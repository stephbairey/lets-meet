# Let's Meet — PayPal Payment Integration Plan
## v1.2.0

**Builds on:** v1.1.0 (cancel/reschedule complete)  
**Approach:** PayPal Standard IPN (Instant Payment Notification)  
**PayPal account:** devonervin2010@gmail.com (HOPE FOR YOUR HEART COACHING)  
**Goal:** Paid services redirect client to PayPal after booking. PayPal notifies the site when payment completes. Booking record is updated automatically with amount paid, transaction ID, and payment date. Admin sees payment status at a glance. No manual invoice steps for Devon.

---

## What This Does (Plain English)

Each service can have a price (0.00 = free, any positive amount = paid). Free services work exactly as they do today — book, confirm, done. Paid services work like this:

1. Client completes the booking form as normal
2. Booking is created in the DB with status `pending_payment` instead of `confirmed`
3. Client is redirected to PayPal with the amount and booking ID pre-filled
4. Client pays on PayPal
5. PayPal sends an IPN (server-to-server notification) to the site
6. Plugin verifies the IPN, updates the booking to `confirmed`, stores amount paid / transaction ID / payment date
7. Confirmation emails are sent to client and admin (same as today, but triggered by IPN, not booking creation)
8. Client sees a "Thank you, payment received" page

If a client books but never pays, the booking stays as `pending_payment`. Admin can manually mark it Paid, Waived, or cancel it.

---

## What This Does NOT Do

