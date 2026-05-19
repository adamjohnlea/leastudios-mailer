# Credential Validation & SNS Rate Limiting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Validate AWS credential format on save and add IP-based rate limiting to the SNS webhook — the two remaining `CODE_REVIEW.md` follow-ups for `leastudios-mailer`.

**Architecture:** Two localized changes, no new classes. (1) `Settings_Page::sanitize_options()` gains regex format checks for the Access Key ID and Secret Access Key; a malformed value is rejected (previous value kept) with an `add_settings_error()` notice. (2) `SNS_Controller::verify_request()` gains a fixed-window, per-IP transient counter that rejects floods with HTTP 429 before any signature work runs.

**Tech Stack:** PHP 8.1+, WordPress plugin APIs (Settings API, REST API, Transients API), PHPUnit 9.6 with the WordPress test library, PHPCS (WordPress Coding Standards), PHPStan level 6.

**Spec:** `docs/superpowers/specs/2026-05-19-credential-validation-and-sns-rate-limiting-design.md`

---

## File Structure

- `src/Admin/Settings_Page.php` — *modify*. Add two private pattern constants; rewrite the credential branches of `sanitize_options()`.
- `src/Webhook/SNS_Controller.php` — *modify*. Add a `is_rate_limited()` private helper; call it first in `verify_request()`.
- `tests/Settings_PageTest.php` — *create*. Covers `sanitize_options()` credential validation.
- `tests/SNS_ControllerTest.php` — *modify*. Add two rate-limit tests; clean up `$_SERVER['REMOTE_ADDR']` in `tear_down()`.
- `docs/developer-handbook.md` — *modify*. Document the two new SNS filters.
- `../leastudios-dev-tools/CODE_REVIEW.md` — *modify* (separate git repository). Mark the three mailer review items resolved.

---

## Task 1: AWS credential format validation

**Files:**
- Create: `tests/Settings_PageTest.php`
- Modify: `src/Admin/Settings_Page.php` (add constants after line 39; rewrite credential branches at lines 299–312)

- [ ] **Step 1: Write the failing test**

Create `tests/Settings_PageTest.php` with exactly this content:

