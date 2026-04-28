=== leaStudios Mailer ===
Contributors: leastudios
Tags: email, ses, amazon ses, smtp, email log
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight Amazon SES email transport for WordPress. Routes all wp_mail() through SES with logging and delivery tracking.

== Description ==

leaStudios Mailer replaces the default WordPress email transport with Amazon SES. All emails sent via `wp_mail()` are routed through your SES account with full delivery logging and bounce/complaint tracking via SNS webhooks.

**Key features:**

* **Amazon SES integration** — routes all WordPress emails through SES using the REST API. No SMTP configuration needed.
* **Email logging** — every sent email is logged with recipient, subject, status, and SES message ID.
* **Delivery tracking** — configure an SNS webhook to receive bounce, complaint, and delivery notifications. Status updates appear in the email log.
* **Health check** — test your SES configuration and send a test email from the admin.
* **Encrypted credentials** — AWS access keys are encrypted at rest using libsodium.
* **Log retention** — configurable retention period with automatic cleanup.

**How it works:**

The plugin hooks into WordPress's `pre_wp_mail` filter to intercept all outgoing emails. When enabled, emails are sent via the SES API instead of the default PHP mail transport. If SES is disabled or encounters an error, emails fall back to the default WordPress behaviour.

== Installation ==

1. Upload the `leastudios-mailer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Mailer in the admin menu and enter your AWS credentials (Access Key ID, Secret Access Key, and Region).
4. Set your verified From email address and From name.
5. Enable SES and send a test email to verify the configuration.
6. Optionally configure an SNS webhook for delivery tracking (webhook URL is shown on the settings page).

== Frequently Asked Questions ==

= Do I need an AWS account? =

Yes. You need an AWS account with SES configured and at least one verified email address or domain.

= What happens if SES is unavailable? =

The plugin returns null from the `pre_wp_mail` filter, which lets WordPress fall back to its default email transport.

= Does this work with other email plugins? =

leaStudios Mailer replaces the email transport layer. It is not compatible with other SMTP or email transport plugins that also hook `pre_wp_mail`. It works alongside plugins that compose emails via `wp_mail()` (like contact form plugins).

== Changelog ==

= 1.0.0 =
* Initial release.
