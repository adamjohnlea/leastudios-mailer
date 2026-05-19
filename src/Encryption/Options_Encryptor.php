<?php
/**
 * Encrypts and decrypts sensitive option values.
 *
 * @package LEAStudios\Mailer\Encryption
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Encryption;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Handles encryption/decryption of sensitive data using sodium.
 *
 * Ciphertexts are stored with an explicit `v1:` envelope prefix so future
 * key-rotation or algorithm changes can ship without breaking stored data.
 * Decryption transparently accepts both `v1:` and legacy bare-base64 values
 * written by earlier releases.
 *
 * When decryption fails on a non-empty input — overwhelmingly because the
 * site's `AUTH_KEY` or `SECURE_AUTH_SALT` rotated after credentials were
 * saved — a short-lived transient is set so the settings page can prompt
 * the admin to re-enter their AWS credentials.
 */
class Options_Encryptor {

	/**
	 * Current envelope prefix. Bump when the wire format changes.
	 */
	private const ENVELOPE_PREFIX = 'v1:';

	/**
	 * Transient key set when decryption of a non-empty ciphertext fails.
	 * Read by the admin settings page to render a recovery notice.
	 */
	public const DECRYPT_FAILURE_TRANSIENT = 'leastudios_mailer_decrypt_failed';

	/**
	 * Encrypt a plaintext value.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Envelope-prefixed, base64-encoded ciphertext with nonce prepended.
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key   = $this->derive_key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		sodium_memzero( $key );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::ENVELOPE_PREFIX . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a ciphertext value.
	 *
	 * Accepts both the `v1:`-prefixed envelope and the legacy bare-base64
	 * form written by releases prior to envelope versioning.
	 *
	 * @param string $ciphertext Stored ciphertext (envelope or legacy form).
	 * @return string The decrypted plaintext, or empty string on failure.
	 */
	public function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}

		$payload = $ciphertext;
		if ( str_starts_with( $payload, self::ENVELOPE_PREFIX ) ) {
			$payload = substr( $payload, strlen( self::ENVELOPE_PREFIX ) );
		}

		$key = $this->derive_key();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $payload, true );

		if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			sodium_memzero( $key );
			$this->flag_decrypt_failure();
			return '';
		}

		$nonce     = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$encrypted = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $encrypted, $nonce, $key );

		sodium_memzero( $key );

		if ( false === $plaintext ) {
			$this->flag_decrypt_failure();
			return '';
		}

		return $plaintext;
	}

	/**
	 * Record that a decryption attempt failed so the settings page can
	 * surface a recovery notice to the admin.
	 *
	 * Gated on `function_exists( 'set_transient' )` so unit tests that
	 * exercise the encryptor outside a full WP bootstrap don't fault.
	 *
	 * @return void
	 */
	private function flag_decrypt_failure(): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( self::DECRYPT_FAILURE_TRANSIENT, 1, MINUTE_IN_SECONDS * 15 );
	}

	/**
	 * Derive a 32-byte encryption key from WordPress salts.
	 *
	 * @return string The derived key.
	 */
	private function derive_key(): string {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_SALT' ) ) {
			wp_die(
				esc_html__( 'leastudios Mailer requires AUTH_KEY and SECURE_AUTH_SALT to be defined in wp-config.php for encryption to work.', 'leastudios-mailer' )
			);
		}

		return sodium_crypto_generichash( AUTH_KEY . SECURE_AUTH_SALT, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
