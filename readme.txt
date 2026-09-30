=== Wedding Booking ===
Contributors: wedding-booking
Tags: booking, photography, woocommerce, appointment, calendar
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your photography site into a booking engine: packages, add-ons, availability calendar, e-signed contracts and 50% deposits through WooCommerce.

== Description ==

Wedding Booking gives photography studios a complete booking funnel on one page. Clients pick a date from the calendar, choose a package and add-ons, fill in their details, accept your terms, and pay a deposit — without ever leaving the booking form.

Everything is managed from a single admin menu: session types, packages, add-ons, availability, form text, emails, and payment rules.

**The booking flow**

Package and add-ons &rarr; client details &rarr; contract (optional) &rarr; payment. The availability calendar sits in a sidebar next to the form, so the date is always visible and never costs an extra step.

**Highlights**

* **One-page booking wizard** — drop `[wedding_booking]` on any page. No page reloads between steps.
* **Availability calendar** — mark any future date Available, Booked, or Blocked with a click.
* **Packages and add-ons** — priced per session type, with featured badges and optional images.
* **50% deposits** — clients pay half now and half later. Wedding Booking creates the balance order automatically and emails a payment link.
* **Terms &amp; Conditions step** — show your service agreement in the form and require the client to accept it before paying. Fully optional and edited with the visual editor.
* **Embedded checkout** — the WooCommerce payment section loads inside the form, so PayPal buttons and card fields appear without a redirect.
* **Branded emails** — every message the plugin sends uses one themed template that follows your brand colors, with an optional file attachment such as a contract PDF.
* **Balance reminders** — automatic and manual reminder emails with a pay-now link.
* **Shareable package links** — every package gets a link that opens the booking form with that package pre-selected.
* **Checkout field builder** — enable, rename, or require any built-in field, and add custom fields of your own.
* **Works without WooCommerce** — the form falls back to an enquiry email plus a WhatsApp button.
* **No external requests** — no CDN fonts or icon libraries. Assets load only on pages that contain the form.

== Installation ==

1. Upload the `wedding-booking` folder to `/wp-content/plugins/`, or install the ZIP from **Plugins &rarr; Add New**.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Wedding Booking &rarr; Settings** to set your notification email, WhatsApp number, booking page, brand colors, and checkout and payment options. Currency comes from WooCommerce automatically.
4. Add your session types, packages, and add-ons, then open **Wedding Booking &rarr; Date Slots** to manage availability.
5. Put the shortcode `[wedding_booking]` on any page.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

No. WooCommerce is optional. When it is active, clients pay a deposit or the full amount at checkout. Without it, the form sends a booking enquiry email and offers a WhatsApp button instead.

= How do I display the booking form? =

Use the shortcode `[wedding_booking]` on any page or post. It also accepts optional attributes: `[wedding_booking package="slug-or-id" primary="#b8956a" accent="#3d6b78"]` pre-selects a package and overrides the two brand colors for that instance.

= How do I add my Terms and Conditions to the booking form? =

Go to **Wedding Booking &rarr; Booking Form** and turn on **Contract step**. You can set the step name, heading, the agreement text (using the visual editor), and the wording of the acceptance checkbox. The client cannot reach the payment step until the box is ticked. The step is off by default.

= Can I block specific dates? =

Yes. Go to **Wedding Booking &rarr; Date Slots** and click any future date to cycle it between Available, Booked, and Blocked.

= How does the 50% deposit work? =

The client pays half at checkout. Wedding Booking creates a second WooCommerce order for the remaining balance, shows the full breakdown on the order and in emails, and sends a payment link for the balance — immediately in the confirmation email and again through scheduled reminders.

= Can I change the wording on the booking form? =

Yes. **Wedding Booking &rarr; Booking Form** controls the steps, the contract step and the sidebar cards. **Wedding Booking &rarr; Settings &rarr; Checkout** holds the messages shown after booking, and **Settings &rarr; Emails** the order email and balance reminders.

= Which page does the booking form live on? =

