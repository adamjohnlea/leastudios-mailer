# Attachment Size Limit & Skipped-Attachment Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fail oversized SES messages early with a clear logged error, and surface dropped (unreadable) attachments in the email log instead of only via an action hook.

**Architecture:** Two localized changes, no new classes. (1) `Client::send_raw_email()` measures the assembled MIME message and returns a failure result when it exceeds a filterable byte limit, before the SES API is called. (2) `Mailer::normalize_attachments()` returns the skipped entries alongside the validated ones, and `Mailer::send()` folds a human-readable note about them into the `error_message` it logs.

**Tech Stack:** PHP 8.1+, WordPress plugin APIs (`wp_mail`/`pre_wp_mail`, filters), PHPUnit 9.6 with the WordPress test library, PHPCS (WordPress Coding Standards), PHPStan level 6.

**Spec:** `docs/superpowers/specs/2026-05-21-attachment-size-limit-and-skip-visibility-design.md`

---

## File Structure

- `src/SES/Client.php` — *modify*. Add a message-size check inside `send_raw_email()`, after the MIME message is built and before the SES request body is assembled.
- `src/Email/Mailer.php` — *modify*. Change `normalize_attachments()` to return `{attachments, skipped}`; thread the skipped list into the log entry via two new private helpers.
- `tests/ClientTest.php` — *modify*. Add a `stub_send_endpoint()` helper and two size-limit tests.
- `tests/MailerTest.php` — *modify*. Add one test that a skipped attachment appears in the logged `error_message`.
- `docs/developer-handbook.md` — *modify*. Document the new `leastudios_mailer_max_message_bytes` filter.

---

## Task 1: Message size pre-check

**Files:**
- Modify: `tests/ClientTest.php` (add a helper and two tests before the final closing brace)
- Modify: `src/SES/Client.php` (`send_raw_email()`, inside the body)

- [ ] **Step 1: Write the failing tests**

In `tests/ClientTest.php`, find the last method — `test_unverified_when_domain_exists_but_is_not_verified_for_sending()` — which ends with:

```php
		$this->assertFalse( $result['verified'] );
		$this->assertIsString( $result['error'] );
	}
}
```

Replace that closing `}` of the method **and** the final class `}` with the method's closing brace followed by the new helper and tests, then the class brace:

```php
		$this->assertFalse( $result['verified'] );
		$this->assertIsString( $result['error'] );
	}

	/**
	 * Stub the SES v2 SendEmail (outbound-emails) endpoint with a 200 response,
	 * so a send that reaches the API does not make real network traffic.
	 */
	private function stub_send_endpoint(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( ! str_contains( (string) $url, '/v2/email/outbound-emails' ) ) {
					return $preempt;
				}

				return [
					'response' => [ 'code' => 200 ],
					'body'     => (string) wp_json_encode( [ 'MessageId' => 'within-limit-msg' ] ),
				];
			},
			10,
			3
		);
	}

	public function test_send_raw_email_over_the_size_limit_is_not_sent(): void {
		$this->stub_send_endpoint();

		// A 10-byte cap is smaller than any real MIME message.
		add_filter( 'leastudios_mailer_max_message_bytes', static fn(): int => 10 );

		$result = $this->client->send_raw_email(
			'sender@example.com',
			'Sender',
			[ 'recipient@example.com' ],
			'Subject',
			'',
			'Body text'
		);

		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['message_id'] );
		$this->assertStringContainsString( 'exceeds', (string) $result['error'] );
	}

	public function test_send_raw_email_within_the_size_limit_dispatches_to_ses(): void {
		$this->stub_send_endpoint();

		// No size filter — the default 40 MB cap is far above a tiny message.
		$result = $this->client->send_raw_email(
			'sender@example.com',
			'Sender',
			[ 'recipient@example.com' ],
			'Subject',
			'',
			'Body text'
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'within-limit-msg', $result['message_id'] );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ClientTest`
Expected: FAIL. `test_send_raw_email_over_the_size_limit_is_not_sent` fails — with no size check, `send_raw_email()` dispatches to the stubbed endpoint, gets a 200, and returns `success => true`, so `assertFalse()` fails. (`test_send_raw_email_within_the_size_limit_dispatches_to_ses` and the five existing tests already pass.)

