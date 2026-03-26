# Let's Meet — User Guide

A lightweight 1-on-1 booking plugin for WordPress. Clients pick a service, choose a date and time from your availability, and book — with optional Google Calendar sync and automatic Zoom meetings.

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Creating Services](#creating-services)
3. [Setting Your Availability](#setting-your-availability)
4. [Booking Rules](#booking-rules)
5. [Adding the Booking Widget to a Page](#adding-the-booking-widget-to-a-page)
6. [Google Calendar Integration](#google-calendar-integration)
7. [Zoom Integration](#zoom-integration)
8. [Email Settings](#email-settings)
9. [Managing Bookings](#managing-bookings)
10. [Client Cancel & Reschedule](#client-cancel--reschedule)
11. [General Settings](#general-settings)
12. [Privacy & GDPR](#privacy--gdpr)

---

## Getting Started

After activating the plugin, a new **Let's Meet** menu appears in your WordPress admin sidebar with three sections:

- **Bookings** — View and manage all client bookings
- **Services** — Create the services clients can book
- **Settings** — Configure availability, Google Calendar, Zoom, emails, and general options

**Minimum setup to accept bookings:**

1. Create at least one service
2. Set your weekly availability
3. Add the `[lets_meet]` shortcode to a page

---

## Creating Services

Go to **Let's Meet > Services** and click **Add New Service**.

| Field | Required | Description |
|-------|----------|-------------|
| Name | Yes | What clients see (e.g., "30-Minute Consultation") |
| Duration | Yes | Session length: 15 to 240 minutes, in 15-minute increments |
| Description | No | Brief description shown to clients on the booking form |
| Enable Zoom | No | Auto-create a Zoom meeting for each booking of this service (requires Zoom to be connected — see [Zoom Integration](#zoom-integration)) |

Services can be **deactivated** instead of deleted — this preserves booking history while hiding the service from clients. Click **Deactivate** next to any service to toggle it off, and **Activate** to bring it back.

---

## Setting Your Availability

Go to **Let's Meet > Settings > Availability**.

### Weekly Schedule

Set up to **3 time windows per day** for each day of the week. For example:

- Monday: 9:00 AM – 12:00 PM, 1:00 PM – 5:00 PM
- Tuesday: 10:00 AM – 3:00 PM

Leave a day blank to mark it as unavailable. Use the **Copy to next day** button to quickly duplicate a day's schedule.

Times are in 30-minute increments. Windows cannot overlap within the same day.

### Booking Rules

These are on the same Availability tab:

| Setting | Default | Options | What it does |
|---------|---------|---------|--------------|
| Buffer Time | 30 min | 15, 30, 45, 60 min | Blocks time before and after each booking so you're not back-to-back |
| Minimum Notice | 2 hours | 1, 2, 4, 8, 24 hours | How far in advance a client must book (prevents last-minute bookings) |
| Booking Horizon | 60 days | 14, 30, 60, 90 days | How far into the future clients can see and book |

---

## Adding the Booking Widget to a Page

Add this shortcode to any page or post:

```
[lets_meet]
```

This displays the full booking flow: service selection, calendar, time slots, and booking form.

### Pre-selecting a service

If you want the booking page to skip the service selection step and go straight to the calendar for a specific service:

```
[lets_meet service="your-service-slug"]
```

The service slug is shown on the Services admin page next to each service. If you only have one active service, the service selection step is automatically skipped.

### What clients see

The booking widget walks clients through 4 steps:

1. **Choose a Service** — Radio buttons for each active service (skipped if only one or pre-selected)
2. **Pick a Date & Time** — Interactive calendar showing available dates. Clicking a date loads available time slots.
3. **Your Details** — Form with Name (required), Email (required), Phone (optional), and Notes (optional). Shows a summary of what they selected.
4. **Booking Confirmed** — Success message with booking details and a note that a confirmation email has been sent.

---

## Google Calendar Integration

Google Calendar integration is **optional**. When connected, the plugin:

- Checks your Google Calendar for conflicts when calculating available slots (so existing events block those times)
- Creates a calendar event for each new booking (includes the Zoom link in the event description if Zoom is enabled)
- Updates the calendar event when a booking is rescheduled
- Deletes the calendar event when a booking is cancelled

Without Google Calendar, the plugin still works — it just uses its own database to track availability.

### Setup

Go to **Let's Meet > Settings > Google Calendar**.

#### Step 1: Create Google OAuth Credentials

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a project (or select an existing one)
3. Enable the **Google Calendar API**
4. Go to **Credentials > Create Credentials > OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Under **Authorized redirect URIs**, add:
   ```
   https://yoursite.com/wp-admin/admin-post.php?action=lm_gcal_callback
   ```
   Replace `yoursite.com` with your actual domain.
7. Copy the **Client ID** and **Client Secret**

#### Step 2: Connect in WordPress

1. Paste the Client ID and Client Secret into the settings fields
2. Set the **Calendar ID** (leave as `primary` to use your main calendar, or enter a specific calendar's email address)
3. Click **Save Credentials**
4. Click **Connect Google Calendar**
5. Approve access in the Google consent screen
6. You'll be redirected back to WordPress with a "Connected" status

#### Disconnecting

Click **Disconnect** on the Google Calendar settings tab. This removes stored tokens. Existing bookings and their calendar events are not affected.

#### If something goes wrong

If the connection breaks (e.g., token expires and can't refresh), you'll see a notice in the admin. Click **Reconnect** to re-authorize. The plugin gracefully falls back to database-only availability if Google Calendar is unreachable.

---

## Zoom Integration

Zoom integration is **optional**. When connected and enabled on a service, the plugin:

- Automatically creates a Zoom meeting for each new booking
- Includes a "Join Zoom Meeting" link in the confirmation email to both client and admin
- Updates the Zoom meeting time if the booking is rescheduled
- Deletes the Zoom meeting if the booking is cancelled

If Zoom is not connected or a service doesn't have Zoom enabled, bookings work normally without video conferencing.

### Setup

Go to **Let's Meet > Settings > Zoom**.

#### Step 1: Create a Server-to-Server OAuth App

1. Go to [marketplace.zoom.us](https://marketplace.zoom.us/) and sign in with your Zoom account
2. Click **Develop > Build App**
3. Choose **Server-to-Server OAuth** as the app type (do not choose "OAuth" — that is a different type)
4. Give your app a name (e.g., "My Booking Plugin") and create it
5. On the **App Credentials** tab, copy the **Account ID**, **Client ID**, and **Client Secret**
6. Go to the **Scopes** tab and add these scopes:
   - `meeting:write:meeting:admin` — Create a meeting
   - `meeting:update:meeting:admin` — Update a meeting (for reschedule)
   - `meeting:delete:meeting:admin` — Delete a meeting (for cancellation)
7. Click **Activate** to activate the app

#### Step 2: Connect in WordPress

1. Paste the Account ID, Client ID, and Client Secret into the settings fields
2. Click **Save Credentials**
3. Click **Test Connection** to verify everything works
4. Go to **Let's Meet > Services**, edit each service that should include a Zoom meeting, and check **Enable Zoom**

### How it works

When a client books a Zoom-enabled service:

- A Zoom meeting is created automatically with "join before host" enabled and no waiting room
- The Zoom join URL appears in the confirmation email sent to the client (as a clickable button) and to the admin
- The join URL is also shown in the booking details in the admin dashboard
- If Google Calendar is connected, the Zoom link is included in the calendar event description

### Disconnecting

Click **Disconnect** on the Zoom settings tab. This removes stored credentials. Existing Zoom meetings that were already created are not affected (they remain in your Zoom account).

### If something goes wrong

If a Zoom meeting fails to create (e.g., credentials expire), the booking still succeeds — the client just won't receive a Zoom link. Check the Zoom settings tab for connection status and use **Test Connection** to troubleshoot.

---

## Email Settings

Go to **Let's Meet > Settings > Email**.

| Setting | Default | Description |
|---------|---------|-------------|
| Reply-To Email | Site admin email | The reply-to address on client confirmation emails |
| Confirmation Message | (empty) | Custom text or HTML included in the client confirmation email body. Use this for directions, cancellation policies, preparation instructions, etc. |
| Admin Notifications | On | When enabled, you receive an email each time a new booking is made |

### Emails sent

- **Client confirmation** — Sent to the client immediately after booking. Includes service name, date, time, duration, your custom confirmation message (if set), and links to cancel or reschedule. If Zoom is enabled for the service, includes a "Join Zoom Meeting" button.
- **Admin notification** — Sent to you (the Reply-To Email address) with client details and a link to view the booking in the admin. Includes the Zoom meeting URL if applicable.
- **Reschedule notification (client)** — Sent to the client when a booking is rescheduled (by admin or by the client themselves). Includes the updated date/time and Zoom link if applicable.
- **Reschedule notification (admin)** — Sent to you when a client reschedules their own booking.
- **Cancellation notification (client)** — Sent to the client when their booking is cancelled.

### Template overrides

To customize the email HTML, copy the template files from the plugin into your theme:

```
wp-content/plugins/lets-meet/templates/emails/confirmation-client.php
wp-content/plugins/lets-meet/templates/emails/confirmation-admin.php
wp-content/plugins/lets-meet/templates/emails/reschedule-client.php
wp-content/plugins/lets-meet/templates/emails/reschedule-admin.php
wp-content/plugins/lets-meet/templates/emails/cancellation-client.php
```

Copy them to:

```
wp-content/themes/your-theme/lets-meet/emails/confirmation-client.php
wp-content/themes/your-theme/lets-meet/emails/confirmation-admin.php
wp-content/themes/your-theme/lets-meet/emails/reschedule-client.php
wp-content/themes/your-theme/lets-meet/emails/reschedule-admin.php
wp-content/themes/your-theme/lets-meet/emails/cancellation-client.php
```

Edit the theme copies. The plugin will use your theme versions automatically.

---

## Managing Bookings

Go to **Let's Meet > Bookings**.

### Bookings List

Shows all bookings with columns for Date & Time, Client name, Email, Service, and Status. You can:

- **Filter** by status: All, Confirmed, or Cancelled
- **Sort** by date
- **View** a booking's full details (client name, email, phone, notes, Google Calendar sync status, Zoom meeting link)
- **Reschedule** a booking — opens a date/time picker to move it to a new slot (updates Google Calendar and Zoom meeting automatically)
- **Cancel** a booking (also removes the Google Calendar event and Zoom meeting if connected)
- **Bulk cancel** multiple bookings using the checkboxes

### Booking Statuses

- **Confirmed** — Active booking
- **Cancelled** — Cancelled by admin or client. The time slot becomes available again for new bookings.

---

## Client Cancel & Reschedule

Clients can cancel or reschedule their own bookings using secure links included in their confirmation email. No login is required.

### How it works

Each confirmation email includes **Reschedule** and **Cancel** links at the bottom. These links contain a unique, per-booking token — only someone with the link can take action.

- **Cancel** — The client clicks the cancel link, sees their booking details, and confirms cancellation. The booking is marked as cancelled, the time slot opens up, and the associated Google Calendar event and Zoom meeting (if any) are removed.
- **Reschedule** — The client clicks the reschedule link and sees a date/time picker showing your current availability. After selecting a new time, the booking is updated. Google Calendar and Zoom meeting times are updated automatically. Both client and admin receive a reschedule notification email.

### Limitations

- Clients can only reschedule to times that are currently available (same rules as a new booking)
- Already-cancelled bookings cannot be rescheduled

---

## General Settings

Go to **Let's Meet > Settings > General**.

| Setting | Default | Description |
|---------|---------|-------------|
| Keep Data on Uninstall | On | When checked, all plugin data (bookings, services, settings) is preserved if you uninstall the plugin. Uncheck this to delete everything on uninstall. |

---

## Privacy & GDPR

The plugin integrates with WordPress's built-in privacy tools.

### Personal Data Export

When you process a personal data export request (**Tools > Export Personal Data**), the plugin includes all bookings associated with the requested email address: service name, date/time, duration, status, and client details.

### Personal Data Erasure

When you process an erasure request (**Tools > Erase Personal Data**), the plugin anonymizes bookings for that email address. Client name, email, phone, and notes are replaced with `[deleted]`. The booking record itself is kept (so your schedule history remains intact) but all personal information is removed.
