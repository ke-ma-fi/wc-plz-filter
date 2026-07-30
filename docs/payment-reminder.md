# Payment Reminder (Zahlungs-Erinnerung)

Automatically emails customers a payment link when their WooCommerce order stays in `pending` status too long.

Relevant source: [`includes/class-wc-plz-reminder.php`](../includes/class-wc-plz-reminder.php).

Configure under **WooCommerce → DT Woohoo → Zahlungs-Erinnerung**.

## How it works

A WP-Cron job (`wc_plz_reminder_scan`) scans WooCommerce orders in status `pending` older than the configured threshold and sends each a reminder email containing `{payment_url}`. Each order receives **at most one reminder**, tracked via the `reminded_pending_payment` order meta flag — the scan skips any order where that flag is already `'true'`.

A transient lock (`wc_plz_reminder_running`, 2-minute TTL) prevents overlapping cron runs if a scan takes longer than the interval between them.

### Dev mode (default: on)

While dev mode is active:

- No cron is scheduled at all — nothing runs automatically.
- A **"Jetzt testen"** button in the admin tab runs the exact same scan on demand, but sends every email to the configured test address and **never sets the meta flag**, so the same order can be tested repeatedly without exhausting its one-reminder budget.
- Live emails are prefixed `[TEST]` (subject) and annotated with the real order ID (body), so a test send is never mistaken for a live one even if `test_email` is misconfigured to a real inbox.

Turning dev mode off schedules the cron immediately (see below); turning it on unschedules it.

### Cron scheduling

The cron interval is a **custom schedule** (`wc_plz_reminder_interval`) registered via the `cron_schedules` filter, not one of WordPress's built-in intervals — this is what lets the interval be a plain "every N minutes" admin setting instead of being limited to hourly/daily/etc.

Changing either `dev_mode` or `cron_interval` in settings triggers `maybe_reschedule_cron()` (hooked to `update_option_wc_plz_reminder`), which unschedules and — if still in live mode — immediately re-schedules the cron with the new interval. No save-and-wait-for-next-run gap.

## Settings

| Setting | Description |
|---------|--------------|
| Dev mode | When active: no automatic cron, all emails sent to test address only (default: on) |
| Test email address | Recipient for all emails while dev mode is active (default: WordPress admin email) |
| Reply-To address | `Reply-To` header set on every outgoing reminder email; leave empty to omit the header (default: WordPress admin email) |
| Cron interval (minutes) | How often the cron job scans for pending orders (default: 5) |
| Pending threshold (minutes) | Orders older than this value trigger a reminder (default: 5) |
| Email subject | Customisable subject line; supports placeholders |
| Email body | Customisable body text; supports placeholders |

## Placeholders

| Placeholder | Replaced with |
|-------------|----------------|
| `{order_number}` | WooCommerce order number |
| `{order_date}` | Order date (WordPress date format) |
| `{customer_first_name}` | Billing first name |
| `{customer_last_name}` | Billing last name |
| `{customer_full_name}` | Billing first + last name |
| `{order_total}` | Order total incl. currency |
| `{payment_url}` | Direct payment link (`WC_Order::get_checkout_payment_url()`) |
| `{shop_name}` | Shop name |

Order number and date are always appended to the email footer, even if the placeholders are removed from the body. A **"Mailtexte auf Standardwerte zurücksetzen"** button restores the default subject/body if they've been edited into a bad state.

## Mail log

The last 50 sent reminders (`WC_PLZ_Reminder::MAX_LOG`) are kept in the `wc_plz_reminder_log` option (most recent first) and shown in a log table under the settings: timestamp, recipient, order ID, mode (`dev`/`live`), and status (`success`/`error`).

A **"Erneut senden"** button per row calls `resend_for_order()`, which — unlike the normal scan path — **bypasses the one-reminder-per-order meta-flag check**, so it can be used to manually retry a failed send or re-notify a customer regardless of prior state. It only sets the flag on success, and only if it wasn't already set.
