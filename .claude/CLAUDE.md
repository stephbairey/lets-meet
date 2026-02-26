# Let's Meet — WordPress Booking Plugin

A lightweight 1:1 booking plugin for service providers. Single-provider, Google Calendar integration, shortcode-based frontend.

## Quick Reference

- **Slug:** `lets-meet` | **Prefix:** `lm_` | **Text domain:** `lets-meet`
- **PHP:** 7.4+ | **WordPress:** 6.4+ | **Single site only**
- **Full plan:** `notes/plan.md` (read the relevant Part before implementing)
- **WP rules:** `notes/rules.md` (security, sanitization, escaping patterns)

## Project Structure

```
lets-meet/
├── lets-meet.php                        # Main file: constants, activation/deactivation
├── uninstall.php                        # Standalone uninstall (no plugin code loaded)
├── includes/
│   ├── class-lets-meet-loader.php       # Hook registration
│   ├── class-lets-meet-db.php           # dbDelta, schema versioning
│   ├── class-lets-meet-services.php     # Service CRUD
│   ├── class-lets-meet-availability.php # Slot engine (THE CORE — read Part 3 of plan)
│   ├── class-lets-meet-bookings.php     # Booking creation, cancellation, GET_LOCK
│   ├── class-lets-meet-gcal.php         # OAuth, FreeBusy, event push/delete
│   ├── class-lets-meet-email.php        # wp_mail() with template system
│   ├── class-lets-meet-privacy.php      # GDPR exporter + eraser
│   ├── class-lets-meet-admin.php        # Admin pages, settings, handlers
│   └── class-lets-meet-public.php       # Shortcode, AJAX, frontend
├── assets/css/ and assets/js/
└── templates/emails/ and templates/frontend/
```

## Database Tables

### {prefix}lm_services
`id` BIGINT PK | `name` VARCHAR(255) | `slug` VARCHAR(255) UNIQUE | `duration` INT (15–240 min) | `description` TEXT | `is_active` TINYINT(1) | `created_at` DATETIME

### {prefix}lm_bookings
`id` BIGINT PK | `service_id` BIGINT | `client_name` VARCHAR(255) | `client_email` VARCHAR(255) | `client_phone` VARCHAR(50) | `client_notes` TEXT | `start_utc` DATETIME | `duration` INT | `site_timezone` VARCHAR(100) | `status` VARCHAR(20) | `gcal_event_id` VARCHAR(255) | `created_at` DATETIME | `updated_at` DATETIME

**Indexes:** `idx_start_status (start_utc, status)` | `idx_email (client_email)`

## Critical Patterns — Follow These Every Time

### Always
- Use `$wpdb->prefix` — never hardcode `wp_`
- Use `$wpdb->prepare()` for every query with variables
- Check `current_user_can('manage_options')` on every admin handler
- Verify nonce on every form/AJAX handler: `wp_verify_nonce()`
- Sanitize input: `sanitize_text_field()`, `sanitize_email()`, `absint()`
- Escape output: `esc_html()`, `esc_attr()`, `esc_url()` — escape late, not early
- Guard every PHP file: `if ( ! defined( 'ABSPATH' ) ) exit;`
- Prefix all hooks, transients, and option keys with `lm_`
- Use `DateTimeImmutable` with `wp_timezone()` for all time calculations
- Use `wp_date()` for display formatting (respects site timezone)

### Never
- Never use raw `date()` or `time()` — use `current_datetime()` or `wp_date()`
- Never do math on formatted time strings — use DateTimeImmutable::modify()
- Never autoload OAuth tokens — they're large and rarely needed
- Never expose tokens in admin notices, logs, or frontend
- Never hardcode timezone offsets — always use named timezones

### Concurrency (Booking Creation)
The booking flow uses three layers of protection — see Part 5 of plan:
1. Fresh availability re-check (live FreeBusy + fresh DB query)
2. MySQL `GET_LOCK('lm_book_{date}', 10)` to serialize per-date
3. Atomic INSERT with NOT EXISTS overlap subquery as safety net
Always release the lock with `RELEASE_LOCK()` in a finally block.

### Google Calendar
- Direct `wp_remote_post()` / `wp_remote_get()` — no Google SDK
- Tokens encrypted with `openssl_encrypt()` using `wp_salt('auth')`
- OAuth callback: `admin_post_lm_gcal_callback` handler
- Retry-with-backoff (1 retry after 1s) on all API calls
- Handle 410 Gone on event deletion gracefully
- If GCal is disconnected → fall back to DB-only availability

## Constants (defined in lets-meet.php)

```php
define( 'LM_VERSION', '1.0.0' );
define( 'LM_DB_VERSION', '1.0.0' );
define( 'LM_PATH', plugin_dir_path( __FILE__ ) );
define( 'LM_URL', plugin_dir_url( __FILE__ ) );
define( 'LM_BASENAME', plugin_basename( __FILE__ ) );
```

## Option Keys

| Key | Autoload | Purpose |
|-----|----------|---------|
| `lm_availability` | yes | Weekly schedule array |
| `lm_settings` | yes | Buffer, horizon, min notice, email settings, keep-data toggle |
| `lm_db_version` | yes | Schema version for migrations |
| `lm_gcal_client_id` | no | OAuth client ID |
| `lm_gcal_client_secret` | no | OAuth client secret (encrypted) |
| `lm_gcal_tokens` | no | Access + refresh tokens (encrypted) |
| `lm_gcal_calendar_id` | no | Calendar to check (default: `primary`) |

## Extensibility Hooks

| Hook | Type | When |
|------|------|------|
| `lm_booking_created` | action | After booking inserted. Args: `$booking_id`, `$booking_data` |
| `lm_booking_cancelled` | action | After booking cancelled. Args: `$booking_id` |
| `lm_available_slots` | filter | After slots calculated. Args: `$slots`, `$date`, `$service_id` |
| `lm_email_client_args` | filter | Before client email sent. Args: `$email_args` |
| `lm_email_admin_args` | filter | Before admin email sent. Args: `$email_args` |
| `lm_gcal_event_data` | filter | Before GCal event pushed. Args: `$event_data`, `$booking_id` |

## Error Logging

```php
function lm_log( $message, $data = [] ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[Let\'s Meet] ' . $message . ( $data ? ' ' . wp_json_encode( $data ) : '' ) );
    }
}
```

Log: GCal API errors, lock failures, 0-row inserts, token refresh failures.
Do NOT log: client PII, tokens, full API responses.

## Implementation Phases

Work one phase at a time. Read the relevant Part of `notes/plan.md` before starting each phase. `/clear` between phases.

1. Plugin scaffold (activation, DB tables, uninstall)
2. Admin — Services (CRUD, duration 15–240 min)
3. Admin — Settings (availability grid, buffer/horizon/notice, email, general)
4. Availability Engine (slot calculation algorithm — Part 3)
5. Google Calendar (OAuth, FreeBusy, event push/delete — Part 4)
6. Frontend — Shortcode & Booking UI (calendar, AJAX, form)
7. Booking Logic & Concurrency (GET_LOCK, atomic insert — Part 5)
8. Email Confirmations (templates, override system)
9. Admin — Bookings Dashboard (WP_List_Table, cancel, bulk)
10. Privacy (GDPR exporter + eraser)
11. Testing (see full checklist in Part 11 of plan)

## Compaction Instructions

When compacting, always preserve: current phase number, list of files modified this session, any failing tests or unresolved issues, and the specific Part of plan.md being implemented.
