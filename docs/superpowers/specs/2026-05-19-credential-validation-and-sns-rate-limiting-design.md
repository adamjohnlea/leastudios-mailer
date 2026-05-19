# Design: AWS credential validation & SNS webhook rate limiting

**Date:** 2026-05-19
**Plugin:** leastudios-mailer
**Status:** Approved

## Background

A code review (`leastudios-dev-tools/CODE_REVIEW.md`, "leastudios-mailer" section) listed three low-priority items. One — a silent encryption fallback in `Options_Encryptor` — is already resolved: `derive_key()` now `wp_die()`s when `AUTH_KEY` / `SECURE_AUTH_SALT` are undefined. This spec covers the remaining two:

1. No format validation of AWS credentials before they are encrypted and stored.
2. No rate limiting on the public SNS webhook endpoint.

## Item A — AWS credential format validation

### Goal

Catch typo'd AWS credentials at save time, in the admin, instead of letting them surface later as an opaque SES authentication failure at send time.

### Change

Add format validation to `Settings_Page::sanitize_options()` — the registered Settings API `sanitize_callback`.

Two new private class constants on `Settings_Page`:

- `ACCESS_KEY_PATTERN = '/^AKIA[A-Z0-9]{16}$/'` — AWS Access Key IDs are 20 characters with an `AKIA` prefix. `ASIA` (temporary STS) keys are deliberately rejected: they require an `X-Amz-Security-Token` the SES signer does not send, so they cannot work with this plugin.
- `SECRET_KEY_PATTERN = '#^[A-Za-z0-9/+]{40}$#'` — AWS Secret Access Keys are exactly 40 base64-alphabet characters. The `#` delimiter avoids escaping the `/` in the character class.

### Behavior

For each of `access_key` and `secret_key`:

- Empty submission → keep the current stored value (unchanged from today; a blank field intentionally means "leave the secret as-is").
- Non-empty and matches its pattern → encrypt and store (unchanged from today).
- Non-empty and fails its pattern → keep the current stored value, and call `add_settings_error()` with an error-level message naming the field. The malformed value is never encrypted or stored.

All other fields (region, from address, enabled, retention) are sanitized and saved as before, regardless of a credential validation failure.

## Item B — SNS webhook rate limiting

### Goal

Add IP-based rate limiting to the public, unauthenticated SNS webhook (`POST leastudios-mailer/v1/sns-webhook`) as defense-in-depth, so a flood of junk requests cannot drive repeated signature verification and signing-certificate fetches.

### Change

Add a rate-limit check to `SNS_Controller::verify_request()`, extracted into a private helper method. It runs first — before JSON parsing, the `Type` check, and `verify_sns_signature()` — so the cheap counter gates the expensive crypto and the outbound cert fetch.

### Mechanism

Fixed-window counter stored in a transient:

- Window bucket: `(int) floor( time() / $window )`.
- Transient key: `leastudios_mailer_sns_rl_` + bucket + `_` + `md5( $ip )`.
- Client IP: `$_SERVER['REMOTE_ADDR']`, used directly. `X-Forwarded-For` is spoofable and not trusted.
- On each request: read the counter (absent = 0), increment, write back with TTL = window. Because the key embeds the bucket, the TTL is always just the window — no TTL-preservation logic needed. The benign read-increment-write race under concurrency is acceptable for a rate limiter.
- If the post-increment count exceeds the limit → return `WP_Error( 'rate_limited', …, [ 'status' => 429 ] )`.

### Configuration

Two new filters, matching the plugin's existing filter conventions:

- `leastudios_mailer_sns_rate_limit` — integer, default `120`. Maximum requests per window per IP. `0` disables rate limiting entirely.
- `leastudios_mailer_sns_rate_window_seconds` — integer, default `60`.

120 requests/minute per IP is intentionally generous. If a large outbound send's burst of SES delivery notifications ever trips the limit, SNS retries the 429'd notifications per its delivery-retry policy — delivery-status updates are delayed, never lost.

## Testing

### Item A — new tests around `Settings_Page::sanitize_options()` (public)

- Valid access key + valid secret key → both encrypted and present in the returned array.
- Malformed access key → returned array keeps the current stored `access_key`; a settings error is registered.
- Malformed secret key → returned array keeps the current stored `secret_key`; a settings error is registered.
- Blank credential fields → current stored values preserved (regression guard).

### Item B — extend `SNS_ControllerTest`

- Requests under the limit pass the rate check (proceed to signature verification).
- A request that exceeds the limit returns a `WP_Error` with status 429.
- Tests pin a low limit via the `leastudios_mailer_sns_rate_limit` filter and set `$_SERVER['REMOTE_ADDR']`.

## Out of scope / housekeeping

- CODE_REVIEW.md item #1 is already resolved; its entry will be marked resolved in `leastudios-dev-tools/CODE_REVIEW.md` (a separate git repository — committed independently).
- No new classes; both changes are localized to existing files.

## Verification

`composer lint` clean (PHPCS + PHPStan level 6) and `composer test` green before the work is considered complete. The new hooks are documented in `docs/developer-handbook.md`.
