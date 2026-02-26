# Let's Meet — WordPress Booking Plugin

## Implementation Plan

**Plugin slug:** `lets-meet`
**Prefix:** `lm_`
**Text domain:** `lets-meet`
**PHP minimum:** 7.4 (WordPress 7.0 drops 7.2/7.3 in April 2026)
**WordPress minimum:** 6.4
**Single site only (v1)**

---

# Part 1: Product Definition

## What It Does

Let's Meet is a lightweight WordPress booking plugin for service providers (coaches, consultants, therapists) who offer 1:1 sessions. The admin defines weekly availability, connects their Google Calendar, and creates service types with configurable durations. Visitors see a clean calendar via shortcode, pick an available slot, enter their details, and book. The plugin checks Google Calendar for conflicts (with configurable buffer time), sends confirmation emails, and syncs the new booking back to Google Calendar.

## Design Decisions (Resolved)

**Buffer time:** 30-minute buffer before AND after every booking and every Google Calendar event. The buffer is applied by the slot engine only — external GCal events are never modified. The GCal event pushed for a Let's Meet booking reflects only the actual session time (e.g., 10:00–11:00), not the buffer.
*Nice-to-have:* Make buffer configurable with options of 15, 30, 45, or 60 minutes.

**Booking horizon:** Visitors can book up to 60 days out.
*Nice-to-have:* Make configurable.

**Minimum notice:** Configurable "minimum hours before booking" setting (default: 2 hours). Prevents same-day chaos. Uses `current_datetime()` for consistent "now" in site timezone.

**Cancellation:** Admin-only for v1. No client self-cancel link. (v2 feature.)

**Timezone:** All times displayed in the WordPress site timezone (`wp_timezone()`). Visitor timezone detection is v2. Admin should be encouraged to use a named timezone string (e.g., `America/Los_Angeles`), not a raw UTC offset.

**Payment:** Not in v1. Deferred to v2 (PayPal/Stripe).

**Vacations / days off:** Not managed in-plugin for v1. Admin blocks time on Google Calendar; FreeBusy picks it up automatically. Design the availability engine to accommodate a future "exceptions" table without rewrite.

**Same-day bookings:** Allowed, subject to the minimum notice setting.

**Graceful degradation:** If Google Calendar is disconnected, tokens are revoked, or the API is unreachable, the plugin continues to function using DB-only booking data for availability. GCal is an enhancement, not a dependency. Admin sees a persistent notice prompting reconnection.

## What v1 Does NOT Do

- Payment processing (v2)
- Client self-service rescheduling or cancellation (v2)
- Visitor timezone detection/conversion (v2)
- Recurring/repeating bookings
- Multiple staff members or calendars (single-provider only)
- Gutenberg block (shortcode only)
- iCal/Outlook sync (Google Calendar only)
- Waitlists
- SMS notifications
- CAPTCHA (honeypot only for v1; CAPTCHA in v2)
- Native vacation/exception UI (rely on GCal blocking for v1)

---

# Part 2: Architecture

## File Structure

```
lets-meet/
├── lets-meet.php                        # Main plugin file: constants, activation/deactivation, loader init
├── uninstall.php                        # Standalone uninstall (no plugin code loaded)
├── includes/
│   ├── class-lets-meet-loader.php       # Hook registration orchestrator
│   ├── class-lets-meet-db.php           # Table creation (dbDelta), schema versioning, direct DB operations
│   ├── class-lets-meet-services.php     # Service CRUD (add, edit, deactivate)
│   ├── class-lets-meet-availability.php # Slot calculation engine (the core algorithm)
│   ├── class-lets-meet-bookings.php     # Booking creation, cancellation, validation, concurrency guard
│   ├── class-lets-meet-gcal.php         # Google Calendar OAuth, FreeBusy, event push, token management
│   ├── class-lets-meet-email.php        # wp_mail() HTML templates for confirmations
│   ├── class-lets-meet-privacy.php      # GDPR: personal data exporter + eraser registration
│   ├── class-lets-meet-admin.php        # All admin pages, settings, handlers
│   └── class-lets-meet-public.php       # Shortcode, AJAX handlers, frontend rendering
├── assets/
│   ├── css/
│   │   ├── admin.css
│   │   └── public.css
│   └── js/
│       ├── admin.js                     # Availability grid UI, "copy right" feature
│       └── public.js                    # Calendar navigation, slot picker, booking form, AJAX
└── templates/
    ├── emails/
    │   ├── confirmation-client.php
    │   └── confirmation-admin.php
    └── frontend/
        ├── calendar-view.php
        ├── slot-picker.php
        └── booking-form.php
```

## WordPress Hooks Used