```php
<?php
/**
 * Tests for the settings page sanitize callback.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\Admin\Settings_Page;
use LEAStudios\Mailer\Email\Health_Check;
use LEAStudios\Mailer\Encryption\Options_Encryptor;
use LEAStudios\Mailer\Log\Email_Logger;
use LEAStudios\Tests\TestCase;

/**
 * Exercises {@see Settings_Page::sanitize_options()} — AWS credential
 * format validation and the keep-current-value-on-blank behaviour.
 *
 * @covers \LEAStudios\Mailer\Admin\Settings_Page
 */
class Settings_PageTest extends TestCase {

	private const OPTION_NAME = 'leastudios_mailer_options';

	private const VALID_ACCESS_KEY = 'AKIAIOSFODNN7EXAMPLE';
	private const VALID_SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';

	private Options_Encryptor $encryptor;
	private Settings_Page $page;

	public function set_up(): void {
		parent::set_up();

		// add_settings_error() appends to a global; clear it so each test
		// observes only the errors it raised.
		global $wp_settings_errors;
		$wp_settings_errors = [];

		$this->encryptor = new Options_Encryptor();
		$this->page      = new Settings_Page(
			$this->encryptor,
			$this->createMock( Email_Logger::class ),
			$this->createMock( Health_Check::class )
		);
	}

	public function test_valid_credentials_are_encrypted_and_stored(): void {
		$result = $this->page->sanitize_options(
			[
				'access_key' => self::VALID_ACCESS_KEY,
				'secret_key' => self::VALID_SECRET_KEY,
			]
		);

		$this->assertSame( self::VALID_ACCESS_KEY, $this->encryptor->decrypt( $result['access_key'] ) );
		$this->assertSame( self::VALID_SECRET_KEY, $this->encryptor->decrypt( $result['secret_key'] ) );
		$this->assertEmpty( get_settings_errors( self::OPTION_NAME ) );
	}

	public function test_malformed_access_key_is_rejected_and_keeps_current(): void {
		update_option(
			self::OPTION_NAME,
			[ 'access_key' => $this->encryptor->encrypt( self::VALID_ACCESS_KEY ) ]
		);

		$result = $this->page->sanitize_options(
			[
				'access_key' => 'AKIA-typo',
				'secret_key' => '',
			]
		);

		// The typo is never stored; the previous encrypted value survives.
		$this->assertSame( self::VALID_ACCESS_KEY, $this->encryptor->decrypt( $result['access_key'] ) );
		$this->assertNotEmpty( get_settings_errors( self::OPTION_NAME ) );
	}

	public function test_malformed_secret_key_is_rejected_and_keeps_current(): void {
		update_option(
			self::OPTION_NAME,
			[ 'secret_key' => $this->encryptor->encrypt( self::VALID_SECRET_KEY ) ]
		);

		$result = $this->page->sanitize_options(
			[
				'access_key' => '',
				'secret_key' => 'too-short',
			]
		);

		$this->assertSame( self::VALID_SECRET_KEY, $this->encryptor->decrypt( $result['secret_key'] ) );
		$this->assertNotEmpty( get_settings_errors( self::OPTION_NAME ) );
	}

	public function test_blank_credentials_preserve_the_current_values(): void {
		update_option(
			self::OPTION_NAME,
			[
				'access_key' => $this->encryptor->encrypt( self::VALID_ACCESS_KEY ),
				'secret_key' => $this->encryptor->encrypt( self::VALID_SECRET_KEY ),
			]
		);

		$result = $this->page->sanitize_options(
			[
				'access_key' => '',
				'secret_key' => '',
			]
		);

		$this->assertSame( self::VALID_ACCESS_KEY, $this->encryptor->decrypt( $result['access_key'] ) );
		$this->assertSame( self::VALID_SECRET_KEY, $this->encryptor->decrypt( $result['secret_key'] ) );
		$this->assertEmpty( get_settings_errors( self::OPTION_NAME ) );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter Settings_PageTest`
Expected: FAIL. `test_malformed_access_key_is_rejected_and_keeps_current` and `test_malformed_secret_key_is_rejected_and_keeps_current` fail — current `sanitize_options()` has no format check, so it encrypts and stores the malformed value and raises no settings error. (`test_valid_*` and `test_blank_*` already pass against current code.)

- [ ] **Step 3: Add the pattern constants**

In `src/Admin/Settings_Page.php`, immediately after the `CAPABILITY` constant (line 39), add:

```php
	/**
	 * Format of an AWS Access Key ID — 20 characters, "AKIA" prefix.
	 *
	 * Temporary "ASIA" (STS) keys are intentionally excluded: they require
	 * an X-Amz-Security-Token the SES signer does not send, so they cannot
	 * authenticate against SES through this plugin.
	 */
	private const ACCESS_KEY_PATTERN = '/^AKIA[A-Z0-9]{16}$/';

	/**
	 * Format of an AWS Secret Access Key — exactly 40 base64-alphabet
	 * characters. The `#` delimiter avoids escaping the `/` in the class.
	 */
	private const SECRET_KEY_PATTERN = '#^[A-Za-z0-9/+]{40}$#';
```

- [ ] **Step 4: Rewrite the credential branches of `sanitize_options()`**

In `src/Admin/Settings_Page.php`, replace this block (currently lines 299–312):

```php
		// Encrypt credentials. Only update if a new value was provided.
		$access_key = sanitize_text_field( $input['access_key'] ?? '' );
		if ( '' !== $access_key ) {
			$sanitized['access_key'] = $this->encryptor->encrypt( $access_key );
		} else {
			$sanitized['access_key'] = $current['access_key'] ?? '';
		}

		$secret_key = sanitize_text_field( $input['secret_key'] ?? '' );
		if ( '' !== $secret_key ) {
			$sanitized['secret_key'] = $this->encryptor->encrypt( $secret_key );
		} else {
			$sanitized['secret_key'] = $current['secret_key'] ?? '';
		}
