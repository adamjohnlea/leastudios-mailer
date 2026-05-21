# leaStudios Mailer — Developer Handbook

## Hooks Reference

All hooks are prefixed with `leastudios_mailer_` and are designed to let developers customise every stage of the pipeline — from interception, through SES delivery (including retry behaviour), to logging, the inbound delivery-tracking webhook, and the admin UI.

---

### Filters

#### `leastudios_mailer_should_intercept`

**Type:** Filter
**Location:** `src/Email/Mailer.php`
**Parameters:**
- `$should_intercept` *(bool)* — Whether the mailer should handle this email. Default `true`.
- `$atts` *(array)* — The original `wp_mail()` arguments (`to`, `subject`, `message`, `headers`, `attachments`).

**Description:** Determines whether a given email should be routed through Amazon SES. Return `false` to let WordPress handle the email with its default transport (e.g. PHP `mail()` or another plugin).

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

#### `leastudios_mailer_pre_send`

**Type:** Filter
**Location:** `src/Email/Mailer.php`
**Parameters:**
- `$args` *(array)* — The processed email arguments with the following keys:
  - `from` *(string)* — Formatted sender, e.g. `"Name <email@example.com>"`.
  - `to` *(string[])* — Recipient email addresses.
  - `subject` *(string)* — Email subject line.
  - `body_html` *(string)* — HTML body (empty string if plain text).
  - `body_text` *(string)* — Plain text body (empty string if HTML).
  - `cc` *(string[])* — CC addresses.
  - `bcc` *(string[])* — BCC addresses.
  - `reply_to` *(string[])* — Reply-To addresses.
  - `headers` *(string|array)* — Original raw headers from `wp_mail()`.
  - `attachments` *(array)* — Validated attachments as `[ ['name' => string, 'path' => string], ... ]`. Empty when none were supplied or all were unreadable. Modifying this array changes which files are sent (see below).
- `$atts` *(array)* — The original `wp_mail()` arguments.

**Return:** Filtered `$args` array, or `null` to skip sending entirely.

**Description:** Fires just before the email is handed to the SES client. Use it to modify any part of the email (recipients, body, headers) or return `null` to silently cancel delivery. Returning `null` drops the email entirely — the mailer short-circuits `pre_wp_mail`, so WordPress does **not** fall back to its default transport.

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

#### `leastudios_mailer_ses_request_body`

**Type:** Filter
**Location:** `src/SES/Client.php`
**Parameters:**
- `$body` *(array)* — The decoded JSON request body for the SES v2 `SendEmail` API call. Contains keys like `FromEmailAddress`, `Destination`, `Content`, and optionally `ReplyToAddresses`.
- `$from` *(string)* — The sender address.
- `$to` *(string[])* — The recipient addresses.
- `$subject` *(string)* — The email subject.

**Description:** Filter the SES API request payload before it is signed and sent. Fires only on the no-attachment send path, where SES `Content.Simple` is used. For emails carrying attachments, see `leastudios_mailer_ses_raw_request_body` below — the two filters mirror each other.

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

**Type:** Filter
**Location:** `src/SES/Client.php`
**Parameters:**
- `$body` *(array)* — The decoded JSON request body for the SES v2 `SendEmail` API call when sending with attachments. Contains `FromEmailAddress`, `Destination`, `Content.Raw.Data` (a base64-encoded RFC 5322 MIME message), and optionally `ReplyToAddresses`.
- `$from_email` *(string)* — The sender address.
- `$to` *(string[])* — The recipient addresses.
- `$subject` *(string)* — The email subject.

**Description:** Mirror of `leastudios_mailer_ses_request_body` for the Raw (attachment-bearing) send path. Use it to attach configuration sets, tags, or other top-level SES options when the email carries one or more attachments. Note that the MIME message itself is already encoded — modify `Content.Raw.Data` only if you know what you're doing (it must remain a valid base64-encoded RFC 5322 message).

**Example:**
```php
add_filter( 'leastudios_mailer_ses_raw_request_body', function ( array $body, string $from, array $to, string $subject ): array {
    $body['ConfigurationSetName'] = 'my-tracking-config';
    return $body;
}, 10, 4 );
```

---

#### `leastudios_mailer_max_message_bytes`