- [ ] **Step 3: Add the size check to `send_raw_email()`**

In `src/SES/Client.php`, find this block inside `send_raw_email()`:

```php
		if ( null === $raw_message ) {
			return [
				'success'    => false,
				'message_id' => null,
				'error'      => __( 'Failed to build raw MIME message for SES.', 'leastudios-mailer' ),
			];
		}

		$body_array = $this->build_raw_request_body( $from_email, $to, $cc, $bcc, $reply_to, $raw_message );
```

Replace it with:

```php
		if ( null === $raw_message ) {
			return [
				'success'    => false,
				'message_id' => null,
				'error'      => __( 'Failed to build raw MIME message for SES.', 'leastudios-mailer' ),
			];
		}

		/**
		 * Filter the maximum size, in bytes, of a message sent through SES.
		 *
		 * Applies to attachment-bearing emails only. A message larger than
		 * this is not sent — it is failed and logged. Defaults to SES's
		 * documented 40 MB limit. Return 0 or less to disable the check.
		 *
		 * @param int $max_bytes Default 40 * MB_IN_BYTES (40 MB).
		 */
		$max_bytes = (int) apply_filters( 'leastudios_mailer_max_message_bytes', 40 * MB_IN_BYTES );

		if ( $max_bytes > 0 && strlen( $raw_message ) > $max_bytes ) {
			return [
				'success'    => false,
				'message_id' => null,
				'error'      => sprintf(
					/* translators: 1: actual message size, 2: configured size limit. */
					__( 'Message size (%1$s) exceeds the %2$s limit; not sent to SES.', 'leastudios-mailer' ),
					(string) size_format( strlen( $raw_message ) ),
					(string) size_format( $max_bytes )
				),
			];
		}

		$body_array = $this->build_raw_request_body( $from_email, $to, $cc, $bcc, $reply_to, $raw_message );
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ClientTest`
Expected: PASS (7 tests — 5 existing + 2 new).

- [ ] **Step 5: Run the full suite and lint**

Run: `composer test`
Expected: PASS (79 tests — 77 existing + 2 new).
Run: `composer lint`
Expected: PHPCS clean, PHPStan `[OK] No errors`. If PHPCS reports auto-fixable docblock-alignment issues, run `composer phpcbf` and re-run `composer lint`.

- [ ] **Step 6: Commit**

```bash
git add src/SES/Client.php tests/ClientTest.php
git commit -m "$(cat <<'EOF'
Reject oversized messages before they reach SES

send_raw_email() now measures the assembled RFC 5322 MIME message and
fails the send when it exceeds a configurable limit — the filter
leastudios_mailer_max_message_bytes, default 40 MB (SES's documented
maximum). Previously an oversized message was sent anyway and surfaced
only as an opaque SES API rejection; it is now failed early with a
clear "message size exceeds limit" error that is recorded in the log.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Surface skipped attachments in the email log

**Files:**
- Modify: `tests/MailerTest.php` (add one test before the final closing brace)
- Modify: `src/Email/Mailer.php` (`send()`, `normalize_attachments()`, and two new private helpers)

- [ ] **Step 1: Write the failing test**

In `tests/MailerTest.php`, find the last method — `test_send_handles_array_to_recipients()` — which ends with:

```php
		$this->mailer->send(
			null,
			[
				'to'      => [ 'a@example.com', 'b@example.com' ],
				'subject' => 'Multi',
				'message' => 'Hello',
				'headers' => [],
			]
		);
	}
}
```

Replace the method's closing `}` and the final class `}` with the method brace, the new test, and the class brace:

```php
		$this->mailer->send(
			null,
			[
				'to'      => [ 'a@example.com', 'b@example.com' ],
				'subject' => 'Multi',
				'message' => 'Hello',
				'headers' => [],
			]
		);
	}

	public function test_skipped_attachment_is_recorded_in_the_email_log(): void {
		update_option(
			'leastudios_mailer_options',
			[
				'enabled'    => true,
				'from_email' => 'sender@example.com',
			]
		);

		// The only attachment is unreadable, so it is dropped and the email
		// goes out via the Simple path with no attachments.
		$this->ses_client->method( 'send_email' )->willReturn(
			[
				'success'    => true,
				'message_id' => 'simple-msg',
				'error'      => '',
			]
		);

		// The log entry must carry a note naming the dropped attachment,
		// even though the email itself was sent successfully.
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with(
				$this->anything(),
				$this->anything(),
				$this->equalTo( 'sent' ),
				$this->anything(),
				$this->stringContains( 'missing.pdf' )
			);

		$this->mailer->send(
			null,
			[
				'to'          => 'recipient@example.com',
				'subject'     => 'Missing Attachment',
				'message'     => 'Hi',
				'headers'     => [],
				'attachments' => [ 'missing.pdf' => '/tmp/lsm-does-not-exist-' . uniqid() . '.pdf' ],
			]
		);
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter MailerTest`
Expected: FAIL. `test_skipped_attachment_is_recorded_in_the_email_log` fails — with the current code, `send()` passes `$ses_result['error']` (an empty string on success) as the log's `error_message`, so the `stringContains( 'missing.pdf' )` constraint on the 5th argument is not met. (The 13 existing tests still pass.)

- [ ] **Step 3: Change `normalize_attachments()` to also return the skipped entries**

In `src/Email/Mailer.php`, in the `normalize_attachments()` docblock, replace this line:

```php
	 * @return array<int, array{name: string, path: string}> Validated attachments.
```

with:

```php
	 * @return array{attachments: array<int, array{name: string, path: string}>, skipped: array<int|string, mixed>} The validated attachments plus the entries that were skipped.
```

Then, still in `normalize_attachments()`, replace this block:

```php
		if ( empty( $attachments ) ) {
			return [];
		}
```

with:

```php
		if ( empty( $attachments ) ) {
			return [
				'attachments' => [],
				'skipped'     => [],
			];
		}
```

And replace the end of the method:

```php
		if ( ! empty( $skipped ) ) {
			do_action( 'leastudios_mailer_attachments_skipped', $skipped );
		}

		return $normalized;
	}
