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
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
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