```

with:

```php
		// Encrypt credentials. Only update when a new, correctly-formatted
		// value is provided; a blank field leaves the stored secret as-is,
		// and a malformed value is rejected so a typo is never stored.
		$access_key = sanitize_text_field( $input['access_key'] ?? '' );
		if ( '' === $access_key ) {
			$sanitized['access_key'] = $current['access_key'] ?? '';
		} elseif ( 1 === preg_match( self::ACCESS_KEY_PATTERN, $access_key ) ) {
			$sanitized['access_key'] = $this->encryptor->encrypt( $access_key );
		} else {
			$sanitized['access_key'] = $current['access_key'] ?? '';
			add_settings_error(
				self::OPTION_NAME,
				'invalid_access_key',
				__( 'The AWS Access Key ID looks wrong — it should be 20 characters beginning with "AKIA". The previous value was kept.', 'leastudios-mailer' ),
				'error'
			);
		}

		$secret_key = sanitize_text_field( $input['secret_key'] ?? '' );
		if ( '' === $secret_key ) {
			$sanitized['secret_key'] = $current['secret_key'] ?? '';
		} elseif ( 1 === preg_match( self::SECRET_KEY_PATTERN, $secret_key ) ) {
			$sanitized['secret_key'] = $this->encryptor->encrypt( $secret_key );
		} else {
			$sanitized['secret_key'] = $current['secret_key'] ?? '';
			add_settings_error(
				self::OPTION_NAME,
				'invalid_secret_key',
				__( 'The AWS Secret Access Key looks wrong — it should be 40 characters long. The previous value was kept.', 'leastudios-mailer' ),
				'error'
			);
		}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter Settings_PageTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the full suite and lint**

Run: `composer test`
Expected: PASS (70 tests — 66 existing + 4 new).
Run: `composer lint`
Expected: PHPCS clean, PHPStan `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/Settings_Page.php tests/Settings_PageTest.php
git commit -m "$(cat <<'EOF'
Validate AWS credential format before storing

Settings_Page::sanitize_options() now checks the AWS Access Key ID
(`AKIA` + 16 chars) and Secret Access Key (40 base64-alphabet chars)
against their known formats. A malformed value is rejected — the
previously stored value is kept and an admin error notice is raised —
so a typo is caught at save time instead of surfacing later as an
opaque SES authentication failure. Blank fields still mean "leave the
stored secret unchanged".

Co-authored-by: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: SNS webhook rate limiting

**Files:**
- Modify: `tests/SNS_ControllerTest.php` (update `tear_down()` at lines 55–58; add two tests after `test_subscription_confirmation_string_to_sign`, i.e. after line 165)
- Modify: `src/Webhook/SNS_Controller.php` (rewrite `verify_request()` at lines 64–84; add `is_rate_limited()` helper)

- [ ] **Step 1: Write the failing tests**

In `tests/SNS_ControllerTest.php`, replace the existing `tear_down()` method:

```php
	public function tear_down(): void {
		delete_transient( 'leastudios_mailer_sns_cert_' . md5( self::CERT_URL ) );
		parent::tear_down();
	}
```

with:

```php
	public function tear_down(): void {
		delete_transient( 'leastudios_mailer_sns_cert_' . md5( self::CERT_URL ) );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}
