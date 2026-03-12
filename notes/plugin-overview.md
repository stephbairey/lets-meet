# Let's Meet — Plugin Overview for Development Context

This document describes the current state of the Let's Meet WordPress plugin (v1.1.0) so that an AI assistant can understand the full system before planning v2 features like payment integration.

## What It Is

A lightweight 1-on-1 booking plugin for WordPress. A single service provider (e.g., a consultant, coach, therapist) defines their services and weekly availability. Clients visit a page on the site, pick a service, choose an open time slot, and book — with optional Google Calendar sync. The plugin is shortcode-based (`[lets_meet]`), has no dependencies on external PHP libraries, and targets PHP 7.4+ / WordPress 6.4+.

## Core Concepts

### Services
Services are what clients book (e.g., "30-Minute Consultation", "Initial Assessment"). Each service has a name, slug, duration (5-minute increments from 15 to 240 minutes), optional HTML description, and an active/inactive toggle. Services are never deleted — they are deactivated to preserve booking history. Stored in `{prefix}lm_services`.

### Availability
The admin sets a weekly schedule with up to 3 time windows per day (e.g., Monday 9:00–12:00, 1:00–5:00). This is stored as a site option (`lm_availability`). Three booking rules control client access:
- **Buffer time** (15–60 min): padding before and after each booking
- **Minimum notice** (1–24 hours): how far ahead clients must book
- **Booking horizon** (14–90 days): how far into the future clients can see

### Slot Calculation Engine (`class-lets-meet-availability.php`)
This is the core algorithm. For a given date and service, it:
1. Gets the weekly availability windows for that day of the week
2. Compiles them into DateTimeImmutable intervals
3. Collects busy intervals from DB bookings + Google Calendar FreeBusy API
4. Applies buffer padding to all busy intervals
5. Merges overlapping busy intervals
6. Applies minimum notice cutoff
7. Generates candidate slots at 30-minute increments
8. Filters out candidates that overlap busy intervals
9. Returns an array of available `H:i` time strings

The engine accepts an optional `$exclude_booking_id` parameter — used by the reschedule flow so the current booking's time slot shows as available.

### Bookings
Stored in `{prefix}lm_bookings`. Key fields: `service_id`, `client_name`, `client_email`, `client_phone`, `client_notes`, `start_utc` (UTC datetime), `duration` (minutes), `site_timezone`, `status` (confirmed/cancelled), `gcal_event_id`, `cancel_token` (64-char hex for client self-service links).

**Three-layer double-booking prevention:**
1. Fresh availability re-check (bypasses GCal cache)
2. MySQL `GET_LOCK('lm_book_{date}', 10)` serialization per date
3. Atomic INSERT with `NOT EXISTS` overlap subquery

Rate limiting: 10 booking attempts per hour per IP.

Bot protection: honeypot field + rendered-at timestamp check (rejects submissions < 3 seconds after render).

### Booking Lifecycle
1. **Created** → status = `confirmed`, GCal event pushed, confirmation emails sent to client and admin
2. **Cancelled** → status = `cancelled`, GCal event deleted, cancellation email sent to client
3. **Rescheduled** → same booking row updated with new `start_utc`, GCal event patched, reschedule emails sent

### Cancel & Reschedule (v1.1.0)
Each booking gets a cryptographically secure token (`bin2hex(random_bytes(32))`). The confirmation email includes two buttons:
- **Cancel Booking** → `?lm_action=cancel&lm_token={token}` — shows booking summary, client confirms, booking is cancelled
- **Reschedule Booking** → `?lm_action=reschedule&lm_token={token}` — shows current booking + full calendar/time picker, client picks new time, booking is updated atomically with the same concurrency protection as creation

The admin can also reschedule from the booking detail page in wp-admin (date picker + AJAX time slot dropdown).

Links stop working after cancellation. Rescheduled bookings keep the same token, so links in subsequent emails continue to work.

### Google Calendar Integration
Optional. Uses direct `wp_remote_post()`/`wp_remote_get()` — no Google SDK. OAuth 2.0 flow with encrypted token storage (AES-256-CBC keyed from `wp_salt('auth')`).

When connected:
- FreeBusy API checks admin's calendar for conflicts during slot calculation (5-min transient cache, fresh call on booking submission)
- Events are created on booking, patched on reschedule, deleted on cancel
- Graceful degradation: if disconnected or API fails, falls back to DB-only

### Email System
HTML emails via `wp_mail()`. Templates in `templates/emails/` with theme override support (`theme/lets-meet/emails/{template}.php`).

Current templates:
- `confirmation-client.php` — booking confirmed, includes cancel/reschedule buttons
- `confirmation-admin.php` — admin notification of new booking
- `cancellation-client.php` — booking cancelled confirmation
- `reschedule-client.php` — booking rescheduled, includes fresh cancel/reschedule buttons
- `reschedule-admin.php` — admin notification of reschedule

Settings: reply-to email, custom confirmation message (HTML allowed), admin notification toggle.

Filters: `lm_email_client_args`, `lm_email_admin_args` for modifying emails before sending.

### Admin Interface
- **Bookings** — WP_List_Table with status filters (all/confirmed/cancelled), sortable by date, detail view, cancel and reschedule actions, bulk cancel
- **Services** — CRUD with activate/deactivate
- **Settings** — 4 tabs: Availability, Google Calendar, Email, General

### Frontend
The `[lets_meet]` shortcode renders a 4-step booking widget:
1. Service selection (radio buttons, auto-skipped if single service or pre-selected via `service="slug"` attribute)
2. Date & time selection (interactive calendar + AJAX slot fetching)
3. Client details form (name, email, phone, notes)
4. Success confirmation

