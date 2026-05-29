# leaStudios Mailer — Developer Handbook

leaStudios Mailer routes every WordPress `wp_mail()` call through the Amazon SES v2
API, logs each send to a custom database table, and tracks bounce, complaint, and
delivery events via an inbound SNS webhook. The entire send pipeline — from interception
through SES delivery, retry logic, logging, the delivery-tracking webhook, and the admin
UI — is hook-driven end to end, giving extension authors a clean seam to inject
logic without patching core files.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture](#2-architecture)
3. [Development Setup](#3-development-setup)
4. [Concepts](#4-concepts)
5. [Data Model](#5-data-model)
6. [Hooks Reference](#6-hooks-reference)
7. [Hook Execution Order](#7-hook-execution-order)
8. [REST API Reference](#8-rest-api-reference)
9. [Extension Recipes](#9-extension-recipes)
10. [Testing](#10-testing)
11. [Release Process](#11-release-process)
12. [Where to Read More](#12-where-to-read-more)

---

## 1. Overview

leaStudios Mailer is a lightweight Amazon SES email transport for WordPress. Site owners
configure AWS credentials and a verified From address in the admin; the plugin then
intercepts every `wp_mail()` call via the `pre_wp_mail` filter and routes it through the
SES v2 REST API — no SMTP configuration required.

Every send is logged to a custom table with recipient, subject, status, and SES message
ID. When an AWS SNS topic is pointed at the plugin's webhook endpoint, bounce, complaint,
and delivery events flow back into the same log, giving a real-time delivery picture
from the WordPress admin.

For extension authors the main entry points are:

- **Send pipeline hooks** — `leastudios_mailer_should_intercept`, `leastudios_mailer_pre_send`,
  `leastudios_mailer_ses_request_body`, `leastudios_mailer_before_log`, and friends
  (see Section 6). Use these to bypass, modify, tag, or audit every outbound email.
- **SES request hooks** — `leastudios_mailer_ses_request_body` /
  `leastudios_mailer_ses_raw_request_body` expose the raw SES v2 payload for tagging
  with configuration sets or custom headers.
- **SNS webhook hooks** — four filters tighten or loosen the replay-protection window
  and rate limiting for the inbound delivery-tracking endpoint.
- **Admin UI hooks** — `leastudios_mailer_settings_tabs` and the dynamic
  `leastudios_mailer_settings_tab_{$slug}` action let third parties add custom settings
  tabs without editing core files.

The plugin integrates with `leastudios-email-templates` (branded wrapping) and
`leastudios-forms` (per-submission delivery status). Both integrations are optional and
degrade gracefully when those siblings are absent.

---

## 2. Architecture

### Component map

```
leastudios-mailer.php
    └── Plugin::init()
            |
            ├── Database\Migration::maybe_migrate()       schema up-to-date check
            |
            ├── Email\Mailer                              hooks pre_wp_mail (priority 10)
            |       └── SES\Client + SES\Signer           SES v2 API + SigV4 signing
            |
            ├── Log\Email_Logger                          writes/updates the log table
            |
            ├── Webhook\SNS_Controller                    POST /sns-webhook (REST)
            |       └── Email_Logger::update_status()     updates rows on delivery events
            |
            ├── Email\Health_Check                        verifies SES config / test send
            |
            └── Admin\Settings_Page       (admin only)   Configuration, Email Log, Test Email tabs
```

### Send pipeline (wp_mail → SES)

```
wp_mail( $to, $subject, $message, $headers, $attachments )
    |
    pre_wp_mail (priority 10 — Mailer intercepts here)
        |
        +-- [filter] leastudios_mailer_should_intercept
        |       return false → hand back to WordPress default transport
        |
        wp_mail_from / wp_mail_from_name (core filters, fire normally)
        |
        +-- [action] leastudios_mailer_attachments_skipped
        |       (only when unreadable attachment paths are dropped)
        |
        +-- [filter] leastudios_mailer_pre_send
        |       return null → cancel delivery silently
        |
        SES\Client — no attachments:
        |   +-- [filter] leastudios_mailer_ses_request_body
        |   +-- [filter] leastudios_mailer_ses_max_attempts
        |   +-- [filter] leastudios_mailer_ses_retry_delay_ms
        |
        SES\Client — with attachments (RFC 5322 MIME via PHPMailer):
        |   +-- [filter] leastudios_mailer_max_message_bytes  (size guard)
        |   +-- [filter] leastudios_mailer_ses_raw_request_body
        |   +-- [filter] leastudios_mailer_ses_max_attempts
        |   +-- [filter] leastudios_mailer_ses_retry_delay_ms
        |
        SES API responds
        |   +-- [action] leastudios_mailer_ses_response
        |
        Email_Logger::log()
        |   +-- [filter] leastudios_mailer_before_log
        |
        +-- [action] leastudios_mailer_email_sent
```

### SNS webhook pipeline (Amazon SNS → WordPress)

```
Amazon SNS POST /wp-json/leastudios-mailer/v1/sns-webhook
    |
    SNS_Controller::verify_request()
        |
        +-- [filter] leastudios_mailer_sns_rate_limit
        +-- [filter] leastudios_mailer_sns_rate_window_seconds
        +-- [filter] leastudios_mailer_sns_max_age_seconds
        +-- [filter] leastudios_mailer_sns_future_skew_seconds
        |
        cryptographic SigV4 signature check (openssl_verify)
        |
    SNS_Controller::handle_notification()
        |
        Email_Logger::update_status()   (bounced / complained / delivered)
```

---

## 3. Development Setup

```bash
cd wp-content/plugins/leastudios-mailer
composer install        # one-time: installs PHPUnit, PHPCS, PHPStan, etc.
composer lint           # phpcs + phpstan
composer phpcs          # WordPress Coding Standards only
composer phpcbf         # auto-fix WPCS issues
composer phpstan        # PHPStan level 6, scans src/
composer test           # PHPUnit suite
```

PHPUnit runs against a real WordPress test library. Install it once (shared across all
suite plugins):

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh \
    wordpress_test root '' 127.0.0.1 latest
```

The shared scaffold, packaging script, and project-wide conventions live in
`leastudios-dev-tools`. The suite-wide lint + test playbook is in
`wp-content/plugins/TESTING.md`.

**Integration tests and AWS credentials:** the PHPUnit suite uses HTTP stubs (via
`WP_Http_TestCase` or `WpRemoteMock`) so no live AWS account is needed for the unit
suite. If you add integration tests that call the real SES or SNS APIs, export
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_REGION` before running them,
and guard the tests with `@group integration`.

---

## 4. Concepts

### Send pipeline

The sequence of WordPress filters and actions that the plugin applies to every outbound
`wp_mail()` call. The pipeline starts when `pre_wp_mail` fires and ends after
`leastudios_mailer_email_sent`. See Section 7 for the exact order.

### SES SigV4

Amazon SES v2 uses the AWS Signature Version 4 request-signing protocol. The plugin's
`SES/Signer` class computes the `Authorization` header for every HTTP request — no SDK
dependency required. Credentials (access key + secret) are stored encrypted at rest via
libsodium.

### Simple vs Raw send

SES exposes two content shapes: `Content.Simple` (separate HTML and text parts, managed
by SES) and `Content.Raw` (a complete RFC 5322 MIME message supplied by the caller).
The mailer uses `Content.Simple` for emails with no attachments and `Content.Raw` (with
the MIME message assembled by the PHPMailer library bundled with WordPress core) for
attachment-bearing emails. The two paths have mirrored request-body filters:
`leastudios_mailer_ses_request_body` (Simple) and `leastudios_mailer_ses_raw_request_body`
(Raw).

### SNS notification

An HTTP POST from Amazon Simple Notification Service to the plugin's webhook endpoint.
The plugin receives SNS notifications for bounce, complaint, and delivery events and uses
them to update log-row statuses. Replay attacks are blocked by a timestamp window
(filterable) and a per-IP rate limiter.

### Configuration set

An optional SES resource that groups sends for tracking and event publishing. Attach one
to sends via the `leastudios_mailer_ses_request_body` / `leastudios_mailer_ses_raw_request_body`
filters by adding `ConfigurationSetName` to the request body.

---

## 5. Data Model

### `{prefix}leastudios_mailer_log` table

Created by `Database/Migration.php` via `dbDelta()`. Current schema version: **3**
(tracked in the `leastudios_mailer_schema_version` option).

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned NOT NULL AUTO_INCREMENT` | Primary key |
| `to_email` | `text NOT NULL` | One or more recipient addresses (comma-separated for multi-recipient sends) |
| `from_email` | `varchar(255) NOT NULL DEFAULT ''` | Sender address (added in schema v2) |
| `subject` | `text NOT NULL` | Email subject (widened from `varchar(255)` in schema v3) |
| `status` | `varchar(20) NOT NULL DEFAULT 'sent'` | `sent`, `failed`, `delivered`, `bounced`, `complained` |
| `message_id` | `varchar(255) DEFAULT NULL` | SES message ID returned by the API |
| `error_message` | `text DEFAULT NULL` | Error detail on failure or SNS event description |
| `created_at` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` | Row insert time |
| `updated_at` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | Last status update (set by SNS webhook) |

Indexes: `PRIMARY KEY (id)`, `KEY status (status)`, `KEY message_id (message_id)`,
`KEY created_at (created_at)`, `KEY from_email (from_email)`.

**Read pattern:** `Email_Logger` is the only write path. For extension authors who want
to query the log, call `Migration::get_table_name()` to get the prefixed table name and
use `$wpdb->prepare()` for any parameterized queries.

### `leastudios_mailer_options` option

Stored by the WordPress Options API. Default shape:

```php
[
    'access_key'         => '',   // AWS Access Key ID — encrypted at rest
    'secret_key'         => '',   // AWS Secret Access Key — encrypted at rest
    'region'             => 'us-east-1',
    'from_email'         => '',   // Verified SES sender address
    'from_name'          => '',
    'enabled'            => false,
    'log_retention_days' => 30,
]
```

AWS credentials are encrypted with libsodium via `Encryption/Options_Encryptor` before
being saved to the database. Requires PHP `ext-sodium` and `ext-openssl`.

### `leastudios_mailer_schema_version` option

Integer tracking the current schema version (target: `3`). `Migration::maybe_migrate()`
compares this against the `SCHEMA_VERSION` constant and runs any pending incremental
steps. When changing the schema, bump `SCHEMA_VERSION` and add a new guarded `if
( $from_version < N )` step — never edit an existing step.

---

## 6. Hooks Reference

All hooks are prefixed `leastudios_mailer_`. Within each subject group, filters appear
before actions; within each type, entries are in alphabetical order.

---

### Send Pipeline Hooks

These hooks fire during a `wp_mail()` send, in the order shown in Section 7.

#### `leastudios_mailer_before_log`

- **Type:** Filter
- **Location:** `src/Log/Email_Logger.php`
- **Since:** 1.2.2
- **Description:** Fires before an email log entry is written to the database. Use it to
  modify log data (e.g. redact sensitive subjects or email addresses) or return `false`
  to suppress logging for certain emails entirely.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$log_data` | `array` | The log entry data with keys: `to_email` (string), `subject` (string), `status` (string — `sent`, `failed`, `delivered`, `bounced`, `complained`), `message_id` (string\|null), `error_message` (string\|null). |

**Returns:** `array|false` — The (optionally modified) log data to write, or `false` to
skip logging this send entirely.

**Example:**
```php
add_filter( 'leastudios_mailer_before_log', function ( array $log_data ) {
    // Don't log password reset emails (they contain tokens in subject).
    if ( str_contains( $log_data['subject'], 'Password Reset' ) ) {
        return false;
    }

    // Redact email addresses in the "to" field for GDPR.
    $log_data['to_email'] = preg_replace(
        '/(.{2})(.*)(@.*)/',
        '$1***$3',
        $log_data['to_email']
    );

    return $log_data;
}, 10, 1 );
```

---

#### `leastudios_mailer_pre_send`

- **Type:** Filter
- **Location:** `src/Email/Mailer.php`
- **Since:** 1.2.2
- **Description:** Fires just before the email is handed to the SES client. Use it to
  modify any part of the email (recipients, body, headers, attachment list) or return
  `null` to silently cancel delivery. Returning `null` drops the email entirely — the
  mailer short-circuits `pre_wp_mail`, so WordPress does **not** fall back to its default
  transport.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$args` | `array\|null` | The processed email arguments with keys: `from` (string), `to` (string[]), `subject` (string), `body_html` (string), `body_text` (string), `cc` (string[]), `bcc` (string[]), `reply_to` (string[]), `headers` (string\|array), `attachments` (array — validated entries as `[['name' => string, 'path' => string], ...]`). |
| `$atts` | `array` | The original `wp_mail()` arguments. |

**Returns:** `array|null` — The (optionally modified) args to send, or `null` to cancel
delivery.

**Example:**
```php
add_filter( 'leastudios_mailer_pre_send', function ( ?array $args, array $atts ): ?array {
    if ( null === $args ) {
        return null;
    }

    // Always BCC the compliance team.
    $args['bcc'][] = 'compliance@example.com';

    // Wrap plain-text emails in a simple HTML template.
    if ( '' === $args['body_html'] && '' !== $args['body_text'] ) {
        $args['body_html'] = '<html><body><pre>' . esc_html( $args['body_text'] ) . '</pre></body></html>';
    }

    return $args;
}, 10, 2 );
```

---

#### `leastudios_mailer_should_intercept`

- **Type:** Filter
- **Location:** `src/Email/Mailer.php`
- **Since:** 1.2.2
- **Description:** Determines whether a given email should be routed through Amazon SES.
  Return `false` to let WordPress handle the email with its default transport (e.g. PHP
  `mail()` or another plugin).

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$should_intercept` | `bool` | Whether the mailer should handle this email. Default `true`. |
| `$atts` | `array` | The original `wp_mail()` arguments (`to`, `subject`, `message`, `headers`, `attachments`). |

**Returns:** `bool` — `true` to route through SES; `false` to pass to WordPress default
transport.

**Example:**
```php
add_filter( 'leastudios_mailer_should_intercept', function ( bool $should_intercept, array $atts ): bool {
    // Let WooCommerce order emails bypass SES and use the default transport.
    if ( str_contains( $atts['subject'] ?? '', 'Your order' ) ) {
        return false;
    }
    return $should_intercept;
}, 10, 2 );
```

---

#### `leastudios_mailer_attachments_skipped`

- **Type:** Action
- **Location:** `src/Email/Mailer.php`
- **Since:** 1.2.2
- **Description:** Fires when one or more attachments supplied to `wp_mail()` cannot be
  read from disk and are therefore dropped before SES delivery. Valid, readable
  attachments are still sent — this action fires only for entries the mailer had to skip.
  Use it to log or alert when expected files are missing.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$skipped` | `array` | The attachment entries that were dropped, preserving their original keys. Each value is whatever was supplied in `$atts['attachments']` (typically a string path, but unexpected types are also captured here). |

**Example:**
```php
add_action( 'leastudios_mailer_attachments_skipped', function ( array $skipped ): void {
    foreach ( $skipped as $key => $value ) {
        error_log( sprintf(
            '[leaStudios Mailer] Unreadable attachment dropped: %s => %s',
            (string) $key,
            is_string( $value ) ? $value : gettype( $value )
        ) );
    }
} );
```

---

#### `leastudios_mailer_email_sent`

- **Type:** Action
- **Location:** `src/Email/Mailer.php`
- **Since:** 1.2.2
- **Description:** Fires after an email is sent (or fails) via SES. Runs after the log
  entry has been written. Use it for notifications, external logging, or retry logic.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$ses_result` | `array` | Result with keys: `success` (bool), `message_id` (string\|null), `error` (string\|null). |
| `$atts` | `array` | Original `wp_mail()` arguments. |
| `$status` | `string` | Log status: `sent` or `failed`. |

**Example:**
```php
add_action( 'leastudios_mailer_email_sent', function ( array $result, array $atts, string $status ): void {
    if ( ! $result['success'] ) {
        error_log( sprintf(
            '[leaStudios Mailer] Email to %s failed: %s',
            is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : $atts['to'],
            $result['error']
        ) );
    }
}, 10, 3 );
```

---

#### `leastudios_mailer_delivery_status`

- **Type:** Filter
- **Location:** `src/Plugin.php` (answered by `src/Log/Email_Logger.php`)
- **Since:** 1.3.0
- **Description:** The supported, public read path into the mailer log for sibling plugins
  and integrations. Pass the SES message ID captured from `leastudios_mailer_email_sent`
  (default value `null`) and the mailer returns the current delivery-status row for that
  message, or the unchanged default when the message ID is unknown. This is the **only**
  intended way for other plugins to read delivery status — they must not query the
  `{prefix}leastudios_mailer_log` table directly. Internally the mailer answers this filter
  via `Email_Logger::get_status_by_message_id()`.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$status` | `array{status: string, error_message: string}\|null` | The default to return when the message ID is unknown. Callers pass `null`. |
| `$message_id` | `string` | The SES message ID to look up. |

**Returns:** `array{status: string, error_message: string}|null` — The status row when the
message ID is found in the log, otherwise the passed-through default.

**Example:**
```php
// From a sibling plugin that recorded the message ID at send time.
$result = apply_filters( 'leastudios_mailer_delivery_status', null, $message_id );

if ( is_array( $result ) ) {
    printf( 'Delivery status: %s', esc_html( $result['status'] ) );
}
```

---

### SES Request Hooks

These hooks fire inside `SES/Client.php` when the mailer is constructing or sending the
SES API request.

#### `leastudios_mailer_max_message_bytes`

- **Type:** Filter
- **Location:** `src/SES/Client.php`
- **Since:** 1.2.2
- **Description:** Caps the size of an attachment-bearing email. After the RFC 5322 MIME
  message is assembled, its byte length is compared against this limit; a message over
  the limit is **not** sent — `send_raw_email()` returns a failure and the email is
  logged as `failed` with an error of the form `Message size (X) exceeds the Y limit;
  not sent to SES.` This turns an oversized send into a clear, early failure instead of
  an opaque SES API rejection. Applies to the Raw (attachment) send path only — the
  no-attachment `Content.Simple` path is not checked.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$max_bytes` | `int` | The maximum size, in bytes, of a message sent through SES. Default `40 * MB_IN_BYTES` (40 MB, Amazon SES's documented limit). Return `0` or less to disable the check. |

**Returns:** `int` — The byte cap to enforce; `0` or less disables the guard.

**Example:**
```php
add_filter( 'leastudios_mailer_max_message_bytes', function ( int $max_bytes ): int {
    // Tighten the cap to 10 MB.
    return 10 * MB_IN_BYTES;
} );
```

---

#### `leastudios_mailer_ses_max_attempts`

- **Type:** Filter
- **Location:** `src/SES/Client.php`
- **Since:** 1.2.2
- **Description:** Controls how many times the SES client will attempt a request before
  giving up. Retries happen only on *transient* failures — a `WP_Error` from
  `wp_remote_post()` (connection, timeout, DNS) or an HTTP `429` / `5xx` response. Other
  `4xx` responses (bad credentials, unverified sender, malformed request) are
  configuration errors and fail immediately without retry. The returned value is clamped
  to the range `1`–`5`; return `1` to disable retries entirely.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$max_attempts` | `int` | Total number of attempts (initial request + retries) for a single SES API call. Default `3`. Clamped to `1`–`5`. |

**Returns:** `int` — The total attempt budget (clamped to 1–5 internally).

**Example:**
```php
add_filter( 'leastudios_mailer_ses_max_attempts', function ( int $max_attempts ): int {
    // Be more persistent on a flaky network — clamped to 5 internally.
    return 5;
} );
```

---

#### `leastudios_mailer_ses_request_body`

- **Type:** Filter
- **Location:** `src/SES/Client.php`
- **Since:** 1.2.2
- **Description:** Filter the SES API request payload before it is signed and sent. Fires
  only on the no-attachment send path, where SES `Content.Simple` is used. For emails
  carrying attachments, see `leastudios_mailer_ses_raw_request_body` — the two filters
  mirror each other.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$body` | `array` | The decoded JSON request body for the SES v2 `SendEmail` API call. Contains keys like `FromEmailAddress`, `Destination`, `Content`, and optionally `ReplyToAddresses`. |
| `$from` | `string` | The sender address. |
| `$to` | `string[]` | The recipient addresses. |
| `$subject` | `string` | The email subject. |

**Returns:** `array` — The (optionally modified) SES v2 `SendEmail` request body.

**Example:**
```php
add_filter( 'leastudios_mailer_ses_request_body', function ( array $body, string $from, array $to, string $subject ): array {
    // Attach a SES configuration set for tracking.
    $body['ConfigurationSetName'] = 'my-tracking-config';

    // Add email tags for SES event categorisation.
    $body['EmailTags'] = [
        [
            'Name'  => 'environment',
            'Value' => wp_get_environment_type(),
        ],
        [
            'Name'  => 'source',
            'Value' => 'wordpress',
        ],
    ];

    return $body;
}, 10, 4 );
```

---

#### `leastudios_mailer_ses_raw_request_body`

- **Type:** Filter
- **Location:** `src/SES/Client.php`
- **Since:** 1.2.2
- **Description:** Mirror of `leastudios_mailer_ses_request_body` for the Raw
  (attachment-bearing) send path. Use it to attach configuration sets, tags, or other
  top-level SES options when the email carries one or more attachments. Note that the
  MIME message itself is already encoded — modify `Content.Raw.Data` only if you know
  what you're doing (it must remain a valid base64-encoded RFC 5322 message).

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$body` | `array` | The decoded JSON request body for the SES v2 `SendEmail` API call when sending with attachments. Contains `FromEmailAddress`, `Destination`, `Content.Raw.Data` (a base64-encoded RFC 5322 MIME message), and optionally `ReplyToAddresses`. |
| `$from_email` | `string` | The sender address. |
| `$to` | `string[]` | The recipient addresses. |
| `$subject` | `string` | The email subject. |

**Returns:** `array` — The (optionally modified) SES v2 `SendEmail` request body for the
Raw send path.

**Example:**
```php
add_filter( 'leastudios_mailer_ses_raw_request_body', function ( array $body, string $from, array $to, string $subject ): array {
    $body['ConfigurationSetName'] = 'my-tracking-config';
    return $body;
}, 10, 4 );
```

---

#### `leastudios_mailer_ses_retry_delay_ms`

- **Type:** Filter
- **Location:** `src/SES/Client.php`
- **Since:** 1.2.2
- **Description:** Sets the base delay for the exponential-backoff-with-jitter wait
  between retry attempts. The delay before retry *n* is `base × 2^(n−1)` plus random
  "full jitter" of `0`–`base/2` ms — so with the `500` default, waits land around
  0.5 s, 1 s, 2 s, …. The jitter spreads retries out so multiple failing sends don't
  all hammer SES in lockstep. Negative values are floored to `0`. Has no effect when
  `leastudios_mailer_ses_max_attempts` is `1`.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$base_delay_ms` | `int` | Base backoff delay, in milliseconds, between SES retry attempts. Default `500`. Negative values are floored to `0`. |

**Returns:** `int` — The base backoff delay in milliseconds.

**Example:**
```php
add_filter( 'leastudios_mailer_ses_retry_delay_ms', function ( int $base_delay_ms ): int {
    // Slower, gentler backoff.
    return 1000;
} );
```

---

#### `leastudios_mailer_ses_response`

- **Type:** Action
- **Location:** `src/SES/Client.php`
- **Since:** 1.2.2
- **Description:** Fires immediately after the SES API response is received, before the
  result is returned to the mailer. Useful for low-level debugging, metrics collection,
  or forwarding results to external monitoring services. When retries occur (see
  `leastudios_mailer_ses_max_attempts`), this fires **once** with the final result — not
  once per attempt.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$response` | `array` | The result array with keys: `success` (bool), `message_id` (string\|null), `error` (string\|null). |
| `$url` | `string` | The SES API endpoint URL that was called. |
| `$body` | `string` | The JSON request body that was sent. |

**Example:**
```php
add_action( 'leastudios_mailer_ses_response', function ( array $response, string $url, string $body ): void {
    // Send SES delivery metrics to a custom endpoint.
    wp_remote_post( 'https://metrics.example.com/ses', [
        'body'     => wp_json_encode( [
            'success'    => $response['success'],
            'message_id' => $response['message_id'],
            'error'      => $response['error'],
            'region'     => wp_parse_url( $url, PHP_URL_HOST ),
            'timestamp'  => gmdate( 'c' ),
        ] ),
        'headers'  => [ 'Content-Type' => 'application/json' ],
        'blocking' => false,
    ] );
}, 10, 3 );
```

---

### SNS Webhook Hooks

These filters fire only on the inbound delivery-tracking webhook
(`POST /wp-json/leastudios-mailer/v1/sns-webhook`) when Amazon SNS posts a
bounce/complaint/delivery notification. They are independent of the `wp_mail()` send
pipeline.

#### `leastudios_mailer_sns_future_skew_seconds`

- **Type:** Filter
- **Location:** `src/Webhook/SNS_Controller.php`
- **Since:** 1.2.2
- **Description:** The forward half of the SNS webhook's replay window. A small tolerance
  prevents legitimate notifications from being rejected when the local server clock lags
  behind AWS; notifications timestamped further ahead than this are rejected. Like
  `leastudios_mailer_sns_max_age_seconds`, this applies to the inbound delivery-tracking
  webhook.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$future_skew_seconds` | `int` | How far into the future, in seconds, an SNS notification's `Timestamp` may be and still be accepted. Default `300` (five minutes). |

**Returns:** `int` — The allowed forward-skew tolerance in seconds.

**Example:**
```php
add_filter( 'leastudios_mailer_sns_future_skew_seconds', function ( int $future_skew_seconds ): int {
    // Allow more slack on a server with known clock drift.
    return 10 * MINUTE_IN_SECONDS;
} );
```

---

#### `leastudios_mailer_sns_max_age_seconds`

- **Type:** Filter
- **Location:** `src/Webhook/SNS_Controller.php`
- **Since:** 1.2.2
- **Description:** Part of the SNS webhook's replay protection. A notification whose
  `Timestamp` is older than this window is rejected during signature verification, so a
  captured notification body cannot be replayed against the endpoint indefinitely. This
  filter applies to the **inbound** delivery-tracking webhook, not the `wp_mail()` send
  pipeline.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$max_age_seconds` | `int` | Maximum age, in seconds, of an SNS notification the webhook will accept. Default `3600` (one hour). |

**Returns:** `int` — The maximum accepted age in seconds.

**Example:**
```php
add_filter( 'leastudios_mailer_sns_max_age_seconds', function ( int $max_age_seconds ): int {
    // Tighten the replay window to 15 minutes.
    return 15 * MINUTE_IN_SECONDS;
} );
```

---

#### `leastudios_mailer_sns_rate_limit`

- **Type:** Filter
- **Location:** `src/Webhook/SNS_Controller.php`
- **Since:** 1.2.2
- **Description:** Caps how many requests a single IP may make to the SNS
  delivery-tracking webhook within the rate-limit window. The check runs before signature
  verification, so a flood of junk requests cannot drive repeated signing-certificate
  fetches or signature checks. A request over the limit receives HTTP `429`. Applies to
  the inbound webhook only, not the `wp_mail()` send pipeline.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$limit` | `int` | Maximum number of webhook requests accepted per window, per client IP. Default `120`. Return `0` (or less) to disable rate limiting entirely. |

**Returns:** `int` — The per-window, per-IP request cap; `0` or less disables limiting.

**Example:**
```php
add_filter( 'leastudios_mailer_sns_rate_limit', function ( int $limit ): int {
    // Tighten to 30 requests per window.
    return 30;
} );
```

---

#### `leastudios_mailer_sns_rate_window_seconds`

- **Type:** Filter
- **Location:** `src/Webhook/SNS_Controller.php`
- **Since:** 1.2.2
- **Description:** Sets the window over which `leastudios_mailer_sns_rate_limit` requests
  are counted. With the defaults, an IP may make 120 webhook requests per 60 seconds.
  Applies to the inbound delivery-tracking webhook only.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$window_seconds` | `int` | Length of the rate-limit window, in seconds. Default `60`. |

**Returns:** `int` — The rate-limit window duration in seconds.

**Example:**
```php
add_filter( 'leastudios_mailer_sns_rate_window_seconds', function ( int $window_seconds ): int {
    // Count requests over a 5-minute window instead of 1 minute.
    return 5 * MINUTE_IN_SECONDS;
} );
```

---

### Admin & Lifecycle Hooks

These hooks fire in the admin layer (`Settings_Page`) or during plugin bootstrap
(`Plugin::init()`).

#### `leastudios_mailer_settings_tabs`

- **Type:** Filter
- **Location:** `src/Admin/Settings_Page.php`
- **Since:** 1.2.2
- **Description:** Filter the tabs displayed on the mailer settings page. Add your own
  tabs, remove existing ones, or reorder them. To render content for a custom tab, hook
  into the dynamic action `leastudios_mailer_settings_tab_{$slug}`.

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$tabs` | `array` | Associative array of tab slug => label. Default tabs: `configuration` => `"Configuration"`, `email-log` => `"Email Log"`, `test-email` => `"Test Email"`. |

**Returns:** `array` — The (optionally modified) tab slug => label map.

**Example:**
```php
// Register a custom "Analytics" tab.
add_filter( 'leastudios_mailer_settings_tabs', function ( array $tabs ): array {
    $tabs['analytics'] = __( 'Analytics', 'my-plugin' );
    return $tabs;
} );

// Render the custom tab's content.
add_action( 'leastudios_mailer_settings_tab_analytics', function () {
    echo '<h2>Email Analytics</h2>';
    echo '<p>Your custom analytics dashboard goes here.</p>';
} );
```

---

#### `leastudios_mailer_initialized`

- **Type:** Action
- **Location:** `src/Plugin.php`
- **Since:** 1.2.2
- **Description:** Fires after all mailer components (SES client, logger, admin settings,
  webhook controller, and cron schedules) have been wired up. Use this to safely interact
  with the mailer knowing that all services are available.

**Parameters:** *(none)*

**Example:**
```php
add_action( 'leastudios_mailer_initialized', function (): void {
    // Register a custom webhook or extend the mailer once it's fully loaded.
    if ( class_exists( \MyPlugin\SES_Extension::class ) ) {
        ( new \MyPlugin\SES_Extension() )->bootstrap();
    }
} );
```

---

#### `leastudios_mailer_settings_tab_{$slug}`

- **Type:** Action (dynamic)
- **Location:** `src/Admin/Settings_Page.php`
- **Since:** 1.2.2
- **Description:** Fires when a custom tab (registered via the
  `leastudios_mailer_settings_tabs` filter) is the active tab. Use this to render the
  tab's HTML content. This action only fires for tabs that are not one of the three
  built-in tabs (`configuration`, `email-log`, `test-email`).

**Parameters:**

| Parameter | Type | Description |
|---|---|---|
| `$active_tab` | `string` | The current tab slug. |

**Example:**
```php
add_action( 'leastudios_mailer_settings_tab_analytics', function ( string $tab ): void {
    ?>
    <h2><?php esc_html_e( 'Email Analytics', 'my-plugin' ); ?></h2>
    <p><?php esc_html_e( 'Delivery statistics for the last 30 days.', 'my-plugin' ); ?></p>
    <!-- Your analytics markup here -->
    <?php
} );
```

---

## 7. Hook Execution Order

### Send pipeline (`wp_mail` → SES)

For a typical `wp_mail()` call with SES enabled, hooks fire in this order:

```
wp_mail()
    |
    pre_wp_mail (priority 10 — Mailer intercepts)
        |
        +-- [filter] leastudios_mailer_should_intercept
        |       false → hand back to WordPress default transport; remaining hooks do not fire
        |
        wp_mail_from / wp_mail_from_name (core WordPress filters)
        |
        +-- [action] leastudios_mailer_attachments_skipped
        |       (only when unreadable attachment paths are dropped)
        |
        +-- [filter] leastudios_mailer_pre_send
        |       null → cancel delivery silently; ses_* hooks do not fire
        |
        SES\Client (no-attachment path — Content.Simple):
        |   +-- [filter] leastudios_mailer_ses_request_body
        |   +-- [filter] leastudios_mailer_ses_max_attempts    (read once before first attempt)
        |   +-- [filter] leastudios_mailer_ses_retry_delay_ms  (read once before first attempt)
        |
        SES\Client (attachment path — Content.Raw / RFC 5322 MIME):
        |   +-- [filter] leastudios_mailer_max_message_bytes   (read before MIME assembly)
        |   |       over limit → return failure; ses_raw_request_body does not fire
        |   +-- [filter] leastudios_mailer_ses_raw_request_body
        |   +-- [filter] leastudios_mailer_ses_max_attempts
        |   +-- [filter] leastudios_mailer_ses_retry_delay_ms
        |
        SES API request (retries on transient errors per ses_max_attempts budget)
        |
        +-- [action] leastudios_mailer_ses_response
        |       (fires once with final result, even when retries occurred)
        |
        Email_Logger::log()
        |   +-- [filter] leastudios_mailer_before_log
        |           false → skip writing the log row
        |
        +-- [action] leastudios_mailer_email_sent
```

| Order | Hook | Type | Trigger |
|---|---|---|---|
| 1 | `leastudios_mailer_should_intercept` | Filter | Before any email processing begins |
| 2 | `leastudios_mailer_attachments_skipped` | Action | When one or more attachments cannot be read from disk |
| 3 | `leastudios_mailer_pre_send` | Filter | After attachment validation, before SES client |
| 4a | `leastudios_mailer_ses_request_body` | Filter | No-attachment path only, before signing |
| 4b | `leastudios_mailer_max_message_bytes` | Filter | Attachment path only, before MIME assembly |
| 4c | `leastudios_mailer_ses_raw_request_body` | Filter | Attachment path only, after size check |
| 5 | `leastudios_mailer_ses_max_attempts` | Filter | Once before first SES attempt |
| 6 | `leastudios_mailer_ses_retry_delay_ms` | Filter | Once before first SES attempt |
| 7 | `leastudios_mailer_ses_response` | Action | After final SES API response |
| 8 | `leastudios_mailer_before_log` | Filter | Before log row is written |
| 9 | `leastudios_mailer_email_sent` | Action | After log row is written |

### SNS webhook pipeline

The SNS webhook filters fire only when Amazon SNS posts to
`POST /wp-json/leastudios-mailer/v1/sns-webhook`. They are entirely independent of the
`wp_mail()` send pipeline.

| Order | Hook | Type | Trigger |
|---|---|---|---|
| 1 | `leastudios_mailer_sns_rate_limit` | Filter | Before signature verification (rate-limit check) |
| 2 | `leastudios_mailer_sns_rate_window_seconds` | Filter | Before signature verification (rate-limit check) |
| 3 | `leastudios_mailer_sns_max_age_seconds` | Filter | During signature verification (replay window) |
| 4 | `leastudios_mailer_sns_future_skew_seconds` | Filter | During signature verification (replay window) |

### Admin & lifecycle hooks

| Hook | Type | Trigger |
|---|---|---|
| `leastudios_mailer_initialized` | Action | After all components are wired; fires on every `plugins_loaded` when plugin is active |
| `leastudios_mailer_settings_tabs` | Filter | On settings page load, before tabs are rendered |
| `leastudios_mailer_settings_tab_{$slug}` | Action | When a custom (non-built-in) settings tab is active |

---

## 8. REST API Reference

Namespace: `leastudios-mailer/v1`

| Method | Route | Description | Auth |
|---|---|---|---|
| POST | `/sns-webhook` | Receive an Amazon SNS bounce/complaint/delivery notification | SNS signature (no WP auth) |

### `POST /sns-webhook`

- **Endpoint:** `/wp-json/leastudios-mailer/v1/sns-webhook`
- **Controller:** `src/Webhook/SNS_Controller.php`
- **Auth:** No WordPress authentication. The endpoint verifies the cryptographic SNS
  signature (SigV4, RSA/SHA1 or RSA/SHA256 depending on `SignatureVersion`). The signing
  certificate is fetched from the AWS-hosted URL embedded in the payload and cached in a
  transient for one hour.
- **Rate limiting:** Per-IP fixed-window counter (default 120 req/60 s). Filterable via
  `leastudios_mailer_sns_rate_limit` and `leastudios_mailer_sns_rate_window_seconds`.
- **Request body (SNS envelope):**

  ```json
  {
    "Type": "Notification",
    "MessageId": "abc-123",
    "TopicArn": "arn:aws:sns:us-east-1:123456789:MyTopic",
    "Subject": "Amazon SES Email Event Notification",
    "Message": "{\"notificationType\":\"Bounce\",\"mail\":{\"messageId\":\"ses-msg-id\"},\"bounce\":{\"bounceType\":\"Permanent\"}}",
    "Timestamp": "2026-05-24T00:00:00.000Z",
    "SignatureVersion": "2",
    "Signature": "<base64>",
    "SigningCertURL": "https://sns.us-east-1.amazonaws.com/SimpleNotificationService-xxx.pem",
    "UnsubscribeURL": "https://sns.us-east-1.amazonaws.com/?Action=Unsubscribe&..."
  }
  ```

- **Supported notification types (inside `Message`):**
  - `Bounce` — updates matching log row status to `bounced`
  - `Complaint` — updates matching log row status to `complained`
  - `Delivery` — updates matching log row status to `delivered`
  - `SubscriptionConfirmation` — auto-confirms the SNS subscription by calling `SubscribeURL` (validated to be an `sns.*.amazonaws.com` host)

- **Response (200):**

  ```json
  { "status": "ok" }
  ```

- **Error responses:**
  - `400` — missing or malformed SNS envelope
  - `403` — signature verification failed
  - `429` — rate limit exceeded

- **Example (Stripe CLI / local testing):**

  ```bash
  # Subscribe the local site to an SNS topic (replace with your ngrok/Herd URL):
  # aws sns subscribe \
  #   --topic-arn arn:aws:sns:us-east-1:123456789:ses-events \
  #   --protocol https \
  #   --notification-endpoint https://leastudios-plugins.test/wp-json/leastudios-mailer/v1/sns-webhook

  # The plugin auto-confirms the subscription when SNS posts the SubscriptionConfirmation message.
  ```

---

## 9. Extension Recipes

### How do I send an attachment-bearing email through the mailer?

**Goal:** Forward file attachments from a WordPress `wp_mail()` call through the Amazon
SES Raw send path.

**Hooks used:** `leastudios_mailer_attachments_skipped`, `leastudios_mailer_pre_send`.

**Walkthrough:** Attachment support is built in — no additional hooks are required for the
happy path. Pass attachments to `wp_mail()` in the same two forms WordPress core accepts:
an indexed array of absolute paths (display name is derived from `basename()`) or a keyed
array with explicit display names (supported since WP 5.6).

The mailer validates each path before building the MIME message. Files that do not exist
or are not readable at send time are silently dropped from the SES delivery,
recorded in the Email Log's error column, and reported via
`leastudios_mailer_attachments_skipped`. The remaining valid files are still sent —
delivery never fails just because one attachment was unreadable.

Use `leastudios_mailer_pre_send` to inspect or modify the validated `attachments` list
(as `[['name' => string, 'path' => string], ...]`) before the email is sent. Use
`leastudios_mailer_attachments_skipped` to alert on missing files.

**Complete example:**

```php
// 1. Send with attachments — works out of the box.
wp_mail(
    'recipient@example.com',
    'Monthly Report',
    'Please find this month\'s report attached.',
    '',
    [
        // Indexed: display name from basename().
        '/var/www/reports/2026-05.pdf',
        // Keyed (WP 5.6+): explicit display name.
        'Executive Summary.pdf' => '/var/www/reports/exec-summary-2026-05.pdf',
    ]
);

// 2. Alert when an expected attachment was unreadable.
add_action( 'leastudios_mailer_attachments_skipped', function ( array $skipped ): void {
    foreach ( $skipped as $key => $value ) {
        // Notify an on-call Slack channel or error-monitoring service.
        wp_remote_post( 'https://hooks.slack.com/services/T.../B.../xxx', [
            'body'    => wp_json_encode( [
                'text' => sprintf(
                    ':warning: leaStudios Mailer: attachment could not be read — `%s`',
                    is_string( $value ) ? $value : gettype( $value )
                ),
            ] ),
            'headers' => [ 'Content-Type' => 'application/json' ],
        ] );
    }
} );

// 3. Swap an attachment for a freshly-generated version just before send.
add_filter( 'leastudios_mailer_pre_send', function ( ?array $args, array $atts ): ?array {
    if ( null === $args ) {
        return null;
    }

    // Replace the first attachment with a signed URL version if available.
    foreach ( $args['attachments'] as &$attachment ) {
        if ( str_ends_with( $attachment['name'], '.pdf' ) ) {
            $signed_path = my_plugin_get_signed_pdf( $attachment['path'] );
            if ( null !== $signed_path ) {
                $attachment['path'] = $signed_path;
            }
        }
    }
    unset( $attachment );

    return $args;
}, 10, 2 );
```

---

### How do I tag SES sends with a configuration set?

**Goal:** Attach an SES configuration set (for open/click tracking, event publishing) to
every outbound email.

**Hooks used:** `leastudios_mailer_ses_request_body`, `leastudios_mailer_ses_raw_request_body`.

**Walkthrough:** SES configuration sets are attached at the top level of the
`SendEmail` API request body, not inside the message content itself. Because the mailer
uses two different send paths — `Content.Simple` (no attachments) and `Content.Raw`
(attachments) — you must hook both filters to ensure all sends are tagged.

Both filters receive the same four parameters and expect the same array shape back. The
only structural difference is that `ses_raw_request_body` has `Content.Raw.Data` (base64
MIME) instead of `Content.Simple`.

**Complete example:**

```php
/**
 * Attach a configuration set + email tags to both send paths.
 */
function my_plugin_tag_ses_body( array $body ): array {
    $body['ConfigurationSetName'] = 'wordpress-transactional';
    $body['EmailTags']            = [
        [
            'Name'  => 'environment',
            'Value' => wp_get_environment_type(),
        ],
        [
            'Name'  => 'site',
            'Value' => sanitize_title( get_bloginfo( 'name' ) ),
        ],
    ];

    return $body;
}

// Simple path (no attachments).
add_filter( 'leastudios_mailer_ses_request_body', 'my_plugin_tag_ses_body' );

// Raw path (with attachments).
add_filter( 'leastudios_mailer_ses_raw_request_body', 'my_plugin_tag_ses_body' );
```

---

### How do I add a custom settings tab to the Mailer admin page?

**Goal:** Surface plugin-specific configuration alongside the built-in Mailer tabs
without a separate admin menu item.

**Hooks used:** `leastudios_mailer_settings_tabs`, `leastudios_mailer_settings_tab_{$slug}`.

**Walkthrough:** The settings page renders tabs in the order they appear in the `$tabs`
array. Register your tab with `leastudios_mailer_settings_tabs`, then render its content
with the matching dynamic action. The action name is `leastudios_mailer_settings_tab_`
followed by your slug exactly as it appears in the `$tabs` array.

The dynamic action fires only when your tab is active. Always escape output in the action
callback; the settings page does not wrap the content in any sanitizing layer.

**Complete example:**

```php
// 1. Register the tab.
add_filter( 'leastudios_mailer_settings_tabs', function ( array $tabs ): array {
    $tabs['my-plugin-log'] = __( 'My Plugin Log', 'my-plugin' );
    return $tabs;
} );

// 2. Render the tab content.
add_action( 'leastudios_mailer_settings_tab_my-plugin-log', function ( string $active_tab ): void {
    $entries = my_plugin_get_recent_log_entries( 50 );
    ?>
    <h2><?php esc_html_e( 'Recent Activity', 'my-plugin' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Time', 'my-plugin' ); ?></th>
                <th><?php esc_html_e( 'Event', 'my-plugin' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $entries as $entry ) : ?>
            <tr>
                <td><?php echo esc_html( $entry['time'] ); ?></td>
                <td><?php echo esc_html( $entry['event'] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
} );
```

---

### How do I redact sensitive subjects from the email log?

**Goal:** Prevent password-reset tokens and other sensitive strings from appearing in the
Email Log visible to admin users.

**Hooks used:** `leastudios_mailer_before_log`.

**Walkthrough:** `leastudios_mailer_before_log` receives the full log data array before
it is inserted into the database. You can modify any field (including `subject`,
`to_email`, or `error_message`) or return `false` to suppress the row entirely.

Returning `false` means no log entry is written at all — use this sparingly, as it breaks
the audit trail. Prefer replacing sensitive content with a placeholder so the send is
still recorded.

**Complete example:**

```php
add_filter( 'leastudios_mailer_before_log', function ( array $log_data ) {
    // Suppress logging entirely for password-reset emails.
    if ( str_contains( $log_data['subject'], '[Password Reset]' ) ) {
        return false;
    }

    // Redact the subject for account-verification emails.
    if ( str_contains( $log_data['subject'], 'Verify your email' ) ) {
        $log_data['subject'] = '[redacted — account verification token]';
    }

    // Partially mask recipient addresses for GDPR compliance.
    $log_data['to_email'] = preg_replace(
        '/(.{2})([^@]*)(@.*)/',
        '$1***$3',
        $log_data['to_email']
    );

    return $log_data;
}, 10, 1 );
```

---

### How do I tighten SES retry behavior?

**Goal:** Reduce the number of retries and shorten the backoff delay for time-sensitive
transactional emails, or increase them for batch sends where throughput matters less than
delivery.

**Hooks used:** `leastudios_mailer_ses_max_attempts`, `leastudios_mailer_ses_retry_delay_ms`.

**Walkthrough:** The SES client retries on transient failures (connection errors, HTTP
`429`, HTTP `5xx`). Permanent `4xx` errors (bad credentials, unverified sender) fail
immediately without retry regardless of `ses_max_attempts`.

The attempt count is clamped to `1`–`5` internally; return `1` to disable retries. The
delay is floored to `0`; return `0` to retry with no wait. Use a high attempt count with
a long delay for resilient batch sends, or a low count with no delay for real-time
transactional emails where a fast failure beats a slow one.

**Complete example:**

```php
// For time-sensitive OTP / login emails: fail fast.
add_filter( 'leastudios_mailer_ses_max_attempts', function ( int $max_attempts ): int {
    // Only the initial attempt; no retries.
    return 1;
} );

// For nightly digest / batch emails: be persistent.
add_filter( 'leastudios_mailer_ses_max_attempts', function ( int $max_attempts ): int {
    return 5; // Capped internally at 5.
} );

add_filter( 'leastudios_mailer_ses_retry_delay_ms', function ( int $base_delay_ms ): int {
    return 2000; // 2 s base, ~2 s / 4 s / 8 s with jitter.
} );
```

---

### How do I bypass the mailer for a specific transactional email?

**Goal:** Let a particular email type skip SES and use WordPress's default transport
(e.g. to route it through a different plugin or PHP `mail()`).

**Hooks used:** `leastudios_mailer_should_intercept`, `leastudios_mailer_pre_send`.

**Walkthrough:** `leastudios_mailer_should_intercept` is the earliest and cleanest exit
point — returning `false` hands the email back to WordPress before any processing occurs.
Use it when you can identify the email from its `wp_mail()` arguments alone (subject,
recipients, headers).

If you need to inspect the fully-processed args (e.g. the validated attachment list),
return `null` from `leastudios_mailer_pre_send` instead. Note that returning `null` from
`pre_send` silently drops the email — it does **not** fall back to the default transport.
So use `should_intercept → false` for bypass, and `pre_send → null` only for outright
cancellation.

**Complete example:**

```php
// Bypass SES for all emails sent to @internal.example.com.
add_filter( 'leastudios_mailer_should_intercept', function ( bool $should_intercept, array $atts ): bool {
    $to = is_array( $atts['to'] ) ? $atts['to'] : [ $atts['to'] ];

    foreach ( $to as $address ) {
        if ( str_ends_with( trim( $address ), '@internal.example.com' ) ) {
            return false; // Let WordPress handle it with the default transport.
        }
    }

    return $should_intercept;
}, 10, 2 );

// Cancel (not bypass) an email whose subject indicates a duplicate send.
add_filter( 'leastudios_mailer_pre_send', function ( ?array $args, array $atts ): ?array {
    if ( null === $args ) {
        return null;
    }

    $dedup_key = 'mailer_dedup_' . md5( $args['subject'] . implode( ',', $args['to'] ) );

    if ( get_transient( $dedup_key ) ) {
        return null; // Drop — already sent within the last 5 minutes.
    }

    set_transient( $dedup_key, 1, 5 * MINUTE_IN_SECONDS );

    return $args;
}, 10, 2 );
```

---

## 10. Testing

```bash
composer test                                          # full PHPUnit suite
vendor/bin/phpunit --filter MailerTest                 # one test class
vendor/bin/phpunit tests/SignerTest.php                # one test file
```

PHPUnit requires the shared WordPress test library (drop into `/tmp/wordpress-tests-lib/`
— install once per machine):

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh \
    wordpress_test root '' 127.0.0.1 latest
```

**Stubbing HTTP in tests:** the test suite uses WordPress's `WP_Http_TestCase` or a
`pre_http_request` filter to intercept `wp_remote_post()` / `wp_remote_get()` calls. No
live AWS account or SNS endpoint is needed for unit tests. To write a test that exercises
the SES client:

```php
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
    if ( str_contains( $url, 'amazonaws.com' ) ) {
        return [
            'response' => [ 'code' => 200 ],
            'body'     => wp_json_encode( [ 'MessageId' => 'test-message-id' ] ),
            'headers'  => [],
        ];
    }
    return $preempt;
}, 10, 3 );
```

**Testing the SNS webhook:** post a signed SNS envelope to
`/wp-json/leastudios-mailer/v1/sns-webhook` using the AWS CLI or a local stub. In unit
tests, call `SNS_Controller::handle_notification()` directly with a parsed payload array
after bypassing `verify_request()`.

---

## 11. Release Process

This plugin uses a tag-triggered release workflow (`.github/workflows/release.yml`) that
auto-generates release notes from the commit log between the previous and current tag.

**To cut a release:** bump the `Version:` header in `leastudios-mailer.php`, commit,
then:

```bash
git tag vX.Y.Z && git push origin vX.Y.Z
```

The workflow verifies the tag matches the header, builds the zip with
`composer install --no-dev`, and publishes the GitHub release.

**Commit-prefix → release-notes section:**

- `feat:` → `## Added`
- `fix:` → `## Fixed`
- `refactor:` → `## Changed`
- `perf:` → `## Performance`

**Hidden from release notes** (use for internal changes): `ci:`, `chore:`, `docs:`,
`test:`, `style:`, `build:`, `release:`.

The subject text after the prefix becomes the bullet verbatim, with the first letter
capitalized. To override auto-notes for a specific release, edit the body in the GitHub
UI after publish.

---

## 12. Where to Read More

- [`CLAUDE.md`](../CLAUDE.md) — this plugin's repo conventions and gotchas (shared-by-duplication classes, PHPStan `treatPhpDocTypesAsCertain`, graceful degradation rules).
- [`README.md`](../README.md) — user-facing overview and installation instructions.
- [`leastudios-dev-tools/CLAUDE.md`](../../leastudios-dev-tools/CLAUDE.md) — suite-wide coding standards, security rules (escape/sanitize/nonce/capability), and database/REST/i18n conventions inherited by every plugin.
- [`leastudios-email-templates` developer handbook](../../leastudios-email-templates/docs/developer-handbook.md) — how branded wrapping hooks into the mailer pipeline.
- [`leastudios-forms` developer handbook](../../leastudios-forms/docs/developer-handbook.md) — how form notifications benefit from per-submission delivery tracking.
- [Amazon SES v2 API reference](https://docs.aws.amazon.com/ses/latest/APIReference-V2/) — the upstream API this plugin wraps.
- [Amazon SNS message signing](https://docs.aws.amazon.com/sns/latest/dg/sns-verify-signature-of-message.html) — how the webhook's signature verification works.
