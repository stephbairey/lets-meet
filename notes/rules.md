# WordPress Coding Rules for Let's Meet

These rules apply to all code in this plugin. They supplement the patterns in CLAUDE.md with concrete examples.

## File Headers

Every PHP file starts with the direct access guard:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

Main plugin file includes the standard plugin header block with Name, Description, Version, Author, License, Text Domain.

## Naming Conventions

- **Classes:** `Lets_Meet_Services`, `Lets_Meet_Gcal` (prefix `Lets_Meet_`)
- **Functions:** `lm_get_available_slots()`, `lm_log()` (prefix `lm_`)
- **Hooks:** `lm_booking_created`, `lm_available_slots` (prefix `lm_`)
- **Options:** `lm_availability`, `lm_settings` (prefix `lm_`)
- **Transients:** `lm_gcal_busy_{date}`, `lm_rate_{hash}` (prefix `lm_`)
- **AJAX actions:** `lm_get_slots`, `lm_submit_booking` (prefix `lm_`)
- **CSS classes:** `lm-calendar`, `lm-slot-button` (prefix `lm-`)
- **JS global:** `lmData` (from wp_localize_script)

## Security Patterns

### Admin Form Handler

```php
function handle_save_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized' );
    }
    check_admin_referer( 'lm_save_settings', 'lm_nonce' );

    $buffer = absint( $_POST['buffer'] ?? 30 );
    if ( ! in_array( $buffer, [ 15, 30, 45, 60 ], true ) ) {
        $buffer = 30;
    }

    update_option( 'lm_settings', [ 'buffer' => $buffer, /* ... */ ] );
    wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
    exit;
}
```

### AJAX Handler (Public)

```php
function ajax_get_slots() {
    check_ajax_referer( 'lm_frontend_nonce', 'nonce' );

    $date = sanitize_text_field( $_POST['date'] ?? '' );
    $service_id = absint( $_POST['service_id'] ?? 0 );

    // Validate date format
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        wp_send_json_error( 'Invalid date format.' );
    }

    // ... business logic ...

    wp_send_json_success( $slots );
}
```

### Database Query

```php
// ALWAYS use prepare() with variables
$bookings = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}lm_bookings
     WHERE start_utc >= %s AND start_utc < %s AND status = %s",
    $day_start_utc,
    $day_end_utc,
    'confirmed'
) );
```

### Output Escaping

```php
// In admin templates
<input type="text" name="name" value="<?php echo esc_attr( $service->name ); ?>">
<p><?php echo esc_html( $booking->client_name ); ?></p>
<a href="<?php echo esc_url( $edit_url ); ?>">Edit</a>

// For HTML content (emails, descriptions)
<?php echo wp_kses_post( $description ); ?>
```

## Time Handling

```php
// Get "now" in site timezone
$now = current_datetime(); // Returns DateTimeImmutable in wp_timezone()

// Construct a specific time in site timezone
$tz = wp_timezone();
$start = new DateTimeImmutable( '2026-03-16 10:00:00', $tz );

// Convert to UTC for storage
$utc = $start->setTimezone( new DateTimeZone( 'UTC' ) );
$utc_string = $utc->format( 'Y-m-d H:i:s' );

// Format for display (uses site timezone automatically)
$display = wp_date( 'F j, Y g:i A', $start->getTimestamp() );

// Add duration
$end = $start->modify( '+60 minutes' );

// NEVER do this:
// $time = date( 'H:i', strtotime( $time_string ) + 3600 );  // BAD
```

## Asset Enqueueing

```php
// Admin: only on our pages
function enqueue_admin_assets( $hook ) {
    if ( strpos( $hook, 'lets-meet' ) === false ) {
        return;
    }
    wp_enqueue_style( 'lm-admin', LM_URL . 'assets/css/admin.css', [], LM_VERSION );
    wp_enqueue_script( 'lm-admin', LM_URL . 'assets/js/admin.js', [], LM_VERSION, true );
}

// Frontend: only when shortcode is present
function enqueue_public_assets() {
    if ( ! has_shortcode( get_post()->post_content ?? '', 'lets_meet' ) ) {
        return;
    }
    wp_enqueue_style( 'lm-public', LM_URL . 'assets/css/public.css', [], LM_VERSION );
    wp_enqueue_script( 'lm-public', LM_URL . 'assets/js/public.js', [], LM_VERSION, true );
    wp_localize_script( 'lm-public', 'lmData', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'lm_frontend_nonce' ),
    ] );
}
```

## Hook Registration

Register hooks in the loader or main file, not inside class constructors:

```php
// In loader or main plugin file
add_action( 'admin_menu', [ $admin, 'register_menu' ] );
add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_admin_assets' ] );
add_action( 'wp_ajax_lm_get_slots', [ $public, 'ajax_get_slots' ] );
add_action( 'wp_ajax_nopriv_lm_get_slots', [ $public, 'ajax_get_slots' ] );
```

## Options API

```php
// Always provide defaults
$settings = get_option( 'lm_settings', [
    'buffer'         => 30,
    'horizon'        => 60,
    'min_notice'     => 2,
    'admin_email'    => get_option( 'admin_email' ),
    'admin_notify'   => true,
    'confirm_msg'    => '',
    'keep_data'      => true,
] );

// Transients: always handle false (expired/missing)
$busy = get_transient( 'lm_gcal_busy_2026-03-16' );
if ( false === $busy ) {
    // Fetch from API
}
```

## Cron Safety

```php
// Always check before scheduling to prevent duplicates
if ( ! wp_next_scheduled( 'lm_prewarm_gcal' ) ) {
    wp_schedule_event( time(), 'daily', 'lm_prewarm_gcal' );
}

// Always unschedule on deactivation
$timestamp = wp_next_scheduled( 'lm_prewarm_gcal' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'lm_prewarm_gcal' );
}
```

## i18n

All user-facing strings must be translatable:

```php
__( 'Your session is confirmed', 'lets-meet' )
esc_html__( 'Booking Details', 'lets-meet' )
sprintf( __( 'Booked for %s', 'lets-meet' ), esc_html( $date ) )
```

## dbDelta Quirks

- SQL must use `CREATE TABLE` (not `CREATE TABLE IF NOT EXISTS`)
- Each column on its own line
- Two spaces between column name and type
- `KEY` not `INDEX` for secondary indexes
- Primary key on its own line: `PRIMARY KEY  (id)`
- Must have exactly two spaces before `(id)` in PRIMARY KEY line
- Always use `{$wpdb->prefix}` not hardcoded prefix