**Type:** Filter
**Location:** `src/SES/Client.php`
**Parameters:**
- `$max_bytes` *(int)* — The maximum size, in bytes, of a message sent through SES. Default `40 * MB_IN_BYTES` (40 MB, Amazon SES's documented limit). Return `0` or less to disable the check.

**Description:** Caps the size of an attachment-bearing email. After the RFC 5322 MIME message is assembled, its byte length is compared against this limit; a message over the limit is **not** sent — `send_raw_email()` returns a failure and the email is recorded in the log as `failed` with a "message size exceeds limit" error. This turns an oversized send into a clear, early failure instead of an opaque SES API rejection. Applies to the Raw (attachment) send path only — the no-attachment `Content.Simple` path is body-only and is not checked.

**Example:**
```php
add_filter( 'leastudios_mailer_max_message_bytes', function ( int $max_bytes ): int {
    // Tighten the cap to 10 MB.
    return 10 * MB_IN_BYTES;
} );
```

---

#### `leastudios_mailer_ses_max_attempts`

**Type:** Filter
**Location:** `src/SES/Client.php`
**Parameters:**
- `$max_attempts` *(int)* — Total number of attempts (initial request + retries) for a single SES API call. Default `3`. The returned value is clamped to the range `1`–`5`; return `1` to disable retries entirely.

**Description:** Controls how many times the SES client will attempt a request before giving up. Retries happen only on *transient* failures — a `WP_Error` from `wp_remote_post()` (connection, timeout, DNS) or an HTTP `429` / `5xx` response. Other `4xx` responses (bad credentials, unverified sender, malformed request) are configuration errors and fail immediately without retry.

**Example:**
```php
add_filter( 'leastudios_mailer_ses_max_attempts', function ( int $max_attempts ): int {
    // Be more persistent on a flaky network — clamped to 5 internally.
    return 5;
} );
```

---

#### `leastudios_mailer_ses_retry_delay_ms`

**Type:** Filter
**Location:** `src/SES/Client.php`
**Parameters:**
- `$base_delay_ms` *(int)* — Base backoff delay, in milliseconds, between SES retry attempts. Default `500`. Negative values are floored to `0`.

**Description:** Sets the base delay for the exponential-backoff-with-jitter wait between retry attempts. The delay before retry *n* is `base × 2^(n−1)` plus random "full jitter" of `0`–`base/2` ms — so with the `500` default, waits land around 0.5s, 1s, 2s, …. The jitter spreads retries out so multiple failing sends don't all hammer SES in lockstep. Return `0` to retry with no delay. Has no effect when `leastudios_mailer_ses_max_attempts` is `1`.

**Example:**
```php
add_filter( 'leastudios_mailer_ses_retry_delay_ms', function ( int $base_delay_ms ): int {
    // Slower, gentler backoff.
    return 1000;
} );
```

---

#### `leastudios_mailer_before_log`

**Type:** Filter
**Location:** `src/Log/Email_Logger.php`
**Parameters:**
- `$log_data` *(array)* — The log entry data with the following keys:
  - `to_email` *(string)* — Recipient email address(es).
  - `subject` *(string)* — Email subject.
  - `status` *(string)* — Status: `sent`, `failed`, `delivered`, `bounced`, `complained`.
  - `message_id` *(string|null)* — SES message ID.
  - `error_message` *(string|null)* — Error details on failure.

**Return:** Filtered `$log_data` array, or `false` to skip logging.

**Description:** Fires before an email log entry is written to the database. Use it to modify log data (e.g. redact sensitive subjects) or return `false` to suppress logging for certain emails.

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

#### `leastudios_mailer_settings_tabs`

**Type:** Filter
**Location:** `src/Admin/Settings_Page.php`
**Parameters:**
- `$tabs` *(array)* — Associative array of tab slug => label. Default tabs:
  - `configuration` => `"Configuration"`
  - `email-log` => `"Email Log"`
  - `test-email` => `"Test Email"`

**Description:** Filter the tabs displayed on the mailer settings page. Add your own tabs, remove existing ones, or reorder them. To render content for a custom tab, hook into the dynamic action `leastudios_mailer_settings_tab_{$slug}`.

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

#### `leastudios_mailer_sns_max_age_seconds`

**Type:** Filter
**Location:** `src/Webhook/SNS_Controller.php`
**Parameters:**
- `$max_age_seconds` *(int)* — Maximum age, in seconds, of an SNS notification the webhook will accept. Default `3600` (one hour).

**Description:** Part of the SNS webhook's replay protection. A notification whose `Timestamp` is older than this window is rejected during signature verification, so a captured notification body cannot be replayed against the endpoint indefinitely. This filter applies to the **inbound** delivery-tracking webhook, not the `wp_mail()` send pipeline.

**Example:**
```php
add_filter( 'leastudios_mailer_sns_max_age_seconds', function ( int $max_age_seconds ): int {
    // Tighten the replay window to 15 minutes.
    return 15 * MINUTE_IN_SECONDS;
} );
```

---

#### `leastudios_mailer_sns_future_skew_seconds`

**Type:** Filter
**Location:** `src/Webhook/SNS_Controller.php`
**Parameters:**
- `$future_skew_seconds` *(int)* — How far into the future, in seconds, an SNS notification's `Timestamp` may be and still be accepted. Default `300` (five minutes).

**Description:** The forward half of the SNS webhook's replay window. A small tolerance prevents legitimate notifications from being rejected when the local server clock lags behind AWS; notifications timestamped further ahead than this are rejected. Like `leastudios_mailer_sns_max_age_seconds`, this applies to the inbound delivery-tracking webhook.

**Example:**
```php
add_filter( 'leastudios_mailer_sns_future_skew_seconds', function ( int $future_skew_seconds ): int {
    // Allow more slack on a server with known clock drift.
    return 10 * MINUTE_IN_SECONDS;
} );
```

---

#### `leastudios_mailer_sns_rate_limit`

**Type:** Filter
**Location:** `src/Webhook/SNS_Controller.php`
**Parameters:**
- `$limit` *(int)* — Maximum number of webhook requests accepted per window, per client IP. Default `120`. Return `0` (or less) to disable rate limiting entirely.

**Description:** Caps how many requests a single IP may make to the SNS delivery-tracking webhook (`leastudios-mailer/v1/sns-webhook`) within the rate-limit window. The check runs before signature verification, so a flood of junk requests cannot drive repeated signing-certificate fetches or signature checks. A request over the limit receives HTTP `429`. Applies to the inbound webhook only, not the `wp_mail()` send pipeline.

**Example:**
```php
add_filter( 'leastudios_mailer_sns_rate_limit', function ( int $limit ): int {
    // Tighten to 30 requests per window.
    return 30;
} );
```

---

#### `leastudios_mailer_sns_rate_window_seconds`

**Type:** Filter
**Location:** `src/Webhook/SNS_Controller.php`
**Parameters:**
- `$window_seconds` *(int)* — Length of the rate-limit window, in seconds. Default `60`.

**Description:** Sets the window over which `leastudios_mailer_sns_rate_limit` requests are counted. With the defaults, an IP may make 120 webhook requests per 60 seconds. Applies to the inbound delivery-tracking webhook only.

**Example:**
```php
add_filter( 'leastudios_mailer_sns_rate_window_seconds', function ( int $window_seconds ): int {
    // Count requests over a 5-minute window instead of 1 minute.
    return 5 * MINUTE_IN_SECONDS;
} );
```

---

### Actions

#### `leastudios_mailer_email_sent`

**Type:** Action
**Location:** `src/Email/Mailer.php`
**Parameters:**
- `$ses_result` *(array)* — Result with keys: `success` *(bool)*, `message_id` *(string|null)*, `error` *(string|null)*.
- `$atts` *(array)* — Original `wp_mail()` arguments.
- `$status` *(string)* — Log status: `sent` or `failed`.

**Description:** Fires after an email is sent (or fails) via SES. Runs after the log entry has been written. Use it for notifications, external logging, or retry logic.

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

#### `leastudios_mailer_attachments_skipped`

**Type:** Action
**Location:** `src/Email/Mailer.php`
**Parameters:**
- `$skipped` *(array)* — The attachment entries that were dropped, preserving their original keys. Each value is whatever was supplied in `$atts['attachments']` (typically a string path, but unexpected types are also captured here).

**Description:** Fires when one or more attachments supplied to `wp_mail()` cannot be read from disk and are therefore dropped before SES delivery. Valid, readable attachments are sent — this action only fires for entries the mailer had to skip. Use it to log or alert when expected files are missing.

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

#### `leastudios_mailer_ses_response`

**Type:** Action
**Location:** `src/SES/Client.php`
**Parameters:**
- `$response` *(array)* — The result array with keys: `success` *(bool)*, `message_id` *(string|null)*, `error` *(string|null)*.
- `$url` *(string)* — The SES API endpoint URL that was called.
- `$body` *(string)* — The JSON request body that was sent.

**Description:** Fires immediately after the SES API response is received, before the result is returned to the mailer. Useful for low-level debugging, metrics collection, or forwarding results to external monitoring services. When retries occur (see `leastudios_mailer_ses_max_attempts`), this fires **once** with the final result — not once per attempt.

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

#### `leastudios_mailer_initialized`

**Type:** Action
**Location:** `src/Plugin.php`
**Parameters:** *(none)*

**Description:** Fires after all mailer components (SES client, logger, admin settings, webhook controller, and cron schedules) have been wired up. Use this to safely interact with the mailer knowing that all services are available.

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

**Type:** Action (dynamic)
**Location:** `src/Admin/Settings_Page.php`
**Parameters:**
- `$active_tab` *(string)* — The current tab slug.

**Description:** Fires when a custom tab (registered via the `leastudios_mailer_settings_tabs` filter) is the active tab. Use this to render the tab's HTML content. This action only fires for tabs that are not one of the three built-in tabs (`configuration`, `email-log`, `test-email`).

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

## Hook Execution Order

When `wp_mail()` is called and the mailer is enabled, hooks fire in this order:

1. **`leastudios_mailer_should_intercept`** — Decide whether to handle the email.
2. `wp_mail_from` / `wp_mail_from_name` — Standard WordPress sender filters.
3. **`leastudios_mailer_attachments_skipped`** — Only if one or more attachments were unreadable and had to be dropped.
4. **`leastudios_mailer_pre_send`** — Final chance to modify or cancel the email (including the validated `attachments` list).
5. **`leastudios_mailer_ses_request_body`** *(no-attachment send)* or **`leastudios_mailer_ses_raw_request_body`** *(attachment send)* — Modify the SES API payload. On the attachment send, **`leastudios_mailer_max_message_bytes`** is also read at this stage to enforce the SES message-size limit before the payload is dispatched.
6. **`leastudios_mailer_ses_max_attempts`** / **`leastudios_mailer_ses_retry_delay_ms`** — Read once before the first SES attempt to size the retry budget and backoff.
7. **`leastudios_mailer_ses_response`** — React to the SES API response. Fires once, after the final attempt, even when retries occurred.
8. **`leastudios_mailer_before_log`** — Filter or suppress the log entry.
9. **`leastudios_mailer_email_sent`** — Post-send action for notifications or logging.

The SNS webhook filters — **`leastudios_mailer_sns_rate_limit`**, **`leastudios_mailer_sns_rate_window_seconds`**, **`leastudios_mailer_sns_max_age_seconds`**, and **`leastudios_mailer_sns_future_skew_seconds`** — are not part of this sequence. They fire only on the inbound delivery-tracking webhook (`leastudios-mailer/v1`) when Amazon SNS posts a bounce/complaint/delivery notification, independently of any `wp_mail()` call.

## Attachments

Attachments passed to `wp_mail()` are forwarded to SES using the v2 `SendEmail` API with `Content.Raw`. The mailer assembles an RFC 5322 MIME message via the PHPMailer library bundled with WordPress core, so attachment encoding (Base64, Content-Type sniffing, headers) matches what core would have produced for the default transport.

Both attachment forms accepted by `wp_mail()` are supported:

```php
// Indexed (legacy) — display name is derived from basename().
wp_mail( $to, $subject, $body, $headers, [ '/abs/path/report.pdf' ] );

// Keyed (WP 5.6+) — explicit display name.
wp_mail( $to, $subject, $body, $headers, [ 'Quarterly Report.pdf' => '/abs/path/report.pdf' ] );
```

Files that do not exist or are not readable at send time are dropped silently from SES delivery and reported via the `leastudios_mailer_attachments_skipped` action so you can alert on them. The remaining valid files are still sent.