Any page with the shortcode. Wedding Booking auto-detects it for the package share links, and you can also choose it explicitly under **Wedding Booking &rarr; Settings &rarr; General**.

== Screenshots ==

1. The booking form: package selection with the availability calendar in the sidebar.
2. The contract step, where clients read and accept your Terms and Conditions.
3. The payment step with the booking summary and the embedded WooCommerce checkout.
4. Admin bookings list with status management and balance tracking.
5. Packages management with pricing, add-ons, and share links.
6. The Date Slots availability calendar.
7. The Booking Form screen, where the steps, the contract step and the sidebar cards are edited.

== Changelog ==

= 1.6.1 =
* **Send a payment reminder right away:** the booking View window now has a **Send reminder now** button in its Balance reminders box, for when the remaining balance can't wait for the automatic reminder. It asks first, shows who it goes to, and warns when a reminder already went out in the last 12 hours.
* After sending, the window updates in place: the number of reminders sent, when the last one went out and when the next automatic one is due.
* When a reminder can't be sent, the window says why (balance already paid, booking cancelled, the balance order is on hold, or no customer email) instead of hiding the button.
* The list's Actions menu uses the same confirmation, and only offers the reminder when it can actually be sent.

= 1.6.0 =
* **Easier admin:** every setting now has a **?** help tip that explains what it does in plain words, with examples. Hover, tab to it or tap it.
* **Settings is organised into sections** — General, Payments, Availability, Checkout, Customers, Emails, Google Calendar and Advanced — with a side menu and a search box that finds any setting (it searches the help text too).
* **Setup checklist** in Settings &rarr; General shows what is left before you can take bookings, with a button for each step. All Bookings reminds you while steps are left.
* Settings that only matter when another option is on are dimmed and say why (for example the deposit amount while the deposit option is off).
* A save bar stays in view, shows when you have unsaved changes, and the browser asks before you leave with unsaved changes.
* The **Frontend** screen is now called **Booking Form** and starts with a diagram of the steps customers go through.
* The top menu is grouped: Bookings · Session Types, Packages, Add-ons · Date Slots · Booking Form, Settings.
* Date Slots explains what each state means. All Bookings explains each booking status.
* Fixed: on phones, on/off switches in settings tables collapsed and overlapped their label.

= 1.5.0 =
* **Availability:**
    * Bookings per day.
    * Optional start times: customers pick a time and each time is booked once.
    * Minimum notice and the furthest bookable day.
    * Closed weekdays.
    * A short hold on a date while the customer pays, so two customers can't pay for the same slot.
* **Deposit and fees:**
    * The deposit percentage is a setting, and each package can have its own.
    * The payment fee has its own label.
    * The fee is not charged for bank transfer, cheque or cash (you choose which methods are exempt).
* **Promo codes:** WooCommerce coupons can be used on the booking form (optional). Each code's discount and usage are recorded on the order.
* **Offline payments:**
    * Bank transfer, cheque and cash-on-delivery bookings are "Awaiting payment" and hold their date. The customer sees the payment instructions, not "confirmed".
    * Unpaid ones can be cancelled automatically after a number of days.
* **Balance deadline:** you can set it a number of days before the shoot. It is shown in the confirmation, the reminders and My Account (`{balance_due_date}` placeholder).
* **Contract:**
    * Acceptance is now recorded on the order: time, terms version, IP, and an optional typed signature.
    * The accepted wording is kept per version, and it is enforced on the server.
* **Free bookings** (0 total) are confirmed immediately.
* **Customers:**
    * A "Bookings" tab in My Account.
    * A booking panel on the order pages, with pay the balance, add to calendar (.ics) and request a reschedule or cancellation.
    * A "Manage your booking" link in the confirmation email.
* **Google Calendar:**
    * Events are updated when a booking changes and removed when it is cancelled.
    * Failed syncs are retried automatically and shown on the Wedding Booking screens.