- No Stripe, no other gateways — PayPal only
- No client self-service payment retry (admin handles edge cases manually)
- No refund processing through the plugin
- No partial payments or installment plans
- No package/bundle purchasing (that's a future version)
- No automatic cancellation of unpaid bookings after X days (admin does this manually)
- No sandbox/live toggle in settings UI (use WP_DEBUG for sandbox — see note below)

---

## Database Schema Changes

### `{prefix}lm_services` — add one column
```sql
price DECIMAL(10,2) NOT NULL DEFAULT '0.00'
```
- 0.00 = free (existing behavior)
- Any positive value = paid, triggers PayPal flow

### `{prefix}lm_bookings` — add four columns
```sql
payment_status  VARCHAR(20)   NOT NULL DEFAULT 'none'
payment_amount  DECIMAL(10,2) NULL
payment_txn_id  VARCHAR(255)  NULL
payment_date    DATETIME      NULL
```

**payment_status values:**
- `none` — free service, no payment required (default for $0 services)
- `pending` — paid service, awaiting PayPal confirmation
- `paid` — IPN confirmed, amount/txn_id/date stored
- `waived` — admin manually overrode (no payment taken)

**DB version bump:** `1.2.0` — `class-lets-meet-db.php` runs `dbDelta()` on activation/upgrade.

---

## New Settings

Add to **General** settings tab (no new tab needed — keep UI lean).

| Setting | Key | Type | Default |
|---------|-----|------|---------|
| PayPal email | `lm_paypal_email` | text | '' |
| PayPal sandbox mode | `lm_paypal_sandbox` | checkbox | false |

Sandbox note: When `lm_paypal_sandbox` is true, the plugin posts to `https://www.sandbox.paypal.com/cgi-bin/webscr` and the IPN listener verifies against `https://ipnpb.sandbox.paypal.com/cgi-bin/webscr`. When false, uses live PayPal URLs.

---

## New File

### `includes/class-lets-meet-paypal.php`

Owns all PayPal logic. Public methods:

```php
// Build the PayPal redirect URL for a booking
public function get_redirect_url( int $booking_id ): string

// Register the IPN listener endpoint (called from loader)
public function register_ipn_endpoint(): void

// Handle incoming IPN POST (verify + update booking)
public function handle_ipn(): void

// Verify IPN with PayPal's servers (returns true/false)
private function verify_ipn( array $post_data ): bool

// Update booking after verified payment
private function confirm_payment( int $booking_id, float $amount, string $txn_id ): void
```

---

## Booking Flow Changes

### `class-lets-meet-bookings.php`

**`create_booking()` changes:**

```php
// Determine initial status based on service price
$service = $this->services->get( $booking_data['service_id'] );
$is_paid = ( (float) $service->price > 0.00 );

$status         = $is_paid ? 'pending_payment' : 'confirmed';
$payment_status = $is_paid ? 'pending' : 'none';
```

- For free services: behavior unchanged (confirmed immediately, emails sent, GCal event pushed)
- For paid services: 
  - Booking inserted with `status = pending_payment`, `payment_status = pending`
  - GCal event is NOT pushed yet (pushed after payment confirmed)
  - Emails are NOT sent yet (sent after payment confirmed)
  - Return value includes `paypal_redirect_url` so the public handler can redirect

### `class-lets-meet-public.php`

**AJAX booking handler changes:**

```php
// After successful create_booking():
if ( isset( $result['paypal_redirect_url'] ) ) {
    wp_send_json_success([
        'redirect' => $result['paypal_redirect_url']
    ]);
} else {
    // existing success response
}
```

Frontend JS checks for `redirect` in the success response and does `window.location.href = response.data.redirect` instead of showing step 4.

---

## IPN Listener

### Endpoint Registration

```php
// In class-lets-meet-paypal.php, hooked to init
add_rewrite_rule(
    '^lets-meet-ipn/?$',
    'index.php?lm_ipn=1',
    'top'
);
add_filter( 'query_vars', fn($vars) => array_merge($vars, ['lm_ipn']) );
add_action( 'template_redirect', [ $this, 'maybe_handle_ipn' ] );
```

IPN URL format: `https://example.com/lets-meet-ipn/`  
This is passed to PayPal as `notify_url` in the redirect form.

### IPN Verification Flow

```
1. Receive POST from PayPal
2. Respond with HTTP 200 immediately (required by PayPal)
3. Re-POST the same data to PayPal with 'cmd=_notify-validate' prepended
4. PayPal responds with 'VERIFIED' or 'INVALID'
5. If INVALID: log and stop
6. If VERIFIED: validate payment_status == 'Completed', receiver_email matches setting, amount matches booking service price
7. Extract booking_id from custom field, call confirm_payment()
```

**PayPal POST fields we use:**
- `payment_status` — must be 'Completed'
- `receiver_email` — must match `lm_paypal_email` setting (prevents spoofing)
- `mc_gross` — amount paid
- `mc_currency` — must be 'USD'
- `txn_id` — transaction ID
- `custom` — booking ID (we pass this in the redirect URL)

### `confirm_payment()` sequence

```php
1. Re-verify booking exists and is in pending_payment status (prevent replay attacks)
2. Check txn_id not already used (prevent duplicate IPN processing)
3. UPDATE lm_bookings SET status='confirmed', payment_status='paid', 
   payment_amount=$amount, payment_txn_id=$txn_id, payment_date=NOW()
4. Push GCal event (same as current confirmed flow)
5. Send confirmation emails to client and admin (same templates as today)
```

---

## PayPal Redirect URL

Built by `class-lets-meet-paypal.php::get_redirect_url()`:

```
https://www.paypal.com/cgi-bin/webscr
  ?cmd=_xclick
  &business={lm_paypal_email}
  &item_name={service_name} with {provider_name}
  &amount={service_price}
  &currency_code=USD
  &custom={booking_id}
  &notify_url={site_url}/lets-meet-ipn/
  &return={site_url}/?lm_payment=success&booking={booking_id}
  &cancel_return={site_url}/?lm_payment=cancelled&booking={booking_id}
  &no_shipping=1
  &no_note=1
```

---

## Return Pages (Client-Facing)

These are handled in `class-lets-meet-public.php` via `template_redirect`, checking for `lm_payment` query var.

**Success page** (`?lm_payment=success&booking={id}`):
> "Payment received! You'll get a confirmation email shortly."  
> Note: PayPal's `return` URL fires on browser redirect — payment may not be IPN-verified yet. Don't show full confirmation here. Just a friendly holding message.

**Cancelled page** (`?lm_payment=cancelled&booking={id}`):
> "Payment was cancelled. Your booking is being held for [X hours]. To complete your booking, please [link to PayPal again] or contact us."  
> This renders the PayPal redirect link again so they can retry.

---

## Admin UI Changes

### Services CRUD — `class-lets-meet-admin.php`
- Add `Price ($)` field to add/edit service form
- Validate: numeric, ≥ 0, max 2 decimal places
- Display price in services list table column (show "Free" for 0.00)

### Bookings List Table — `class-lets-meet-bookings-table.php`
- Add `Payment` column showing payment_status as a badge:
  - `none` → gray "Free"
  - `pending` → amber "Pending"
  - `paid` → green "Paid"
  - `waived` → blue "Waived"
- Add payment status filter to the existing status filter row

### Booking Detail Page — `class-lets-meet-admin.php`
Add a Payment section showing:
- Status
- Amount paid (if paid)
- Transaction ID (if paid), linked to PayPal transaction URL
- Payment date (if paid)

**Manual override controls:**
- If `pending`: buttons for "Mark as Paid" (opens modal: enter amount + txn_id manually) and "Mark as Waived"
- If `paid` or `waived`: read-only display, no override needed
- If `none`: nothing shown

---

## Email Changes

### Existing templates — minimal changes

**`confirmation-client.php`** — add conditional block:
```php
<?php if ( $payment_status === 'paid' ) : ?>
  <p>Payment of $<?= esc_html( $payment_amount ) ?> received. Transaction ID: <?= esc_html( $payment_txn_id ) ?></p>
<?php endif; ?>
```

**`confirmation-admin.php`** — add payment info to booking summary block (same conditional).

### No new templates needed
The existing confirmation templates fire after IPN verification, so they already represent a complete/paid booking. No separate "payment received" template.

---

## File Changes Summary

| File | Change |
|------|--------|
| `lets-meet.php` | Version bump to 1.2.0, include new PayPal class |
| `includes/class-lets-meet-db.php` | Add columns, bump db_version |
| `includes/class-lets-meet-loader.php` | Wire PayPal hooks |
| `includes/class-lets-meet-services.php` | Add price field to CRUD |
| `includes/class-lets-meet-bookings.php` | Status logic on create |
| `includes/class-lets-meet-public.php` | Redirect on paid booking, return page handlers |
| `includes/class-lets-meet-admin.php` | Price field, payment UI, manual override |
| `includes/class-lets-meet-bookings-table.php` | Payment column + filter |
| `includes/class-lets-meet-paypal.php` | **NEW** — all PayPal logic |
| `templates/emails/confirmation-client.php` | Add payment info block |
| `templates/emails/confirmation-admin.php` | Add payment info block |
| `assets/js/public.js` | Handle redirect response from AJAX |

---

## Todo List

- [ ] **Phase 1: Schema**
  - [ ] Add `price` column to `lm_services`
  - [ ] Add `payment_status`, `payment_amount`, `payment_txn_id`, `payment_date` to `lm_bookings`
  - [ ] Bump `lm_db_version` to `1.2.0`, run dbDelta on upgrade

- [ ] **Phase 2: Settings**
  - [ ] Add PayPal email field to General settings tab
  - [ ] Add PayPal sandbox toggle to General settings tab
  - [ ] Sanitize + save both fields

- [ ] **Phase 3: Services**
  - [ ] Add price field to service add/edit form
  - [ ] Validate and save price
  - [ ] Show price in services list table

- [ ] **Phase 4: PayPal class**
  - [ ] `get_redirect_url()` — builds PayPal URL with all params
  - [ ] `register_ipn_endpoint()` — rewrite rule + query var
  - [ ] `handle_ipn()` — receive, 200, verify, validate, call confirm
  - [ ] `verify_ipn()` — POST back to PayPal, check VERIFIED
  - [ ] `confirm_payment()` — update DB, push GCal, send emails
  - [ ] Replay attack prevention (txn_id uniqueness check)

- [ ] **Phase 5: Booking flow**
  - [ ] `create_booking()` — conditional status based on service price
  - [ ] Suppress GCal + emails for pending_payment bookings
  - [ ] Return `paypal_redirect_url` in result for paid bookings
  - [ ] Frontend JS: handle redirect response
  - [ ] Return page handler (success + cancelled)

- [ ] **Phase 6: Admin UI**
  - [ ] Payment column in bookings list table
  - [ ] Payment status filter
  - [ ] Payment section in booking detail view
  - [ ] Manual override controls (mark paid / mark waived)

- [ ] **Phase 7: Email updates**
  - [ ] Add payment block to `confirmation-client.php`
  - [ ] Add payment block to `confirmation-admin.php`

- [ ] **Phase 8: Testing**
  - [ ] Activate on Local — no errors, existing bookings unaffected
  - [ ] Free service: book end-to-end, confirm status = confirmed, no PayPal redirect
  - [ ] Paid service: book, confirm redirect to PayPal sandbox
  - [ ] Simulate IPN (PayPal IPN simulator tool): confirm booking updates to confirmed + paid
  - [ ] Verify GCal event pushed after IPN (not at booking time)
  - [ ] Verify emails sent after IPN
  - [ ] Test IPN replay prevention (send same txn_id twice)
  - [ ] Test spoofed receiver_email in IPN
  - [ ] Test manual admin override (mark paid, mark waived)
  - [ ] Test payment column + filter in bookings list
  - [ ] Test cancelled return page + retry PayPal link
  - [ ] Confirm existing v1.1.0 cancel/reschedule still works on confirmed bookings
  - [ ] No PHP notices or warnings with WP_DEBUG on

---

*Let's Meet v1.2.0 Payment Plan | Built on v1.1.0 cancel/reschedule foundation*