```

Then, immediately after the `test_subscription_confirmation_string_to_sign()` method (after its closing `}` on line 165) and before the `signed_notification()` helper, add:

```php
	public function test_requests_under_the_rate_limit_pass(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		add_filter( 'leastudios_mailer_sns_rate_limit', static fn(): int => 5 );

		$message = $this->signed_notification();

		// Three requests, all within the limit of 5.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( $this->controller->verify_request( $this->build_request( $message ) ) );
		}
	}

	public function test_requests_over_the_rate_limit_return_429(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';

		add_filter( 'leastudios_mailer_sns_rate_limit', static fn(): int => 2 );

		$message = $this->signed_notification();

		// The first two requests are within the limit.
		$this->controller->verify_request( $this->build_request( $message ) );
		$this->controller->verify_request( $this->build_request( $message ) );

		// The third exceeds it and is rejected before signature checks run.
		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter SNS_ControllerTest`
Expected: FAIL. `test_requests_over_the_rate_limit_return_429` fails — with no rate limiting, the third request proceeds to signature verification and returns `true`, so `assertWPError()` fails. (`test_requests_under_the_rate_limit_pass` already passes against current code.)

- [ ] **Step 3: Add the rate-limit guard to `verify_request()`**

In `src/Webhook/SNS_Controller.php`, replace the opening of `verify_request()` — this block:

```php
	public function verify_request( WP_REST_Request $request ) {
		$body = $request->get_json_params();
```

with:

```php
	public function verify_request( WP_REST_Request $request ) {
		if ( $this->is_rate_limited() ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many requests.', 'leastudios-mailer' ),
				[ 'status' => 429 ]
			);
		}

		$body = $request->get_json_params();
```

- [ ] **Step 4: Add the `is_rate_limited()` helper**

In `src/Webhook/SNS_Controller.php`, immediately after the closing `}` of `verify_request()` and before `handle_notification()`, add:

```php
	/**
	 * Determine whether the calling IP has exceeded the webhook rate limit.
	 *
	 * Fixed-window counter: the transient key embeds a time bucket, so the
	 * transient's TTL is always exactly the window and no TTL-preservation
	 * is needed. The benign read-increment-write race under concurrency is
	 * acceptable for a rate limiter. The check runs before signature
	 * verification so a flood cannot drive repeated certificate fetches.
	 *
	 * @return bool True when the request should be rejected with HTTP 429.
	 */
	private function is_rate_limited(): bool {
		/**
		 * Filter the maximum SNS webhook requests allowed per window, per IP.
		 *
		 * Return 0 or less to disable webhook rate limiting entirely.
		 *
		 * @param int $limit Default 120.
		 */
		$limit = (int) apply_filters( 'leastudios_mailer_sns_rate_limit', 120 );

		if ( $limit <= 0 ) {
			return false;
		}

		/**
		 * Filter the SNS webhook rate-limit window, in seconds.
		 *
		 * @param int $window_seconds Default 60.
		 */
		$window = (int) apply_filters( 'leastudios_mailer_sns_rate_window_seconds', MINUTE_IN_SECONDS );
		$window = max( 1, $window );

		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$bucket = (int) floor( time() / $window );
		$key    = 'leastudios_mailer_sns_rl_' . $bucket . '_' . md5( $ip );

		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, $window );

		return $count > $limit;
	}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter SNS_ControllerTest`
Expected: PASS (13 tests — 11 existing + 2 new).

- [ ] **Step 6: Run the full suite and lint**

Run: `composer test`
Expected: PASS (72 tests).
Run: `composer lint`
Expected: PHPCS clean, PHPStan `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add src/Webhook/SNS_Controller.php tests/SNS_ControllerTest.php
git commit -m "$(cat <<'EOF'
Rate-limit the SNS webhook endpoint

verify_request() now applies a per-IP fixed-window rate limit before
any JSON parsing or signature verification, so a flood of junk
requests cannot drive repeated signing-certificate fetches or
signature checks. Over-limit requests get HTTP 429.

The limit and window are filterable via leastudios_mailer_sns_rate_limit
(default 120) and leastudios_mailer_sns_rate_window_seconds (default
60); a limit of 0 disables the feature. The counter is keyed by
REMOTE_ADDR and a time bucket so the transient TTL is simply the
window.

Co-authored-by: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Document the new SNS filters

**Files:**
- Modify: `docs/developer-handbook.md`

