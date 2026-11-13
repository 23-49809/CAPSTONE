# SMS and email notifications

## What is sent

| When | What | How |
|---|---|---|
| A client submits a request | One automatic confirmation with the reference number: SMS to the mobile number, and email when an address was given | Queued with the request; sent right after the response by `bin/send-submission-confirmations.php` |
| A staff member presses Send Notification | The staff message, by SMS and/or email | Sent during the request (`sa_send_notification`) |
| A request's status changes | Nothing | Shown in request tracking only |

"Sent" in the app means IPROG or Gmail accepted the message. Neither proves it reached the
phone or inbox.

## Settings (web server environment, e.g. `/etc/smart-assess/apache.env`)

| Variable | Default | Meaning |
|---|---|---|
| `SMART_ASSESS_SMS_ENABLED` | off | `1` turns SMS on |
| `IPROG_API_TOKEN` | (required for SMS) | IPROG API token |
| `SMART_ASSESS_SMS_PROVIDER` | unset (IPROG default, route 0) | IPROG `sms_provider` route sent with every SMS: `0`, `1` or `2`. Any other value is ignored and logged. On 13 Nov 2026 route 0 messages to a Globe number were accepted by IPROG but never arrived, while routes 1 and 2 arrived. |
| `SMART_ASSESS_SUBMISSION_CONFIRMATION` | on | `0` switches the automatic confirmation off |
| `SMART_ASSESS_PHP_CLI` | `/usr/bin/php` | PHP command-line binary used to start the confirmation sender |
| `SMART_ASSESS_GMAIL_DIR` | (required for email) | Gmail OAuth client and token folder |

Changing `apache.env` needs an Apache restart.

## Automatic confirmations

* Queued inside the submission's database transaction (behind a savepoint), so a
  confirmation exists only for a saved request, and a queueing problem never fails a
  submission.
* Sent once: the sender claims each confirmation (`send_state` queued → sending) before
  sending, never retries, and records the outcome on the notification and its history row.
  If a sender stops after claiming, the confirmation stays "sending" (shown as unconfirmed)
  and is never sent again.
* If the sender cannot start, the confirmation stays queued; any later sender run sends
  confirmations queued more than two minutes ago.
* The SMS is plain text, one SMS part, with no web link (Globe blocks SMS with links). The
  email is the mailer's own "We received your request" email with the tracking link.
* Automatic confirmations never block staff messages (only unconfirmed *staff* SMS do).
* Sender log: `logs/confirmations.log` (request ID and outcome only).

## IPROG message IDs

Every SMS (staff and automatic) stores IPROG's message ID and the status IPROG reported once,
shortly after accepting it. Staff see both in the notification history, so an SMS that did not
arrive can be looked up in the IPROG dashboard or with IPROG support.

## Database update

`database/migrations/20261113_notification_delivery_tracking.sql` adds nullable columns and
indexes to `notifications`; safe to run more than once. Until it has run, the website behaves
as before: no confirmation is queued and no IPROG ID is stored.

Order for production: back up the database, run the migration, deploy this release, set
`SMART_ASSESS_SMS_PROVIDER`, restart Apache, then do one controlled test submission.