```

with:

```php
		if ( ! empty( $skipped ) ) {
			do_action( 'leastudios_mailer_attachments_skipped', $skipped );
		}

		return [
			'attachments' => $normalized,
			'skipped'     => $skipped,
		];
	}
```

- [ ] **Step 4: Add the two private helper methods**

In `src/Email/Mailer.php`, immediately after the closing `}` of `normalize_attachments()` and before the `/**` docblock of `parse_headers()`, add:

```php
	/**
	 * Combine the SES send error (if any) with a note about skipped
	 * attachments into the single message stored in the email log.
	 *
	 * @param string|null          $ses_error The SES send error, or null on success.
	 * @param array<int|string, mixed> $skipped Attachment entries that were dropped.
	 * @return string|null The combined message, or null when there is nothing to record.
	 */
	private function log_error_message( ?string $ses_error, array $skipped ): ?string {
		$note = $this->skipped_attachments_note( $skipped );

		if ( '' === $note ) {
			return $ses_error;
		}

		if ( null === $ses_error || '' === $ses_error ) {
			return $note;
		}

		return $ses_error . ' ' . $note;
	}

	/**
	 * Build a human-readable note naming the attachments that were skipped.
	 *
	 * For the keyed `wp_mail()` form the array key is the caller's intended
	 * display name and is used directly; for the legacy indexed form the
	 * file's base name is used instead.
	 *
	 * @param array<int|string, mixed> $skipped Attachment entries that were dropped.
	 * @return string The note, or an empty string when nothing was skipped.
	 */
	private function skipped_attachments_note( array $skipped ): string {
		if ( empty( $skipped ) ) {
			return '';
		}

		$names = [];

		foreach ( $skipped as $key => $value ) {
			if ( is_string( $key ) && '' !== $key && ! ctype_digit( $key ) ) {
				$names[] = $key;
			} elseif ( is_string( $value ) && '' !== $value ) {
				$names[] = basename( $value );
			} else {
				$names[] = __( '(invalid entry)', 'leastudios-mailer' );
			}
		}

		$count = count( $names );

		return sprintf(
			/* translators: 1: number of attachments, 2: comma-separated list of filenames. */
			_n(
				'%1$d attachment could not be read and was not sent: %2$s',
				'%1$d attachments could not be read and were not sent: %2$s',
				$count,
				'leastudios-mailer'
			),
			$count,
			implode( ', ', $names )
		);
	}
