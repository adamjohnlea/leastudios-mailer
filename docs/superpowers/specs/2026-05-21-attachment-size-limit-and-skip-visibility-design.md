# Design: Attachment size limit & skipped-attachment visibility

**Date:** 2026-05-21
**Plugin:** leastudios-mailer
**Status:** Approved

## Background

The mailer forwards `wp_mail()` attachments to SES: `Mailer::normalize_attachments()`
validates each file and drops unreadable ones, then `Client::send_raw_email()`
assembles an RFC 5322 MIME message and sends it via SES `Content.Raw`. Two gaps
remain:

1. **No message-size pre-check.** Amazon SES rejects messages over its size limit
   (~40 MB). The plugin sends regardless, so an oversized message surfaces only as
   an opaque SES API rejection.
2. **Dropped attachments are barely visible.** When a file is unreadable it is
   dropped and the `leastudios_mailer_attachments_skipped` action fires — but
   nothing reaches the admin. An email silently goes out missing a file unless a
   developer wired up that hook.

Both changes are localized to `Email/Mailer.php` and `SES/Client.php`. No new
classes, no schema change.

## Item A — Message size pre-check

### Goal

Catch an oversized message before it reaches SES, turning an opaque SES rejection
into a clear, logged failure.

### Change

In `Client::send_raw_email()`, after `build_raw_message()` returns a non-null
`$raw_message`, measure `strlen( $raw_message )` and compare it against a
filterable byte limit.

A new filter sets the limit:

- `leastudios_mailer_max_message_bytes` — integer bytes, default `40 * MB_IN_BYTES`
  (40 MB, SES's documented limit). Returning `0` or less disables the check.

The MIME message PHPMailer produces already has attachments base64-encoded inside
it, so `strlen( $raw_message )` is the encoded message size — the same quantity
SES weighs against its limit.

### Behavior

- Message within the limit, or the limit disabled (`<= 0`) → send proceeds
  unchanged.
- Message over the limit → `send_raw_email()` returns
  `[ 'success' => false, 'message_id' => null, 'error' => <message> ]` **without
  calling the SES API**. The error names the actual size and the limit, e.g.
  *"Message size (47.3 MB) exceeds the 40 MB limit; not sent to SES."*

This failure result flows through `Mailer::send()`'s existing logging path
untouched: the email is recorded in the log table as `failed` with that error.

Only the Raw (attachment-bearing) send path is checked. The no-attachment
`Content.Simple` path carries only a body and realistically never approaches the
limit, so `send_email()` is left unchanged.

Because the check lives inside `send_raw_email()`, it runs *after* the
`leastudios_mailer_pre_send` filter — so it measures the final attachment set,
including any a `pre_send` listener added or removed.

## Item B — Skipped-attachment visibility in the email log

### Goal

Make dropped (unreadable) attachments visible in the Email Log admin tab, not only
through an action hook.

### Change

`Mailer::normalize_attachments()` currently returns only the validated attachment
list. It will return both the validated list and the skipped entries.
`Mailer::send()` builds a human-readable note from the skipped list and folds it
into the `error_message` it passes to `Email_Logger::log()`.

The note lists the affected filenames, e.g.
*"1 attachment could not be read and was not sent: report.pdf"*.

### Behavior

For the row written to the email log:

| Send outcome | Attachments skipped | `error_message` written |
|---|---|---|
| Sent | none | `null` (unchanged from today) |
| Sent | one or more | the skipped-attachment note |
| Failed | none | the SES error (unchanged from today) |
| Failed | one or more | the SES error and the note, combined |

The log row's `status` is unaffected — a successful send with skipped attachments
is still `sent`; the note is informational. The existing `error_message` column
holds it, so no schema migration is needed.

The `leastudios_mailer_attachments_skipped` action continues to fire exactly as
before — this change is purely additive.

## Testing

### Item A — `ClientTest`

- `send_raw_email()` with a message that exceeds a filtered-low
  `leastudios_mailer_max_message_bytes` returns `success => false` with an error
  naming the size, and makes no SES API call.
- `send_raw_email()` with a message under the limit still dispatches to SES
  (stub the `outbound-emails` endpoint via `pre_http_request`, returning 200).

### Item B — `MailerTest`

- `wp_mail()` with one unreadable attachment path sends the email and the logged
  row's `error_message` contains the skipped-attachment note naming the file.

## Out of scope

- No per-attachment size limit — SES limits the whole message, which is what is
  checked.
- No "drop oversized attachments and send anyway" — an over-limit message fails
  the whole send (decision: never silently drop content the caller supplied).
- No new email-log column — the existing `error_message` column is reused.
- The no-attachment `Content.Simple` path is not size-checked.

## Verification

`composer lint` clean (PHPCS + PHPStan level 6) and `composer test` green before
the work is considered complete. The new `leastudios_mailer_max_message_bytes`
filter is documented in `docs/developer-handbook.md`.