| Need | Hook | Notes |
|------|------|-------|
| Initialize plugin | `plugins_loaded` | Load classes, set up text domain |
| Register shortcode | `init` | `[lets_meet]` and `[lets_meet service="slug"]` |
| Admin menu | `admin_menu` | Top-level "Let's Meet" with subpages |
| Admin assets | `admin_enqueue_scripts` | With screen check — only on LM admin pages |
| Frontend assets | `wp_enqueue_scripts` | Only when shortcode is present on page |
| AJAX: get slots | `wp_ajax_nopriv_lm_get_slots` + `wp_ajax_lm_get_slots` | Returns available times as JSON |
| AJAX: submit booking | `wp_ajax_nopriv_lm_submit_booking` + `wp_ajax_lm_submit_booking` | Creates booking, emails, GCal push |
| Cron: prewarm cache | Custom hook `lm_prewarm_gcal` | Nightly, next 7 days only. Best-effort, not source of truth |
| Cron schedule | `cron_schedules` filter | Register custom `lm_nightly` interval |
| Activation | `register_activation_hook()` | Create tables via dbDelta, schedule cron |
| Deactivation | `register_deactivation_hook()` | Unschedule cron events |
| Uninstall | `uninstall.php` | Conditionally drop tables and delete options (respects "keep data" toggle) |
| Privacy: export | `wp_privacy_personal_data_exporters` | Register booking data exporter |
| Privacy: erase | `wp_privacy_personal_data_erasers` | Register booking data eraser |
| Settings API | `admin_init` | Register settings, sections, sanitization callbacks |
| Options autoload | `add_option()` | OAuth tokens: autoload OFF. Availability schedule: autoload ON |
| GCal OAuth callback | `admin_post_lm_gcal_callback` | Dedicated OAuth redirect handler |
| Custom actions | `lm_booking_created`, `lm_booking_cancelled` | Extensibility for integrations |
| Custom filters | `lm_available_slots`, `lm_email_*_args`, `lm_gcal_event_data` | Extensibility for customization |

## Data Storage

### Option Keys (wp_options)

| Key | Autoload | Contents |
|-----|----------|----------|
| `lm_availability` | yes | Serialized weekly schedule array |
| `lm_settings` | yes | General settings (buffer minutes, booking horizon, minimum notice, admin email, admin notification toggle, custom confirmation message, keep-data-on-uninstall toggle) |
| `lm_db_version` | yes | Schema version string for migration tracking |
| `lm_gcal_client_id` | no | Google OAuth client ID |
| `lm_gcal_client_secret` | no | Google OAuth client secret (encrypted with `wp_salt()`) |
| `lm_gcal_tokens` | no | Access + refresh tokens (encrypted with `wp_salt()`) |
| `lm_gcal_calendar_id` | no | Which calendar to check. Default: `primary` |

### Custom Tables

#### `{prefix}lm_services`

```sql
CREATE TABLE {prefix}lm_services (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255)    NOT NULL,
    slug        VARCHAR(255)    NOT NULL,
    duration    INT             NOT NULL,          -- minutes (15–240, validated on save)
    description TEXT,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY slug (slug)
) {charset_collate};
```

No delete — deactivate only. Preserves booking history integrity. Duration is a free input validated to 15–240 minutes in 15-minute increments.

#### `{prefix}lm_bookings`

```sql
CREATE TABLE {prefix}lm_bookings (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id      BIGINT UNSIGNED NOT NULL,
    client_name     VARCHAR(255)    NOT NULL,
    client_email    VARCHAR(255)    NOT NULL,
    client_phone    VARCHAR(50)     DEFAULT '',
    client_notes    TEXT,
    start_utc       DATETIME        NOT NULL,      -- UTC datetime of session start
    duration        INT             NOT NULL,       -- minutes
    site_timezone   VARCHAR(100)    NOT NULL,       -- e.g. 'America/Los_Angeles' (for display reconstruction)
    status          VARCHAR(20)     NOT NULL DEFAULT 'confirmed',  -- 'confirmed' or 'cancelled'
    gcal_event_id   VARCHAR(255)    DEFAULT '',     -- for future edit/cancel sync
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_start_status (start_utc, status),
    KEY idx_email (client_email)
) {charset_collate};
```

**Why `start_utc` instead of separate date/time columns:** A single UTC datetime makes timezone conversion trivial in v2, eliminates ambiguity around DST transitions, supports clean range queries with a single index, and simplifies ICS generation. The `site_timezone` column allows reconstructing the local display time.

**Why `VARCHAR(20)` for status instead of ENUM:** Easier to extend in v2 (e.g., `pending`, `rescheduled`) without ALTER TABLE.

**Indexes:** `idx_start_status` supports the most common query pattern (fetching confirmed bookings in a date range for availability checks). `idx_email` supports the GDPR exporter/eraser.

### Schema Versioning

Store a version string in `lm_db_version`. On activation, compare current version to stored version. If different, run `dbDelta()` which handles CREATE-or-ALTER semantics. Bump the version whenever columns or indexes change.