- [ ] **Step 1: Add the two filter entries**

In `docs/developer-handbook.md`, find the end of the `leastudios_mailer_sns_future_skew_seconds` entry — its example block ends with:

```
add_filter( 'leastudios_mailer_sns_future_skew_seconds', function ( int $future_skew_seconds ): int {
    // Allow more slack on a server with known clock drift.
    return 10 * MINUTE_IN_SECONDS;
} );
```

followed by a closing ``` fence, a blank line, `---`, a blank line, and `### Actions`.

Insert the following two entries between that `---` and `### Actions`:

````markdown
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
````

- [ ] **Step 2: Update the SNS note in Hook Execution Order**

In `docs/developer-handbook.md`, in the "Hook Execution Order" section, replace this paragraph:

```
The SNS webhook filters — **`leastudios_mailer_sns_max_age_seconds`** and **`leastudios_mailer_sns_future_skew_seconds`** — are not part of this sequence. They fire only on the inbound delivery-tracking webhook (`leastudios-mailer/v1`) when Amazon SNS posts a bounce/complaint/delivery notification, independently of any `wp_mail()` call.
```

with:

```
The SNS webhook filters — **`leastudios_mailer_sns_rate_limit`**, **`leastudios_mailer_sns_rate_window_seconds`**, **`leastudios_mailer_sns_max_age_seconds`**, and **`leastudios_mailer_sns_future_skew_seconds`** — are not part of this sequence. They fire only on the inbound delivery-tracking webhook (`leastudios-mailer/v1`) when Amazon SNS posts a bounce/complaint/delivery notification, independently of any `wp_mail()` call.
```

- [ ] **Step 3: Commit**

```bash
git add docs/developer-handbook.md
git commit -m "$(cat <<'EOF'
Document the SNS webhook rate-limit filters

Adds developer-handbook entries for leastudios_mailer_sns_rate_limit
and leastudios_mailer_sns_rate_window_seconds, and lists them in the
Hook Execution Order note alongside the existing SNS replay-window
filters.

Co-authored-by: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Mark CODE_REVIEW.md items resolved

**Files:**
- Modify: `../leastudios-dev-tools/CODE_REVIEW.md` — **a separate git repository.** The commit in this task runs in `leastudios-dev-tools`, not `leastudios-mailer`.

- [ ] **Step 1: Append a resolution note**

In `../leastudios-dev-tools/CODE_REVIEW.md`, in the `## Plugin: leastudios-mailer` section, find this line:

```
**This plugin has no other actionable issues.** It is production-ready.
```

Immediately after it, add a blank line and:

```markdown
**Resolution (2026-05-19):** All three items addressed.

1. Resolved — `Options_Encryptor::derive_key()` `wp_die()`s when `AUTH_KEY` / `SECURE_AUTH_SALT` are undefined; no silent fallback remains.
2. Resolved — `SNS_Controller::verify_request()` enforces an IP-based fixed-window rate limit (`leastudios_mailer_sns_rate_limit`, default 120 per 60s) before signature verification.
3. Resolved — `Settings_Page::sanitize_options()` validates AWS Access Key ID / Secret Access Key format and rejects malformed values with an admin error notice.
```

- [ ] **Step 2: Commit (in the leastudios-dev-tools repository)**

```bash
cd ../leastudios-dev-tools
git add CODE_REVIEW.md
git commit -m "$(cat <<'EOF'
Mark leastudios-mailer code-review items resolved

All three low-priority items in the mailer section are now addressed:
encryption fail-loud, SNS webhook rate limiting, and AWS credential
format validation.

Co-authored-by: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
cd ../leastudios-mailer
```

---

## Verification (whole plan)

- `composer test` — green (72 tests).
- `composer lint` — PHPCS clean, PHPStan `[OK] No errors`.
- Four commits: two in `leastudios-mailer` for the code changes, one in `leastudios-mailer` for the handbook, one in `leastudios-dev-tools` for `CODE_REVIEW.md`.
