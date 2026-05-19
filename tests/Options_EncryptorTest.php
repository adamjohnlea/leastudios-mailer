<?php
/**
 * Tests for the credential encryptor.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\Encryption\Options_Encryptor;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Mailer\Encryption\Options_Encryptor
 */
class Options_EncryptorTest extends TestCase {

	private Options_Encryptor $encryptor;

	public function set_up(): void {
		parent::set_up();
		$this->encryptor = new Options_Encryptor();
		delete_transient( Options_Encryptor::DECRYPT_FAILURE_TRANSIENT );
	}

	public function test_round_trip_recovers_plaintext(): void {
		$plaintext  = 'AKIAEXAMPLE/wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
		$ciphertext = $this->encryptor->encrypt( $plaintext );

		$this->assertNotSame( $plaintext, $ciphertext );
		$this->assertSame( $plaintext, $this->encryptor->decrypt( $ciphertext ) );
	}

	public function test_each_encrypt_produces_a_fresh_nonce(): void {
		$plaintext = 'same secret value';

		$a = $this->encryptor->encrypt( $plaintext );
		$b = $this->encryptor->encrypt( $plaintext );

		$this->assertNotSame(
			$a,
			$b,
			'Ciphertext must differ across encryptions of the same plaintext (nonce reuse leaks plaintext)'
		);
		$this->assertSame( $plaintext, $this->encryptor->decrypt( $a ) );
		$this->assertSame( $plaintext, $this->encryptor->decrypt( $b ) );
	}

	public function test_empty_plaintext_round_trips_to_empty_string(): void {
		$this->assertSame( '', $this->encryptor->encrypt( '' ) );
		$this->assertSame( '', $this->encryptor->decrypt( '' ) );
	}

	public function test_v1_envelope_prefix_is_used(): void {
		$ciphertext = $this->encryptor->encrypt( 'sentinel' );

		$this->assertStringStartsWith( 'v1:', $ciphertext );
	}

	public function test_legacy_ciphertext_without_prefix_still_decrypts(): void {
		// Pre-envelope-format ciphertexts were bare base64. Build one the
		// same way the old encrypt() did and confirm decrypt() still reads it.
		$plaintext = 'legacy secret';
		$key       = sodium_crypto_generichash( AUTH_KEY . SECURE_AUTH_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$encrypted = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		$legacy    = base64_encode( $nonce . $encrypted );

		$this->assertStringStartsNotWith( 'v1:', $legacy );
		$this->assertSame( $plaintext, $this->encryptor->decrypt( $legacy ) );
	}

	public function test_tampered_ciphertext_returns_empty_string(): void {
		$ciphertext = $this->encryptor->encrypt( 'sensitive' );

		// Flip a byte in the encoded portion (skip the `v1:` prefix).
		$payload  = substr( $ciphertext, 3 );
		$mutated  = $payload;
		$mutated  = substr_replace( $mutated, $mutated[ strlen( $mutated ) - 5 ] === 'A' ? 'B' : 'A', strlen( $mutated ) - 5, 1 );
		$tampered = 'v1:' . $mutated;

		$this->assertSame( '', $this->encryptor->decrypt( $tampered ) );
	}

	public function test_truncated_ciphertext_returns_empty_string(): void {
		// A value too short to even contain a nonce must not crash — it
		// must just return ''. This is the path a stray /random/ option
		// value could otherwise take.
		$this->assertSame( '', $this->encryptor->decrypt( 'v1:dG9vc2hvcnQ=' ) );
		$this->assertSame( '', $this->encryptor->decrypt( 'v1:!!!not-base64!!!' ) );
	}

	public function test_decrypt_failure_sets_admin_notice_transient(): void {
		// Force a failure: feed a v1 envelope around random bytes.
		$bad = 'v1:' . base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16 ) );

		$this->assertSame( '', $this->encryptor->decrypt( $bad ) );
		$this->assertSame( 1, (int) get_transient( Options_Encryptor::DECRYPT_FAILURE_TRANSIENT ) );
	}

	public function test_successful_decrypt_does_not_set_failure_transient(): void {
		$ciphertext = $this->encryptor->encrypt( 'fine' );
		$this->assertSame( 'fine', $this->encryptor->decrypt( $ciphertext ) );

		$this->assertFalse( get_transient( Options_Encryptor::DECRYPT_FAILURE_TRANSIENT ) );
	}
}