```php
// Pseudocode
define( 'LM_DB_VERSION', '1.0.0' );

function lm_maybe_create_tables() {
    if ( get_option( 'lm_db_version' ) !== LM_DB_VERSION ) {
        // run dbDelta() with current schema
        update_option( 'lm_db_version', LM_DB_VERSION );
    }
}
```

---

# Part 3: The Availability Engine

This is the core of the plugin. Everything else is plumbing.

## Time Model

All internal calculations use `DateTimeImmutable` objects in the site timezone (`wp_timezone()`). Storage is UTC. Display is site timezone.

**Conversion flow:**
1. Admin enters "10:00 AM" for Monday → stored as string `"10:00"` in `lm_availability`
2. At runtime, for a specific Monday (e.g., 2026-03-16), compile to `DateTimeImmutable('2026-03-16 10:00:00', wp_timezone())`
3. For DB storage, convert to UTC
4. For display, convert back to site timezone using `wp_date()`

This approach survives DST transitions because PHP's `DateTimeImmutable` with a named timezone handles the offset automatically.

## Availability Schedule (Admin Input)

Stored in `lm_availability` as a serialized array:

```php
[
    'monday' => [
        ['start' => '10:00', 'end' => '13:00'],
        ['start' => '15:00', 'end' => '17:00'],
    ],
    'tuesday' => [
        ['start' => '10:00', 'end' => '13:00'],
        ['start' => '15:00', 'end' => '17:00'],
        ['start' => '18:00', 'end' => '20:00'],
    ],
    'wednesday' => [],
    // ... etc
]
```

Times are stored as 24-hour strings. Validated on save against allowed values (30-min increments from 00:00 to 23:30). Overlapping windows within a single day are rejected with an admin notice.

Admin UI: 7-column grid (Mon–Sun), up to 3 rows of start/end dropdowns per day. "Copy →" button copies one day's schedule to the next day.

## Slot Calculation Algorithm

This is the function called when a visitor selects a date on the frontend.

```
get_available_slots( $date, $service_id ) → array of start times

Input:
  $date        — 'Y-m-d' string (site timezone)
  $service_id  — int

Steps:

1. DETERMINE DAY OF WEEK
   $day = strtolower( date('l', strtotime($date)) )

2. GET AVAILABILITY WINDOWS
   $windows = lm_availability[$day]
   If empty → return [] (no availability that day)

3. GET SERVICE DURATION
   $duration = service duration in minutes (from DB, validated 15–240)

4. COMPILE WINDOWS INTO DATETIME INTERVALS
   For each window, create concrete DateTimeImmutable start/end for this specific date
   in site timezone

5. COLLECT ALL BUSY INTERVALS (two sources)
   a. Existing confirmed bookings from DB for this date
      → Query: WHERE start_utc BETWEEN $date_start_utc AND $date_end_utc
               AND status = 'confirmed'
      → Convert each to [start, end] interval in site timezone

   b. Google Calendar busy blocks
      → Call FreeBusy API for this date (or use cached transient)
      → FreeBusy returns UTC intervals; convert to site timezone
      → NOTE: FreeBusy ranges are start-inclusive, end-exclusive

6. APPLY BUFFER TO ALL BUSY INTERVALS
   For each busy interval [start, end]:
     expanded = [start - buffer_minutes, end + buffer_minutes]

7. MERGE OVERLAPPING/ADJACENT BUSY INTERVALS
   Sort by start time, then merge any that overlap or are adjacent
   This reduces comparison count in step 9

8. APPLY MINIMUM NOTICE
   $earliest_allowed = current_datetime() + minimum_notice_hours
   If $earliest_allowed is after the start of the day, it becomes an
   additional constraint on candidate slots

9. GENERATE CANDIDATE SLOTS AND FILTER
   For each availability window:
     For start_time from window_start, incrementing by 30 min:
       candidate = [start_time, start_time + duration]
       REJECT if candidate end > window end (doesn't fit in window)
       REJECT if candidate start < $earliest_allowed (minimum notice)
       REJECT if candidate overlaps any merged busy interval
       ACCEPT otherwise → add start_time to results

10. RETURN array of available start times (as 'H:i' strings in site timezone)
```

### Overlap Detection

Two intervals `[a_start, a_end)` and `[b_start, b_end)` overlap if and only if `a_start < b_end AND b_start < a_end`. Using exclusive ends (matching FreeBusy convention) means "ends at 11:00, next starts at 11:00" does NOT overlap — this is correct behavior.

### DST Safety

Two rules prevent DST bugs:

1. Always construct `DateTimeImmutable` with `wp_timezone()` (a named timezone, not an offset). PHP handles the DST math.
2. Never do arithmetic on formatted time strings. Always use `DateTimeImmutable::modify()` or `DateInterval` for additions.

---

# Part 4: Google Calendar Integration

## OAuth Flow