```

- [ ] **Step 5: Capture the skipped list in `send()`**

In `src/Email/Mailer.php`, in the `send()` method, replace this block:

```php
		$raw_attachments = isset( $atts['attachments'] ) ? (array) $atts['attachments'] : [];
		$attachments     = $this->normalize_attachments( $raw_attachments );
```

with:

```php
		$raw_attachments = isset( $atts['attachments'] ) ? (array) $atts['attachments'] : [];
		$normalized      = $this->normalize_attachments( $raw_attachments );
		$attachments     = $normalized['attachments'];
		$skipped         = $normalized['skipped'];
```

- [ ] **Step 6: Fold the skipped note into the logged error**

In `src/Email/Mailer.php`, still in `send()`, replace this block:

```php
		$this->logger->log(
			$to_string,
			$subject,
			$status,
			$ses_result['message_id'],
			$ses_result['error'],
			$from_email
		);
```

with:

```php
		$this->logger->log(
			$to_string,
			$subject,
			$status,
			$ses_result['message_id'],
			$this->log_error_message( $ses_result['error'], $skipped ),
			$from_email
		);
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter MailerTest`
Expected: PASS (14 tests — 13 existing + 1 new).

- [ ] **Step 8: Run the full suite and lint**

Run: `composer test`
Expected: PASS (80 tests).
Run: `composer lint`
Expected: PHPCS clean, PHPStan `[OK] No errors`. If PHPCS reports auto-fixable issues, run `composer phpcbf` and re-run `composer lint`.

- [ ] **Step 9: Commit**

```bash
git add src/Email/Mailer.php tests/MailerTest.php
git commit -m "$(cat <<'EOF'
Record skipped attachments in the email log

When an unreadable attachment is dropped, Mailer::send() now folds a
note naming the affected files into the log entry's error_message, so
a site owner sees it in the Email Log admin tab instead of having to
wire up the leastudios_mailer_attachments_skipped action. The action
still fires unchanged; normalize_attachments() now returns the skipped
entries alongside the validated ones.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Document the new filter

**Files:**
- Modify: `docs/developer-handbook.md`

- [ ] **Step 1: Add the filter entry**

In `docs/developer-handbook.md`, find the end of the `leastudios_mailer_ses_raw_request_body` entry — its example block followed by a `---` and then `#### `leastudios_mailer_ses_max_attempts``:

```
add_filter( 'leastudios_mailer_ses_raw_request_body', function ( array $body, string $from, array $to, string $subject ): array {
    $body['ConfigurationSetName'] = 'my-tracking-config';
    return $body;
}, 10, 4 );
```

````

---

#### `leastudios_mailer_ses_max_attempts`
````

Insert the new entry between that `---` and `#### `leastudios_mailer_ses_max_attempts``, so the section becomes:

````
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
````

- [ ] **Step 2: Note the filter in the Hook Execution Order**

In `docs/developer-handbook.md`, in the "Hook Execution Order" section, replace item 5:

```
5. **`leastudios_mailer_ses_request_body`** *(no-attachment send)* or **`leastudios_mailer_ses_raw_request_body`** *(attachment send)* — Modify the SES API payload.
```

with:

```
5. **`leastudios_mailer_ses_request_body`** *(no-attachment send)* or **`leastudios_mailer_ses_raw_request_body`** *(attachment send)* — Modify the SES API payload. On the attachment send, **`leastudios_mailer_max_message_bytes`** is also read at this stage to enforce the SES message-size limit before the payload is dispatched.
```

- [ ] **Step 3: Commit**

```bash
git add docs/developer-handbook.md
git commit -m "$(cat <<'EOF'
Document the leastudios_mailer_max_message_bytes filter

Adds a developer-handbook entry for the new message-size filter and
notes it in the Hook Execution Order alongside the SES request-body
filters.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Verification (whole plan)

- `composer test` — green (80 tests: 77 existing + 3 new).
- `composer lint` — PHPCS clean, PHPStan `[OK] No errors`.
- Three commits in `leastudios-mailer`: the size check, the skipped-attachment logging, and the handbook entry.

Version bump (1.1.2), changelog, repackaging the distributable zip, and pushing are a separate finishing step handled after this plan.
