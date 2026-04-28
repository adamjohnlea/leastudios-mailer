<?php
/**
 * Nonce helper for secure form/action verification.
 *
 * @package LEAStudios\Mailer\Security
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Security;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Centralized nonce management.
 */
class Nonce {

	/**
	 * Create a nonce for a specific action.
	 *
	 * @param string $action The action name.
	 * @return string The nonce value.
	 */
	public static function create( string $action ): string {
		return wp_create_nonce( 'leastudios_mailer_' . $action );
	}

	/**
	 * Verify a nonce for a specific action.
	 *
	 * @param string $nonce  The nonce to verify.
	 * @param string $action The action name.
	 * @return bool Whether the nonce is valid.
	 */
	public static function verify( string $nonce, string $action ): bool {
		return (bool) wp_verify_nonce( $nonce, 'leastudios_mailer_' . $action );
	}

	/**
	 * Verify an AJAX nonce and die on failure.
	 *
	 * @param string $action    The action name.
	 * @param string $param_key The request parameter key. Default '_wpnonce'.
	 * @return void
	 */
	public static function check_ajax( string $action, string $param_key = '_wpnonce' ): void {
		check_ajax_referer( 'leastudios_mailer_' . $action, $param_key );
	}
}