Follow Google's "web server application" pattern:

1. Admin enters Client ID + Client Secret in plugin settings (obtained from Google Cloud Console)
2. Admin clicks "Connect Google Calendar" button
3. Plugin redirects to Google OAuth consent screen with:
   - `response_type=code`
   - `access_type=offline` (to get refresh token)
   - `prompt=consent` (force consent to ensure refresh token is returned)
   - `state=` nonce for CSRF protection
   - `scope=` (see Scopes below)
   - `redirect_uri=` dedicated callback URL (see below)
4. Google redirects back with authorization code
5. Plugin exchanges code for access + refresh tokens
6. Tokens stored in `lm_gcal_tokens`, encrypted with `wp_salt()`

**Redirect URI:** Use `admin_post_lm_gcal_callback` as the dedicated handler. The redirect URI registered in Google Cloud Console should be `{site_url}/wp-admin/admin-post.php?action=lm_gcal_callback`. This avoids conflicts with normal settings page loads and provides a clean callback endpoint. Must be HTTPS if the site uses HTTPS.

**Scopes (minimum required):**
- `https://www.googleapis.com/auth/calendar.freebusy` — read busy times (for FreeBusy queries)
- `https://www.googleapis.com/auth/calendar.events` — create/delete events (for booking sync)

Do NOT request broader scopes like `calendar` (full read/write to all calendar data).

**Token refresh:** Access tokens expire after ~1 hour. Before any API call, check expiry; if expired, use refresh token to get a new access token. If refresh fails (token revoked, salts changed), set a flag that displays an admin notice prompting re-authorization. Never autoload token options.

**Encryption:** Encrypt tokens using `openssl_encrypt()` with a key derived from `wp_salt('auth')`. If decryption fails (corrupted data, rotated salts), catch the error and force re-auth rather than crashing.

## FreeBusy API (Availability Checking)

**Strategy: On-demand with brief caching.** NOT periodic background sync.

When a visitor clicks a date on the frontend calendar:

1. Check transient `lm_gcal_busy_{date}` (5-minute expiry)
2. If cache miss, call Google Calendar FreeBusy API:
   ```
   POST https://www.googleapis.com/calendar/v3/freeBusy
   {
     "timeMin": "2026-03-16T00:00:00-07:00",
     "timeMax": "2026-03-17T00:00:00-07:00",
     "items": [{"id": "primary"}]
   }
   ```
3. Response contains `busy` array of `{start, end}` UTC ranges
4. Cache response in transient
5. Pass busy times to slot engine

**Why not cron-based sync:** WP-Cron is traffic-driven and unreliable on low-traffic sites. A 60-day horizon makes periodic full-range fetches heavy and quota-hungry. On-demand is simpler, more accurate, and kinder to Google's API quotas.

**Optional prewarm (nice-to-have):** A nightly cron job (`lm_prewarm_gcal`) fetches FreeBusy for the next 7 days and populates transients. This is purely an optimization — the on-demand path is always the fallback and source of truth.

**Calendar selection:** v1 uses `primary` calendar only. The settings page stores `lm_gcal_calendar_id` (default: `primary`). FreeBusy supports querying multiple calendar IDs in one call, so expanding to a multi-calendar picker in v2 is straightforward.

## Event Push (Booking → Google Calendar)

When a booking is confirmed:

```
POST https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events
{
  "summary": "Coaching Session — {client_name}",
  "description": "Booked via Let's Meet\nService: {service_name}\nEmail: {client_email}\nPhone: {client_phone}\nNotes: {client_notes}",
  "start": {"dateTime": "2026-03-16T10:00:00-07:00"},
  "end": {"dateTime": "2026-03-16T11:00:00-07:00"},
  "reminders": {"useDefault": true}
}
```

- Do NOT add attendees (avoids Google sending its own email notifications)
- Store the returned `event.id` in `gcal_event_id` column for future cancel sync
- On booking cancellation, delete the GCal event using this ID

**Quota awareness:** Google enforces per-project and per-user rate limits. Bursty writes (multiple visitors booking close together) could hit limits. Implement simple retry-with-backoff (1 retry after 1 second) for GCal API failures — applies to both event push AND FreeBusy calls. If push fails after retry, the booking still succeeds locally — GCal sync failure is logged but non-blocking. Set a transient flag for admin notice.

**Cancellation and 410 handling:** On booking cancellation, delete the GCal event using the stored `gcal_event_id`. If the event was already manually deleted in Google Calendar, the API returns 410 Gone — catch this and treat as success (event is already gone). Log but don't error.

---

# Part 5: Booking Flow & Concurrency

## Frontend UX (3-step, all AJAX)

**Shortcode:** `[lets_meet]` — optionally `[lets_meet service="coaching-session"]` to pre-select a service.

**Step 1: Pick a service**
If more than one active service, show radio buttons with name + duration. If only one, skip this step.

