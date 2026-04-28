# leaStudios Mailer

Lightweight Amazon SES email transport for WordPress. Routes every `wp_mail()` through SES, logs every send, and tracks bounces/complaints/deliveries via SNS webhooks.

- **Requires WordPress:** 6.4+
- **Requires PHP:** 8.1+
- **License:** GPL-2.0-or-later

## Features

- **Amazon SES via REST API** — no SMTP configuration required.
- **Email logging** — every send is logged with recipient, subject, status, and SES message ID.
- **Delivery tracking** — configure an SNS webhook to receive bounce/complaint/delivery events; status updates flow into the email log.
- **Health check** in the admin — verify your SES configuration and send a test email.
- **Encrypted credentials** at rest (libsodium).
- **Configurable log retention** with automatic cleanup.

## How it works

The plugin hooks into WordPress's `pre_wp_mail` filter to intercept outgoing emails. When SES is enabled, mail goes through the SES API; when disabled or on error, it falls back to the default WordPress transport.

## Installation

1. Upload `leastudios-mailer` to `/wp-content/plugins/`.
2. Activate via Plugins → Installed Plugins.
3. Go to **Mailer** in the admin menu, enter AWS credentials (Access Key, Secret, Region) and the verified From address.
4. Enable SES and send a test email.
5. Optionally configure an SNS webhook (URL shown on the settings page) for delivery tracking.

## Related plugins

This plugin is part of the leaStudios plugin family. It works on its own, and integrates with:

- **[leastudios-email-templates](../leastudios-email-templates)** — when active, the template wrapper hooks into the mailer pipeline so branded emails go through SES with full delivery tracking.
- **[leastudios-forms](../leastudios-forms)** — form notification deliveries are tracked per submission when the mailer is active.
- **[leastudios-payments](../leastudios-payments)** — payment receipts and subscription emails benefit from SES delivery + logging.

## Development

This plugin is self-contained — it can be cloned, linted, tested, and packaged on its own.

```bash
composer install            # install dependencies (incl. dev tools)
composer lint               # phpcs + phpstan
composer test               # phpunit (requires the WP test library)
composer phpcbf             # auto-fix WPCS issues
```

To run the test suite, install the WordPress test library once:

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '' localhost latest
```

The shared scaffold, packaging script, and project-wide development conventions live in **[leastudios-dev-tools](../leastudios-dev-tools)** — start there when bootstrapping a new plugin or making cross-plugin tooling changes.

## License

GPL-2.0-or-later. See `readme.txt` for the WordPress.org-style header.
