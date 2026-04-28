# leaStudios Mailer — Developer Handbook

## Hooks Reference

All hooks are prefixed with `leastudios_mailer_` and are designed to let developers customise every stage of the email pipeline — from interception, through SES delivery, to logging and admin UI.

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
- `$atts` *(array)* — The original `wp_mail()` arguments.

**Return:** Filtered `$args` array, or `null` to skip sending entirely.

**Description:** Fires just before the email is handed to the SES client. Use it to modify any part of the email (recipients, body, headers) or return `null` to silently cancel delivery.

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

**Description:** Filter the SES API request payload before it is signed and sent. Use this to add SES-specific features such as configuration sets, email tags, custom headers, or list management options.

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
- `$attachments` *(array)* — The attachment file paths that were present on the email.
- `$atts` *(array)* — The original `wp_mail()` arguments.

**Description:** Fires when an email contains attachments that cannot be sent via SES Simple content mode. SES Simple mode does not support attachments; this action lets you log or handle the situation (e.g. upload attachments to S3 and include links in the body).

**Example:**
```php
add_action( 'leastudios_mailer_attachments_skipped', function ( array $attachments, array $atts ): void {
    error_log( sprintf(
        '[leaStudios Mailer] %d attachment(s) skipped for email to %s: %s',
        count( $attachments ),
        is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : $atts['to'],
        implode( ', ', array_map( 'basename', $attachments ) )
    ) );
}, 10, 2 );
```

---

#### `leastudios_mailer_ses_response`

**Type:** Action
**Location:** `src/SES/Client.php`
**Parameters:**
- `$response` *(array)* — The result array with keys: `success` *(bool)*, `message_id` *(string|null)*, `error` *(string|null)*.
- `$url` *(string)* — The SES API endpoint URL that was called.
- `$body` *(string)* — The JSON request body that was sent.

**Description:** Fires immediately after the SES API response is received, before the result is returned to the mailer. Useful for low-level debugging, metrics collection, or forwarding results to external monitoring services.

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
3. **`leastudios_mailer_attachments_skipped`** — If attachments are present.
4. **`leastudios_mailer_pre_send`** — Final chance to modify or cancel the email.
5. **`leastudios_mailer_ses_request_body`** — Modify the raw SES API payload.
6. **`leastudios_mailer_ses_response`** — React to the SES API response.
7. **`leastudios_mailer_before_log`** — Filter or suppress the log entry.
8. **`leastudios_mailer_email_sent`** — Post-send action for notifications or logging.