**Step 2: Pick a date & time**
Month-view calendar, navigable forward (up to booking horizon). Past dates greyed out. Days with no availability windows greyed out. Click a date → AJAX fetches available slots → time slots appear below as clickable buttons.

**Step 3: Your details**
- Name (required)
- Email (required)
- Phone (optional)
- Message/notes (optional)
- Honeypot field (hidden, rejects if filled — spam prevention)
- Rendered-at timestamp (hidden, rejects if submitted < 3 seconds after render — bot prevention)
- Submit button

On submit → AJAX POST → server validates, checks availability again, creates booking, sends emails, pushes to GCal → success message with booking details.

## AJAX Endpoints

**`wp_ajax_[nopriv_]lm_get_slots`**
- Input: `date` (Y-m-d), `service_id` (int), nonce
- Validates: nonce, date is within booking horizon, date is not in the past, service exists and is active
- Returns: JSON array of available time strings, or error

**`wp_ajax_[nopriv_]lm_submit_booking`**
- Input: `service_id`, `date`, `time`, `name`, `email`, `phone`, `notes`, `honeypot`, nonce
- Validates: nonce, honeypot is empty, timestamp check (>3s since render), all required fields present, email format, service exists, rate limit check
- **Critical: Re-checks slot availability with FRESH data (not cached)** — this is the concurrency guard
- On success: inserts booking, sends emails, pushes GCal event, returns confirmation
- On failure: returns specific error (slot taken, validation error, etc.)

**AJAX URL:** Passed via `wp_localize_script()`, never hardcoded.

**Nonce note:** For logged-out users, WordPress generates the same nonce for all visitors. This still prevents CSRF from other sites but does not authenticate individual users. The nonce is supplemented by the honeypot field and rate limiting.

## Concurrency Control (Double-Booking Prevention)

Two visitors may see the same slot and submit simultaneously. Under InnoDB's default REPEATABLE READ isolation, two concurrent transactions can both see no existing row and both insert — the NOT EXISTS subquery alone is not sufficient. Defense is three layers:

1. **Fresh availability check on every submit.** The submit handler calls the full slot engine with fresh DB queries and a live FreeBusy call (not cached). This catches most races.

2. **MySQL named lock to serialize booking attempts.** Before inserting, acquire a date-scoped lock:

```php
// Pseudocode
$lock_name = 'lm_book_' . $date_utc;  // e.g., 'lm_book_2026-03-16'
$acquired = $wpdb->get_var( $wpdb->prepare(
    "SELECT GET_LOCK(%s, 10)", $lock_name
) );  // waits up to 10 seconds

if ( ! $acquired ) {
    // Could not acquire lock — server is busy, ask visitor to retry
    return wp_send_json_error( 'Server busy, please try again.' );
}

// ... perform fresh availability check + INSERT here ...

$wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
```

This serializes booking attempts for the same date, eliminating the race window entirely. Low overhead — only blocks concurrent writes to the same date.

3. **Atomic INSERT with overlap guard as final safety net:**

```sql
INSERT INTO {prefix}lm_bookings (service_id, client_name, ..., start_utc, duration, status)
SELECT %d, %s, ..., %s, %d, 'confirmed'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM {prefix}lm_bookings
    WHERE status = 'confirmed'
    AND start_utc < %s  -- candidate end (UTC)
    AND DATE_ADD(start_utc, INTERVAL duration MINUTE) > %s  -- candidate start (UTC)
)
```

If the INSERT affects 0 rows, the slot was taken → return error to visitor. This is the belt to the GET_LOCK suspenders.

## Rate Limiting

Transient-based: `lm_rate_{ip_hash}` with 1-hour expiry, max 10 booking attempts. (Raised from original 5 to accommodate shared IPs in offices/mobile carriers.) Check on submit, increment on attempt (not just success).

---

# Part 6: Email Confirmations

## Approach

Uses `wp_mail()` with PHP template files. Works with any SMTP plugin the site has installed. No dependency on specific email services.

## Templates

Located in `templates/emails/`. Each template receives an `$args` array with booking data and renders HTML.

**Overridable by theme:** If `theme/lets-meet/emails/confirmation-client.php` exists, use it instead of the plugin's template. Check with `locate_template()` pattern.

## Two Emails Per Booking

**To client (`confirmation-client.php`):**
- Subject: "Your session is confirmed — {service_name}"
- Body: date, time (formatted in site timezone), duration, service name, admin's custom confirmation message (from settings)
- Reply-to: admin email from settings

**To admin (`confirmation-admin.php`):**
- Subject: "New booking: {client_name} — {service_name}"
- Body: client name, email, phone, notes, date, time, duration, link to admin bookings page
- Can be toggled off in settings

## Admin Settings for Email

- Reply-to email address
- Custom confirmation message (textarea, shown in client email)
- Admin notification on/off toggle

---