* **Cancelled bookings** that are re-activated hold their date again (with a conflict check).
* **Studio staff:** Shop Managers can manage bookings (new `manage_wedding_booking` capability).
* **Uninstall:** an option to remove all Wedding Booking data when the plugin is deleted.
* **All Bookings:**
    * Search, status/package/date filters, sorting, pagination, a Session column, CSV export (safe for Excel) and a month calendar view.
    * Status changes explain what will happen and ask before they email the customer.
    * They never mark money as received; use **Record payment** for cash or bank payments.
* **Add booking** by hand (phone or walk-in): paid in full, deposit paid, or unpaid with a payment link emailed to the customer.
* **Edit / reschedule** a booking: dates, orders, calendar event and customer email all updated together.
* **The booking view** shows the start time, promo code, balance deadline, accepted terms (with the exact wording), Google Calendar status and customer change requests.
* **Settings:** new cards for Deposit & payment fee, Availability, Offline payments, Customer account and Plugin data. Packages can have their own deposit %.
* **Booking form:**
    * Start-time buttons under the calendar.
    * Closed, full and out-of-range days are shown as unavailable.
    * The fee is shown on the first step.
    * Promo code field.
    * Typed signature.
    * A login prompt up front when accounts are required.
    * Progress kept across reloads and login.
    * Prices use your WooCommerce currency format with decimals.
* **Booking form accessibility:** every text can be translated, and keyboard and screen-reader use is much better.
* The room number accepts text (e.g. "B12").


= 1.4.0 =
* **Remaining payment reminders rebuilt.** They no longer depend on a single WP-Cron event queued at checkout, which never fired on hosts where WP-Cron doesn't run and skipped bookings made while reminders were off. An hourly check now looks at every booking with an unpaid balance, and runs during normal page visits if WP-Cron is not running.
* Two independent reminder switches: **Reminder before the photoshoot** (N days before, at 09:00) and **Keep reminding until the balance is paid** (every N days, optional limit, optional stop once the shoot date has passed).
* The reminder card shows whether reminders are running, when the last check ran, and which reminder goes out next, and has a "Run reminder check now" button. The booking's View panel shows reminders sent and the next one.
* Reminders go out between 09:00 and 21:00 site time, never twice within 12 hours. Each one is logged as an order note. The subject accepts placeholders, and dates use your site's date format.
* **Security:** booking prices are now calculated on the server from the package and add-ons. Totals sent by the browser are ignored.
* The server now checks the session date before creating an order: past, booked and blocked dates are refused. A booking paid for a date that is already taken is flagged to the studio by email and an order note.
* Fixed: every deposit payment created two balance orders.
* Fixed: marking a booking "Processing" in Bookings marked the unpaid balance as paid and completed the booking. "Waiting payment" no longer reopens a paid order.
* Cancelled or refunded bookings now free their date and cancel the unpaid balance order.
* Unpaid booking orders now expire after WooCommerce's "Hold stock" time. Balance orders never expire.
* The booking confirmation email now includes bank-transfer, cheque and cash-on-delivery payment instructions. After the balance is paid, the confirmation and order totals say "Balance paid".
* Changing the date on the payment step now updates the order. The calendar no longer shows every date as free on a cached page.
* Admin fixes:
    * All Bookings no longer crashes without WooCommerce.
    * Editing add-ons or packages keeps inactive links.
    * A session type with packages can't be deleted.
    * Date Slots won't reopen a date a customer booked.
    * Prices show decimals.
    * An expired admin session shows a clear message.

= 1.3.0 =
* New **Google Calendar** integration (Wedding Booking &rarr; Settings): every paid booking is added to your calendar automatically.
* Event title is the package plus the WooCommerce order number — for example "Beach shooting #34182". The client's chosen location becomes the event location, and the notes carry the package, add-ons, session time and the client's name, phone and email.
* The client is added as a guest, so they get the Google invitation and the shoot appears in their own calendar.
* Every event gets an alert 2 hours before the session (filterable via `wedding_booking_gcal_reminder_minutes`).
* One-click "Connect with Google" — paste your Google app's Client ID and Secret once, then connect and approve. No access tokens to copy and nothing to edit in any file.
* A sync toggle to pause without disconnecting, a "Send a test event" button, and a setup checklist covering the redirect URI, enabling the Calendar API, and publishing the Google app.
* Events use the booking's time when one was given (60 minutes by default, filterable) and fall back to an all-day event otherwise.
* Bookings now store the created event's ID, so a booking is never added to the calendar twice.
* **Balance reminders are now anchored to the shoot date** — the automatic reminder goes out a set number of days before the photoshoot (default 1, at 09:00 site time) instead of a fixed delay after checkout. Set it under Wedding Booking &rarr; Settings &rarr; Remaining Payment Reminder.

