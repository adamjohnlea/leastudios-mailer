<?php
/**
 * Tests for the SES API client.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\Encryption\Options_Encryptor;
use LEAStudios\Mailer\SES\Client;
use LEAStudios\Mailer\SES\Signer;
use LEAStudios\Tests\TestCase;

/**
 * Exercises {@see Client::check_sender_identity()} — in particular that a
 * sender whose *domain* is verified in SES (rather than the individual
 * address) is reported as a healthy identity.
 *
 * SES `GetEmailIdentity` calls are stubbed via the `pre_http_request`
 * filter, so no real AWS traffic occurs.
 *
 * @covers \LEAStudios\Mailer\SES\Client
 */
class ClientTest extends TestCase {

	private const REGION       = 'us-east-1';
	private const IDENTITY_URL = 'https://email.us-east-1.amazonaws.com/v2/email/identities/';

	private Client $client;

	public function set_up(): void {
		parent::set_up();

		$encryptor = new Options_Encryptor();

		update_option(
			'leastudios_mailer_options',
			[
				'access_key' => $encryptor->encrypt( 'AKIAIOSFODNN7EXAMPLE' ),
				'secret_key' => $encryptor->encrypt( 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' ),
				'region'     => self::REGION,
			]
		);

		$this->client = new Client( $encryptor, new Signer() );
	}

	/**
	 * Stub SES GetEmailIdentity responses. Any identity not listed in $map
	 * gets a 404 NotFoundException, exactly as SES returns for an identity
	 * that was never registered in the account.
	 *
	 * @param array<string, array{code: int, body: array<string, mixed>}> $map Identity name => stubbed response.
	 */
	private function stub_identities( array $map ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $map ) {
				if ( 0 !== strpos( (string) $url, self::IDENTITY_URL ) ) {
					return $preempt;
				}

				$identity = rawurldecode( substr( (string) $url, strlen( self::IDENTITY_URL ) ) );

				if ( isset( $map[ $identity ] ) ) {
					return [
						'response' => [ 'code' => $map[ $identity ]['code'] ],
						'body'     => (string) wp_json_encode( $map[ $identity ]['body'] ),
					];
				}

				return [
					'response' => [ 'code' => 404 ],
					'body'     => (string) wp_json_encode(
						[ 'message' => "Email identity {$identity} does not exist." ]
					),
				];
			},
			10,
			3
		);
	}

	public function test_verified_email_identity_reports_verified(): void {
		$this->stub_identities(
			[
				'sender@example.com' => [
					'code' => 200,
					'body' => [ 'VerifiedForSendingStatus' => true ],
				],
			]
		);

		$result = $this->client->check_sender_identity( 'sender@example.com' );

		$this->assertTrue( $result['verified'] );
		$this->assertNull( $result['error'] );
	}

	public function test_verified_domain_covers_an_unregistered_address(): void {
		// The address itself is not an SES identity — only the domain is.
		// This is the standard "verify your domain in SES" setup.
		$this->stub_identities(
			[
				'example.com' => [
					'code' => 200,
					'body' => [ 'VerifiedForSendingStatus' => true ],
				],
			]
		);

		$result = $this->client->check_sender_identity( 'noreply@example.com' );

		$this->assertTrue( $result['verified'] );
		$this->assertNull( $result['error'] );
	}

	public function test_verified_domain_when_address_identity_is_pending(): void {
		// The address identity exists but is still pending verification;
		// the domain is verified, so the sender is usable regardless.
		$this->stub_identities(
			[
				'noreply@example.com' => [
					'code' => 200,
					'body' => [ 'VerifiedForSendingStatus' => false ],
				],
				'example.com'         => [
					'code' => 200,
					'body' => [ 'VerifiedForSendingStatus' => true ],
				],
			]
		);

		$result = $this->client->check_sender_identity( 'noreply@example.com' );

		$this->assertTrue( $result['verified'] );
	}

	public function test_unverified_when_neither_address_nor_domain_exists(): void {
		$this->stub_identities( [] );

		$result = $this->client->check_sender_identity( 'noreply@example.com' );

		$this->assertFalse( $result['verified'] );
		$this->assertIsString( $result['error'] );
		$this->assertStringContainsString( 'example.com', $result['error'] );
	}

	public function test_unverified_when_domain_exists_but_is_not_verified_for_sending(): void {
		$this->stub_identities(
			[
				'example.com' => [
					'code' => 200,
					'body' => [ 'VerifiedForSendingStatus' => false ],
				],
			]
		);

		$result = $this->client->check_sender_identity( 'noreply@example.com' );

		$this->assertFalse( $result['verified'] );
		$this->assertIsString( $result['error'] );
	}
}