# Part 7: Admin Interface

## Menu Structure

Top-level menu: **Let's Meet** (dashicons-calendar-alt)

| Subpage | Slug | Description |
|---------|------|-------------|
| Bookings | `lets-meet` (default) | List of all bookings |
| Services | `lets-meet-services` | Add/edit/deactivate services |
| Settings | `lets-meet-settings` | Availability, Google Calendar, email, general |

## Bookings Page

`WP_List_Table` showing bookings. (Yes, it's technically "private" API — but it's industry-standard for plugins and has been stable for years. Test against WP betas.)

**Columns:** Date & Time (site TZ), Client Name, Email, Service, Status
**Sortable:** By date (default: upcoming first)
**Filterable:** By status (all / confirmed / cancelled)
**Bulk action:** Cancel selected
**Row action:** View details, Cancel

**Single booking detail view:** Full client info + cancel button with JS confirmation dialog. Cancellation deletes the GCal event and updates status.

## Services Page

Simple admin table (not WP_List_Table — overkill for a handful of services).

**Fields:** Name, slug (auto-generated), duration (number input, 15–240 minutes in 15-min increments), description, active/inactive toggle.

No delete — deactivate only.

## Settings Page

Uses WordPress Settings API for consistent saving, sanitization, and admin notices.

**Tab 1: Availability**
- Weekly schedule grid (7 columns, 3 rows of start/end dropdowns per day)
- "Copy →" button per day (JS)
- Buffer time dropdown (15 / 30 / 45 / 60 minutes, default 30)
- Minimum notice (dropdown: 1h / 2h / 4h / 8h / 24h, default 2h)
- Booking horizon (dropdown: 14 / 30 / 60 / 90 days, default 60)

**Tab 2: Google Calendar**
- Client ID field
- Client Secret field
- "Connect" / "Disconnect" button
- Connection status indicator
- Calendar ID field (default: `primary`)

**Tab 3: Email**
- Reply-to email
- Custom confirmation message (textarea)
- Admin notification toggle

**Tab 4: General**
- "Remove all data on uninstall" toggle (default: OFF)

---

# Part 8: Security

## Non-Negotiable Checklist

Every form submission, AJAX handler, and settings save MUST include:

- **Nonce verification:** `wp_verify_nonce()` on every request
- **Capability check:** `current_user_can('manage_options')` on every admin action
- **Input validation:** For constrained values (service_id exists, duration is 15–240 in 15-min increments, buffer is one of 15/30/45/60), use strict allowlists
- **Input sanitization:** For free-form fields (name, notes, message), use `sanitize_text_field()`, `sanitize_email()`, etc.
- **Output escaping:** `esc_html()`, `esc_attr()`, `esc_url()` at render time. Escape late, not early.
- **Database queries:** `$wpdb->prepare()` for every query with user input. Never raw interpolation.
- **Direct access guard:** `if ( ! defined( 'ABSPATH' ) ) exit;` at top of every PHP file

## AJAX-Specific

- Nonce passed to frontend via `wp_localize_script()`
- Honeypot field for spam prevention (hidden input, reject if filled)
- Timestamp check (reject submissions < 3 seconds after form render)
- Rate limiting via transients (10 attempts per IP per hour)
- All AJAX handlers return `wp_send_json_success()` or `wp_send_json_error()`

## Extensibility Hooks

Even though this is a focused plugin, a handful of action/filter hooks make future customization painless and cost nothing:

| Hook | Type | Fires when |
|------|------|------------|
| `lm_booking_created` | action | After a booking is successfully inserted. Args: `$booking_id`, `$booking_data` |
| `lm_booking_cancelled` | action | After a booking is cancelled. Args: `$booking_id` |
| `lm_available_slots` | filter | After slots are calculated for a date. Args: `$slots`, `$date`, `$service_id` |
| `lm_email_client_args` | filter | Before client confirmation email is sent. Args: `$email_args` |
| `lm_email_admin_args` | filter | Before admin notification email is sent. Args: `$email_args` |
| `lm_gcal_event_data` | filter | Before a GCal event is pushed. Args: `$event_data`, `$booking_id` |

These hooks let you (or a future developer) add custom behavior without modifying plugin code — e.g., sending a Slack notification on booking, adding fields to the GCal event, or filtering out specific time slots.

## Error Logging

Use WordPress's built-in debug log when `WP_DEBUG` and `WP_DEBUG_LOG` are enabled:

```php
// Pseudocode
function lm_log( $message, $data = [] ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[Let\'s Meet] ' . $message . ( $data ? ' ' . wp_json_encode( $data ) : '' ) );
    }
}
```

Log these events:
- GCal API errors (FreeBusy failures, event push failures, token refresh failures)
- Concurrency lock acquisition failures
- Booking insertion failures (0 rows affected)
- OAuth token decryption failures