= 1.2.0 =
* New **Admin Order Email** setting (Wedding Booking → Settings): send yourself a branded "new booking" notification using the same template your customers get, instead of WooCommerce's plain New Order email.
* The admin email lays out the full booking at a glance — session and add-ons, a payment breakdown (deposit taken vs. balance still due), and any note the customer left.
* Rich customer contact block: clickable email and WhatsApp, hotel / place of stay, street address and country.
* Optional custom recipient, subject, heading, and an editable intro note with placeholders; ships off so existing sites are unchanged until you turn it on.
* Redesigned booking confirmation email — a centered "confirmed" badge, the session date featured in a highlight card, and a matching "Order summary" totals panel shared with the admin notification.

= 1.1.1 =
* The step indicator and the booking form now share a single background card for a cleaner, more unified look.

= 1.1.0 =
* New optional **Contract step** between Details and Payment: show your Terms and Conditions in a scrollable panel and require the client to accept them before paying.
* The contract step is fully editable under **Wedding Booking &rarr; Frontend** — toggle it on or off, name the step, write the agreement in the visual editor, and set the acceptance wording.
* The booking form now adapts to three or four steps automatically, with the step indicator, navigation, and Back buttons all following along.

= 1.0.0 =
* First public release of Wedding Booking.
* Multi-step booking form via the `[wedding_booking]` shortcode, with the availability calendar in a sidebar beside the form.
* Session types, packages, and add-ons management, with featured packages and shareable package links.
* Availability calendar with Available, Booked, and Blocked date states.
* WooCommerce checkout in direct (embedded) or redirect mode, with configurable deposits, a 50% partial-payment mode, automatic balance orders, and an optional payment fee.
* Checkout form builder for built-in and custom fields.
* Branded email system covering booking confirmations, balance reminders, and enquiries, with a customisable order email and file attachment.
* Frontend screen for the sidebar cards, form text, and loading placeholders; Appearance settings for brand colors.
* Enquiry-email and WhatsApp fallback when WooCommerce is not active.
* Security hardening throughout: nonce verification, output escaping, and input sanitisation.

== Upgrade Notice ==

= 1.6.1 =
Adds a "Send reminder now" button to the booking View window, so you can email a customer about their remaining balance straight away.

= 1.6.0 =
A clearer admin: help tips on every setting, Settings split into sections with search, and a setup checklist. No settings change — everything you saved stays as it is.

= 1.5.0 =
Adds availability rules, start times, configurable deposits, promo codes, offline-payment handling, customer booking management and much more. The contract step (if enabled) is now enforced on the server. Clear any page cache after updating so visitors get the new booking form.

= 1.4.0 =
Fixes remaining-payment reminders that were not being sent, and closes a checkout hole that let the browser set the booking price. Recommended for all sites.

= 1.3.0 =
Adds Google Calendar sync for paid bookings. Optional — connect your Google account under Wedding Booking → Settings → Google Calendar to switch it on.

= 1.2.0 =
Adds a branded admin "new booking" email with the full booking details. Off by default — enable it under Wedding Booking → Settings → Admin Order Email.

= 1.1.1 =
Visual polish only: the step indicator and booking form now sit in one card. No settings or behaviour change.

= 1.1.0 =
Adds an optional Terms and Conditions step to the booking form. Nothing changes on existing sites until you turn it on under Wedding Booking &rarr; Frontend.
