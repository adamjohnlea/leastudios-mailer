# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin is

**leaStudios Mailer** — routes every WordPress `wp_mail()` call through the Amazon SES v2 API, logs each send to a custom table, and tracks bounce/complaint/delivery events via an SNS webhook. Part of the leaStudios plugin suite but fully self-contained (own git repo; clones, lints, tests, and packages on its own).

## Inherited conventions — read first

`../leastudios-dev-tools/CLAUDE.md` is the **mother CLAUDE.md** for all `leastudios-*` plugins: coding standards, escape/sanitize rules, nonce/capability conventions, REST and database patterns, i18n. Those conventions are inherited and are **not repeated here** — this file covers only what is specific to this plugin.

`docs/developer-handbook.md` is the authoritative reference for every public hook (filters, actions, dynamic actions) and their firing order. Update it whenever you add, remove, or change a hook.

## Commands

```bash
composer install                       # one-time
composer lint                          # phpcs + phpstan
composer phpcs                         # WordPress Coding Standards check
composer phpcbf                        # auto-fix coding-standards issues
composer phpstan                       # PHPStan level 6, scans src/
composer test                          # full PHPUnit suite
vendor/bin/phpunit --filter MailerTest  # single test class
vendor/bin/phpunit tests/SignerTest.php # single test file
```

PHPUnit runs against a real WordPress test library. Install it once (shared across all suite plugins):

```bash
bash ../leastudios-dev-tools/bin/install-wp-tests.sh wordpress_test root '<db-pass>' 127.0.0.1 latest
```

## Architecture

`leastudios-mailer.php` defines `LEASTUDIOS_MAILER_*` constants, loads the Composer autoloader, and on `plugins_loaded` instantiates `LEAStudios\Mailer\Plugin` (PSR-4 namespace `LEAStudios\Mailer\` → `src/`). Activation seeds the `leastudios_mailer_options` option, runs migrations, and schedules cron; deactivation unschedules cron.

`Plugin::init()` wires the components together and fires `leastudios_mailer_initialized` when done:

- **`Email/Mailer`** — the heart of the plugin. Hooks `pre_wp_mail` (priority 10) to intercept outgoing mail. If `leastudios_mailer_options['enabled']` is false it returns `null` to let WordPress's default transport handle the mail; otherwise it builds the email args, runs them through filters, and hands off to the SES client.
- **`SES/Client`** + **`SES/Signer`** — `Client` calls the SES v2 `SendEmail` API; `Signer` produces the AWS SigV4 request signature. No-attachment sends use `Content.Simple`; attachment sends build an RFC 5322 MIME message (via WP-core PHPMailer) and use `Content.Raw`. The two paths have mirrored request-body filters.
- **`Log/Email_Logger`** — writes one row per send to the log table; also updated by the SNS webhook with delivery status.
- **`Webhook/SNS_Controller`** — registers a REST route under namespace `leastudios-mailer/v1` (registered on `rest_api_init`) that receives Amazon SNS notifications and updates log rows with bounce/complaint/delivery status.
- **`Email/Health_Check`** — verifies SES config and sends test emails from the admin.
- **`Admin/Settings_Page`** (admin only) — three built-in tabs (Configuration, Email Log, Test Email); extensible via the `leastudios_mailer_settings_tabs` filter.

### Data & storage

- **Custom table**: `{$wpdb->prefix}leastudios_mailer_log` — the only table. Created/altered by `Database/Migration` via `dbDelta()`.
- **Schema versioning**: target version is the `SCHEMA_VERSION` constant in `Database/Migration`; current version is stored in the `leastudios_mailer_schema_version` option. `maybe_migrate()` runs incremental, idempotent steps (`if ( $from_version < N )`). **When changing the schema, bump `SCHEMA_VERSION` and add a new guarded migration step — never edit an existing one.**
- **Settings**: the `leastudios_mailer_options` option. AWS credentials are encrypted at rest with libsodium via `Encryption/Options_Encryptor` (requires `ext-sodium`, `ext-openssl`).
- **Cron**: `leastudios_mailer_cleanup_logs` runs daily, pruning log rows older than `log_retention_days`.

### Extension surface

The email pipeline is hook-driven end to end: `should_intercept` → `pre_send` → SES request-body filters → `ses_response` → `before_log` → `email_sent`. All hooks are prefixed `leastudios_mailer_`. See `docs/developer-handbook.md` for signatures, return contracts, and the exact firing order.

## Plugin-specific gotchas

- **`Security/Nonce.php`, `Encryption/Options_Encryptor.php`, `Shared/Datetime_Util.php` are shared-by-duplication** across suite plugins and must stay byte-identical. `../leastudios-dev-tools/bin/check-shared.sh` verifies this. Edit one → propagate to every copy.
- **PHPStan runs with `treatPhpDocTypesAsCertain: false`** (see `phpstan.neon`). This is deliberate: `apply_filters()` is `mixed` at runtime, so the runtime defensive checks on filter return values (`false === $filtered`, `null === $args`) are real and must not be "simplified away" as dead code.
- The mailer must **degrade gracefully** — any SES/config error falls back to the default WordPress transport rather than dropping the email.
