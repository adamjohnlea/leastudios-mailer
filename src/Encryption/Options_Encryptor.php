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
 */
class Options_Encryptor {

	/**
	 * Encrypt a plaintext value.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Base64-encoded ciphertext with nonce prepended.
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
		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a ciphertext value.
	 *
	 * @param string $ciphertext Base64-encoded ciphertext with nonce prepended.
	 * @return string The decrypted plaintext, or empty string on failure.
	 */
	public function decrypt( string $ciphertext ): string {
		if ( '' === $ciphertext ) {
			return '';
		}

		$key = $this->derive_key();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $ciphertext, true );

		if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			sodium_memzero( $key );
			return '';
		}

		$nonce     = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$encrypted = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $encrypted, $nonce, $key );

		sodium_memzero( $key );

		if ( false === $plaintext ) {
			return '';
		}

		return $plaintext;
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