All frontend JS is vanilla (no jQuery, no frameworks). CSS is self-contained.

### Privacy / GDPR
Integrates with WordPress privacy tools:
- **Export**: includes all bookings for a given email
- **Erasure**: anonymizes PII fields to `[deleted]`, preserves booking rows

### Extensibility Hooks
| Hook | Type | Args |
|------|------|------|
| `lm_booking_created` | action | `$booking_id`, `$booking_data` |
| `lm_booking_cancelled` | action | `$booking_id` |
| `lm_booking_rescheduled` | action | `$booking_id`, `$booking_data` |
| `lm_available_slots` | filter | `$slots`, `$date`, `$service_id` |
| `lm_email_client_args` | filter | `$email_args` |
| `lm_email_admin_args` | filter | `$email_args` |
| `lm_gcal_event_data` | filter | `$event_data`, `$booking_id` |

## Database Schema

### `{prefix}lm_services`
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | Auto-increment |
| name | VARCHAR(255) | Required |
| slug | VARCHAR(255) UNIQUE | Auto-generated |
| duration | INT | 15–240 minutes, 5-min increments |
| description | TEXT | Optional, allows safe HTML |
| is_active | TINYINT(1) | Default 1 |
| created_at | DATETIME | |

### `{prefix}lm_bookings`
| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED PK | Auto-increment |
| service_id | BIGINT UNSIGNED | FK to services |
| client_name | VARCHAR(255) | |
| client_email | VARCHAR(255) | |
| client_phone | VARCHAR(50) | Optional |
| client_notes | TEXT | Optional |
| start_utc | DATETIME | UTC |
| duration | INT | Minutes |
| site_timezone | VARCHAR(100) | For display |
| status | VARCHAR(20) | confirmed / cancelled |
| gcal_event_id | VARCHAR(255) | Nullable |
| cancel_token | VARCHAR(64) | Secure hex token |
| created_at | DATETIME | |
| updated_at | DATETIME | Auto-update |

Indexes: `idx_start_status`, `idx_email`, `idx_cancel_token`

## Options
| Key | Autoload | Content |
|-----|----------|---------|
| `lm_availability` | yes | Weekly schedule array |
| `lm_settings` | yes | buffer, horizon, min_notice, admin_email, admin_notify, confirm_msg, keep_data |
| `lm_db_version` | yes | Schema version (currently 1.1.0) |
| `lm_gcal_client_id` | no | OAuth client ID |
| `lm_gcal_client_secret` | no | Encrypted |
| `lm_gcal_tokens` | no | Encrypted access + refresh tokens |
| `lm_gcal_calendar_id` | no | Calendar to check |

## File Structure
```
lets-meet/
├── lets-meet.php                          # Main file, constants, activation/deactivation
├── uninstall.php                          # Standalone uninstall
├── includes/
│   ├── class-lets-meet-loader.php         # Hook wiring
│   ├── class-lets-meet-db.php             # dbDelta schema
│   ├── class-lets-meet-services.php       # Service CRUD
│   ├── class-lets-meet-availability.php   # Slot engine
│   ├── class-lets-meet-bookings.php       # Create, cancel, reschedule + concurrency
│   ├── class-lets-meet-gcal.php           # OAuth, FreeBusy, event push/patch/delete
│   ├── class-lets-meet-email.php          # wp_mail with template system
│   ├── class-lets-meet-privacy.php        # GDPR exporter + eraser
│   ├── class-lets-meet-admin.php          # Admin pages, settings, handlers
│   ├── class-lets-meet-public.php         # Shortcode, AJAX, client cancel/reschedule
│   └── class-lets-meet-bookings-table.php # WP_List_Table for admin
├── assets/
│   ├── css/admin.css
│   ├── css/public.css
│   ├── js/admin.js
│   └── js/public.js                       # Booking widget + reschedule widget
├── templates/
│   ├── emails/
│   │   ├── confirmation-client.php
│   │   ├── confirmation-admin.php
│   │   ├── cancellation-client.php
│   │   ├── reschedule-client.php
│   │   └── reschedule-admin.php
│   └── frontend/
│       ├── calendar-view.php              # Main booking widget
│       ├── cancel-page.php                # Client cancel page
│       └── reschedule-page.php            # Client reschedule page
├── docs/
│   └── user-guide.md
└── notes/
    └── (dev notes, not in plugin zip)
```

## What Doesn't Exist Yet (Relevant to v2 Planning)

- **No payment processing** — bookings are free; no Stripe, PayPal, or any payment gateway
- **No booking confirmation workflow** — bookings go straight to "confirmed", there is no "pending" or "pending payment" status
- **No pricing on services** — the services table has no price column
- **No client accounts** — clients are identified by email only, no login/registration
- **No multi-provider support** — single provider only
- **No recurring bookings** — one-off only
- **No waitlist** — if a slot is taken, client picks another
- **No SMS notifications** — email only
- **No webhook/API endpoints** — WordPress hooks only, no REST API
- **No i18n .pot file** — strings are wrapped in `__()` / `esc_html__()` but no translation file generated yet

## Coding Conventions

- Prefix: `lm_` for all hooks, options, transients, DB lock names
- Text domain: `lets-meet`
- All time math uses `DateTimeImmutable` with `wp_timezone()` / UTC
- Display formatting uses `wp_date()` (respects site timezone)
- All DB queries use `$wpdb->prepare()`
- All admin handlers check `current_user_can('manage_options')` + nonce
- All output is escaped (`esc_html()`, `esc_attr()`, `esc_url()`)
- Error logging via `lm_log()` — only when `WP_DEBUG` is on, never logs PII or tokens
