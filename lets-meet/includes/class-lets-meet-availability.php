<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slot calculation engine — the core of the plugin.
 *
 * Computes available booking slots for a given date and service by:
 * 1. Loading the admin's weekly availability windows
 * 2. Compiling them into concrete DateTimeImmutable intervals
 * 3. Collecting busy intervals from DB bookings (+ GCal in Phase 5)
 * 4. Expanding busy intervals by the buffer
 * 5. Merging overlapping busy intervals
 * 6. Generating candidate slots and filtering out conflicts
 *
 * All internal math uses DateTimeImmutable in wp_timezone().
 * Overlap detection uses half-open intervals [start, end).
 */
class Lets_Meet_Availability {

	/** @var Lets_Meet_Services */
	private $services;

	/** @var Lets_Meet_Gcal */
	private $gcal;

	public function __construct( Lets_Meet_Services $services, Lets_Meet_Gcal $gcal ) {
		$this->services = $services;
		$this->gcal     = $gcal;
	}

	/**
	 * Get available booking slots for a date and service.
	 *
	 * @param string $date       'Y-m-d' string in site timezone.
	 * @param int    $service_id Service ID.
	 * @return array Array of available start times as 'H:i' strings in site timezone.
	 */
	public function get_available_slots( $date, $service_id ) {
		$tz         = wp_timezone();
		$settings   = get_option( 'lm_settings', [] );
		$service_id = absint( $service_id );

		// ── Validate date format ─────────────────────────────────────
		$date_check = DateTimeImmutable::createFromFormat( 'Y-m-d', $date, $tz );
		if ( ! $date_check || $date_check->format( 'Y-m-d' ) !== $date ) {
			return [];
		}

		// ── Reject past dates ────────────────────────────────────────
		$today = current_datetime()->format( 'Y-m-d' );
		if ( $date < $today ) {
			return $this->apply_filter( [], $date, $service_id );
		}

		// ── Enforce booking horizon ──────────────────────────────────
		$horizon  = absint( $settings['horizon'] ?? 60 );
		$max_date = current_datetime()->modify( "+{$horizon} days" )->format( 'Y-m-d' );
		if ( $date > $max_date ) {
			return $this->apply_filter( [], $date, $service_id );
		}

		// ── Step 1: Determine day of week ────────────────────────────
		$date_obj = new DateTimeImmutable( $date, $tz );
		$day      = strtolower( $date_obj->format( 'l' ) );

		// ── Step 2: Get availability windows ─────────────────────────
		$availability = get_option( 'lm_availability', [] );
		$windows_raw  = $availability[ $day ] ?? [];

		if ( empty( $windows_raw ) ) {
			return $this->apply_filter( [], $date, $service_id );
		}

		// ── Step 3: Get service duration ─────────────────────────────
		$service = $this->services->get( $service_id );
		if ( ! $service || ! $service->is_active ) {
			return [];
		}
		$duration = absint( $service->duration );

		// ── Step 4: Compile windows into DateTimeImmutable intervals ─
		$windows = $this->compile_windows( $date, $windows_raw, $tz );

		// ── Step 5: Collect all busy intervals ───────────────────────
		$day_start = new DateTimeImmutable( $date . ' 00:00:00', $tz );
		$day_end   = $day_start->modify( '+1 day' );

		$day_start_utc = $day_start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$day_end_utc   = $day_end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		// 5a. DB bookings.
		$busy = $this->get_busy_from_db( $day_start_utc, $day_end_utc, $tz );

		// 5b. Google Calendar busy times.
		$busy = array_merge( $busy, $this->gcal->get_busy( $date, $tz ) );

		// ── Step 6: Apply buffer to all busy intervals ───────────────
		$buffer = absint( $settings['buffer'] ?? 30 );
		$busy   = $this->apply_buffer( $busy, $buffer );

		// ── Step 7: Merge overlapping/adjacent busy intervals ────────
		$busy = $this->merge_intervals( $busy );

		// ── Step 8: Apply minimum notice ─────────────────────────────
		$min_notice_hours = absint( $settings['min_notice'] ?? 2 );
		$earliest         = current_datetime()->modify( "+{$min_notice_hours} hours" );

		// ── Step 9: Generate candidate slots and filter ──────────────
		$slots = $this->generate_slots( $windows, $duration, $busy, $earliest );

		// ── Step 10: Return ──────────────────────────────────────────
		return $this->apply_filter( $slots, $date, $service_id );
	}

	/**
	 * Compile raw availability windows into DateTimeImmutable intervals.
	 *
	 * @param string       $date       'Y-m-d' date string.
	 * @param array        $windows    Raw windows [['start' => 'HH:MM', 'end' => 'HH:MM'], ...].
	 * @param DateTimeZone $tz         Site timezone.
	 * @return array Array of ['start' => DateTimeImmutable, 'end' => DateTimeImmutable].
	 */
	private function compile_windows( $date, $windows, $tz ) {
		$compiled = [];
		foreach ( $windows as $w ) {
			$start = $w['start'] ?? '';
			$end   = $w['end'] ?? '';

			// Skip windows with invalid time format.
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $start ) || ! preg_match( '/^\d{2}:\d{2}$/', $end ) ) {
				continue;
			}