Do NOT log: client personal data, OAuth tokens, or full API responses (may contain sensitive data).

## OAuth Token Security

- Encrypted at rest using `openssl_encrypt()` with key derived from `wp_salt('auth')`
- NOT autoloaded (only loaded when GCal operations are needed)
- Decryption failure triggers re-auth prompt, not crash
- Tokens never exposed in admin notices, logs, or frontend output

---

# Part 9: Privacy & Compliance

## WordPress Personal Data Tools

Register with WordPress's built-in privacy tools so site admins can handle data requests:

**Exporter:** Hook into `wp_privacy_personal_data_exporters`. When WordPress processes a data export request for an email address, return all bookings for that email (name, email, phone, notes, dates, service).

**Eraser:** Hook into `wp_privacy_personal_data_erasers`. When WordPress processes an erasure request, anonymize or delete bookings for that email. (Anonymize is preferred — replace name/email/phone/notes with "[deleted]" but keep the booking record for the admin's schedule integrity.)

## Google API Compliance

Google's API Services User Data Policy requires:
- Truthful representation of what data is accessed and why
- Minimum necessary scopes
- Secure token handling
- Accurate privacy disclosure

Even for single-client installs, the OAuth consent screen should clearly describe: "This app reads your calendar availability and creates events for confirmed bookings."

---

# Part 10: Operational Considerations

## WP-Cron

WP-Cron is traffic-driven, not time-driven. Events fire when someone visits the site after the scheduled time. On low-traffic sites, cron can be hours late.

**Rule:** Never use WP-Cron for correctness-critical operations. Use it only for best-effort optimizations (cache prewarm, cleanup).

- Availability truth → always computed live (on-demand FreeBusy + fresh DB query)
- Token refresh → happens synchronously when an API call is needed
- Cache prewarm → nightly cron, next 7 days, acceptable if late or missed
- Old booking cleanup → optional daily cron to auto-cancel past bookings with status cleanup

**Custom schedule:** 15-minute intervals are not built-in. If needed, register via `cron_schedules` filter. For v1, nightly (`daily`) is sufficient.

## Uninstall Behavior

Use `uninstall.php` (not a hook) so plugin code doesn't need to load during uninstall.

**Default behavior:** Do NOT drop tables or delete options. Booking data is client history.

**Optional:** If `lm_settings['remove_data_on_uninstall']` is true:
- Drop `lm_services` and `lm_bookings` tables
- Delete all `lm_*` options
- Unschedule all `lm_*` cron events

## PHP Compatibility

WordPress 7.0 (April 2026) drops PHP 7.2/7.3. Minimum: PHP 7.4. Recommended: PHP 8.1+.

If using Google's official PHP client library, check its PHP version requirements before bundling. Consider using direct HTTP calls (`wp_remote_post()`) instead of the full Google SDK to avoid dependency weight.

---

# Part 11: Implementation Todo List

- [ ] **Phase 1: Plugin scaffold**
  - [ ] Main plugin file with headers, constants, activation/deactivation hooks
  - [ ] Loader class for hook registration
  - [ ] DB class with dbDelta table creation and schema versioning
  - [ ] `uninstall.php` with conditional data removal

- [ ] **Phase 2: Admin — Services**
  - [ ] Services CRUD (add, edit, deactivate)
  - [ ] Duration input with 15–240 min validation (15-min increments)
  - [ ] Admin table for services

- [ ] **Phase 3: Admin — Settings**
  - [ ] Settings page with tabs (Availability, Google Calendar, Email, General)
  - [ ] Weekly schedule grid UI with 3-slot-per-day dropdowns
  - [ ] "Copy right" JavaScript behavior
  - [ ] Save/load availability from wp_options
  - [ ] Overlap validation with admin notices
  - [ ] Buffer, minimum notice, and horizon settings
  - [ ] Email settings (reply-to, custom message, admin toggle)
  - [ ] Uninstall data toggle

- [ ] **Phase 4: Availability Engine**
  - [ ] Core algorithm in `class-lets-meet-availability.php`
  - [ ] Window compilation (day-of-week string → concrete DateTimeImmutable intervals)
  - [ ] Busy interval collection (DB bookings + GCal)
  - [ ] Buffer expansion
  - [ ] Interval merging
  - [ ] Minimum notice enforcement
  - [ ] Candidate generation and filtering
  - [ ] Overlap detection helper function

- [ ] **Phase 5: Google Calendar Integration**
  - [ ] OAuth settings UI (Client ID, Client Secret, Connect/Disconnect button)
  - [ ] OAuth redirect via `admin_post_lm_gcal_callback` with state/CSRF protection
  - [ ] Token storage (encrypted) and refresh logic
  - [ ] FreeBusy API integration (on-demand with transient caching + retry-with-backoff)
  - [ ] Event push on booking creation (with retry-with-backoff)
  - [ ] Event delete on booking cancellation (handle 410 Gone gracefully)
  - [ ] Graceful degradation: DB-only availability when GCal is disconnected
  - [ ] Persistent admin notice when tokens are expired/revoked
  - [ ] Error logging via `lm_log()` for all API failures
  - [ ] Optional: nightly prewarm cron for next 7 days

- [ ] **Phase 6: Frontend — Shortcode & Booking UI**
  - [ ] Shortcode registration (`[lets_meet]` with optional `service` attribute)
  - [ ] Frontend asset enqueueing (only on pages with shortcode)
  - [ ] Calendar month view (HTML/CSS/JS) with forward navigation
  - [ ] Day click → AJAX slot fetch → time slot display
  - [ ] Booking form with validation
  - [ ] Honeypot spam field + rendered-at timestamp check
  - [ ] AJAX submission with nonce verification
  - [ ] Success and error state UI
  - [ ] Loading states during AJAX calls

- [ ] **Phase 7: Booking Logic & Concurrency**
  - [ ] Booking creation with fresh availability re-check
  - [ ] MySQL GET_LOCK() per-date serialization
  - [ ] Atomic INSERT with NOT EXISTS overlap guard (safety net)
  - [ ] Rate limiting (transient-based, 10/hour/IP)
  - [ ] Booking cancellation (status update + GCal event deletion with 410 handling)
  - [ ] Fire `lm_booking_created` and `lm_booking_cancelled` action hooks

- [ ] **Phase 8: Email Confirmations**
  - [ ] Client confirmation HTML template
  - [ ] Admin notification HTML template
  - [ ] Template override system (theme folder check)
  - [ ] `wp_mail()` integration with reply-to and custom message

- [ ] **Phase 9: Admin — Bookings Dashboard**
  - [ ] WP_List_Table for bookings list
  - [ ] Sortable by date, filterable by status
  - [ ] Single booking detail view
  - [ ] Cancel booking action (with GCal sync)
  - [ ] Bulk cancel

- [ ] **Phase 10: Privacy**
  - [ ] Personal data exporter registration
  - [ ] Personal data eraser registration

- [ ] **Phase 11: Testing**
  - [ ] Activation creates tables without errors
  - [ ] Deactivation cleans up cron events
  - [ ] Uninstall respects "keep data" toggle
  - [ ] Full booking flow end-to-end (service → date → time → details → confirm)
  - [ ] Slot engine: correct slots returned for various scenarios
  - [ ] Slot engine: buffer applied correctly to both bookings and GCal events
  - [ ] Slot engine: minimum notice enforced
  - [ ] Slot engine: DST transition date produces correct results
  - [ ] Slot engine: service with non-standard duration (e.g., 45 min) generates correct slots
  - [ ] Concurrency: two simultaneous bookings for same slot → one succeeds, one gets error
  - [ ] Concurrency: GET_LOCK acquisition and release verified
  - [ ] Google Calendar: FreeBusy returns correct busy times
  - [ ] Google Calendar: FreeBusy failure → falls back to DB-only availability
  - [ ] Google Calendar: booking pushes event successfully
  - [ ] Google Calendar: cancellation deletes event
  - [ ] Google Calendar: cancellation of already-deleted GCal event handles 410 gracefully
  - [ ] Google Calendar: expired token triggers refresh, then retry
  - [ ] Google Calendar: revoked token shows persistent admin notice
  - [ ] Google Calendar: completely disconnected → plugin still works (DB-only)
  - [ ] Email: client receives confirmation
  - [ ] Email: admin receives notification (when enabled)
  - [ ] Security: AJAX rejects without valid nonce
  - [ ] Security: admin pages reject non-admin users
  - [ ] Security: honeypot field rejects bots
  - [ ] Security: timestamp check rejects instant submissions (<3s)
  - [ ] Security: rate limiting blocks excessive attempts
  - [ ] Security: SQL injection attempt on booking form (try `'; DROP TABLE`)
  - [ ] Security: XSS attempt in name/notes fields
  - [ ] Privacy: data export returns correct bookings for email
  - [ ] Privacy: data erasure anonymizes bookings
  - [ ] Extensibility: `lm_booking_created` action fires with correct args
  - [ ] Extensibility: `lm_available_slots` filter allows modification
  - [ ] Edge case: no availability windows for a day
  - [ ] Edge case: fully booked day
  - [ ] Edge case: booking on first/last day of booking horizon
  - [ ] Edge case: booking with minimum notice exactly at threshold
  - [ ] Edge case: buffer around midnight (booking ends 23:30, buffer extends to 00:00 next day)
  - [ ] Edge case: changing site timezone after bookings exist → UTC storage displays correctly
  - [ ] Edge case: large number of past bookings → admin list page performs well
  - [ ] No PHP warnings or notices in WP_DEBUG mode
  - [ ] Error logging: GCal failures appear in debug.log when WP_DEBUG is on