			$compiled[] = [
				'start' => new DateTimeImmutable( $date . ' ' . $start . ':00', $tz ),
				'end'   => new DateTimeImmutable( $date . ' ' . $end . ':00', $tz ),
			];
		}
		return $compiled;
	}

	/**
	 * Get busy intervals from confirmed bookings in the database.
	 *
	 * @param string       $day_start_utc UTC start of the day.
	 * @param string       $day_end_utc   UTC end of the day.
	 * @param DateTimeZone $tz            Site timezone.
	 * @return array Array of ['start' => DateTimeImmutable, 'end' => DateTimeImmutable].
	 */
	private function get_busy_from_db( $day_start_utc, $day_end_utc, $tz ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lm_bookings';

		$bookings = $wpdb->get_results( $wpdb->prepare(
			"SELECT start_utc, duration FROM {$table}
			 WHERE start_utc >= %s AND start_utc < %s AND status = %s",
			$day_start_utc,
			$day_end_utc,
			'confirmed'
		) );

		$busy = [];
		$utc  = new DateTimeZone( 'UTC' );

		foreach ( $bookings as $b ) {
			$start  = new DateTimeImmutable( $b->start_utc, $utc );
			$end    = $start->modify( '+' . absint( $b->duration ) . ' minutes' );
			$busy[] = [
				'start' => $start->setTimezone( $tz ),
				'end'   => $end->setTimezone( $tz ),
			];
		}

		return $busy;
	}

	/**
	 * Expand each busy interval by the buffer (before and after).
	 *
	 * @param array $intervals Array of ['start' => DateTimeImmutable, 'end' => DateTimeImmutable].
	 * @param int   $buffer    Buffer in minutes.
	 * @return array
	 */
	private function apply_buffer( $intervals, $buffer ) {
		if ( 0 === $buffer ) {
			return $intervals;
		}

		$expanded = [];
		foreach ( $intervals as $i ) {
			$expanded[] = [
				'start' => $i['start']->modify( "-{$buffer} minutes" ),
				'end'   => $i['end']->modify( "+{$buffer} minutes" ),
			];
		}
		return $expanded;
	}

	/**
	 * Sort intervals by start time and merge any that overlap or are adjacent.
	 *
	 * @param array $intervals Array of ['start' => DateTimeImmutable, 'end' => DateTimeImmutable].
	 * @return array Merged intervals.
	 */
	private function merge_intervals( $intervals ) {
		if ( count( $intervals ) <= 1 ) {
			return $intervals;
		}

		// Sort by start time.
		usort( $intervals, function ( $a, $b ) {
			return $a['start'] <=> $b['start'];
		} );

		$merged = [ $intervals[0] ];

		for ( $i = 1; $i < count( $intervals ); $i++ ) {
			$last    = &$merged[ count( $merged ) - 1 ];
			$current = $intervals[ $i ];

			// Overlap or adjacent: current starts at or before last ends.
			if ( $current['start'] <= $last['end'] ) {
				// Extend the end if current goes further.
				if ( $current['end'] > $last['end'] ) {
					$last['end'] = $current['end'];
				}
			} else {
				$merged[] = $current;
			}
		}

		return $merged;
	}

	/**
	 * Generate candidate slots and filter out conflicts.
	 *
	 * Candidates are generated at 30-minute increments within each window.
	 *
	 * @param array              $windows   Compiled availability windows.
	 * @param int                $duration  Service duration in minutes.
	 * @param array              $busy      Merged busy intervals (with buffer).
	 * @param DateTimeImmutable  $earliest  Earliest allowed start (minimum notice).
	 * @return array Array of 'H:i' time strings.
	 */
	private function generate_slots( $windows, $duration, $busy, $earliest ) {
		$slots = [];

		foreach ( $windows as $window ) {
			$candidate = $window['start'];

			while ( true ) {
				$candidate_end = $candidate->modify( "+{$duration} minutes" );

				// Does the candidate fit within the window?
				if ( $candidate_end > $window['end'] ) {
					break;
				}

				// Is the candidate after the minimum notice cutoff?
				if ( $candidate < $earliest ) {
					$candidate = $candidate->modify( '+30 minutes' );
					continue;
				}

				// Does the candidate overlap any busy interval?
				if ( $this->overlaps_any( $candidate, $candidate_end, $busy ) ) {
					$candidate = $candidate->modify( '+30 minutes' );
					continue;
				}

				// Candidate is available.
				$slots[] = $candidate->format( 'H:i' );

				$candidate = $candidate->modify( '+30 minutes' );
			}
		}

		return $slots;
	}

	/**
	 * Check if a candidate interval overlaps any busy interval.
	 *
	 * Uses half-open interval comparison: [a_start, a_end) overlaps [b_start, b_end)
	 * if and only if a_start < b_end AND b_start < a_end.
	 *
	 * @param DateTimeImmutable $start Candidate start.
	 * @param DateTimeImmutable $end   Candidate end.
	 * @param array             $busy  Merged busy intervals.
	 * @return bool
	 */
	private function overlaps_any( $start, $end, $busy ) {
		foreach ( $busy as $b ) {
			if ( $start < $b['end'] && $b['start'] < $end ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Apply the lm_available_slots filter.
	 *
	 * @param array  $slots      Array of 'H:i' strings.
	 * @param string $date       'Y-m-d' date string.
	 * @param int    $service_id Service ID.
	 * @return array Filtered slots.
	 */
	private function apply_filter( $slots, $date, $service_id ) {
		/**
		 * Filter the available slots after calculation.
		 *
		 * @param array  $slots      Available start times as 'H:i' strings.
		 * @param string $date       The date in 'Y-m-d' format (site timezone).
		 * @param int    $service_id The service ID.
		 */
		return apply_filters( 'lm_available_slots', $slots, $date, $service_id );
	}
}
