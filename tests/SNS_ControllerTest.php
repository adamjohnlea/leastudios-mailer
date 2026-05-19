<?php
/**
 * Tests for the SNS webhook controller.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\Log\Email_Logger;
use LEAStudios\Mailer\Webhook\SNS_Controller;
use LEAStudios\Tests\TestCase;
use WP_REST_Request;

/**
 * Exercises {@see SNS_Controller::verify_request()} end-to-end so the cert
 * URL host check, replay window, signature-version match, and signature
 * verification are all covered.
 *
 * A throw-away self-signed RSA cert is generated per-test and seeded
 * directly into the cert transient that {@see SNS_Controller} reads,
 * which avoids any real HTTP traffic to sns.amazonaws.com.
 *
 * @covers \LEAStudios\Mailer\Webhook\SNS_Controller
 */
class SNS_ControllerTest extends TestCase {

	private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem';

	private SNS_Controller $controller;
	private string $cert_pem;

	/** @var \OpenSSLAsymmetricKey */
	private $private_key;

	public function set_up(): void {
		parent::set_up();

		$logger           = $this->createMock( Email_Logger::class );
		$this->controller = new SNS_Controller( $logger );

		[ $this->cert_pem, $this->private_key ] = self::generate_test_cert();

		// Pre-seed the cert transient so verify_sns_signature() doesn't
		// attempt a live wp_remote_get() to amazonaws.com.
		set_transient(
			'leastudios_mailer_sns_cert_' . md5( self::CERT_URL ),
			$this->cert_pem,
			HOUR_IN_SECONDS
		);
	}

	public function tear_down(): void {
		delete_transient( 'leastudios_mailer_sns_cert_' . md5( self::CERT_URL ) );
		parent::tear_down();
	}

	public function test_valid_notification_signature_passes(): void {
		$message = $this->signed_notification();

		$request = $this->build_request( $message );

		$this->assertTrue( $this->controller->verify_request( $request ) );
	}

	public function test_tampered_signature_is_rejected(): void {
		$message              = $this->signed_notification();
		$message['Signature'] = base64_encode( random_bytes( 256 ) );

		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_signature', $result->get_error_code() );
	}

	public function test_tampered_message_body_is_rejected(): void {
		$message            = $this->signed_notification();
		$message['Message'] = 'attacker swapped this in';

		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
	}

	public function test_non_aws_cert_url_is_rejected(): void {
		$message                   = $this->signed_notification();
		$message['SigningCertURL'] = 'https://sns.attacker.com/cert.pem';

		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
	}

	public function test_http_cert_url_is_rejected(): void {
		$message                   = $this->signed_notification();
		$message['SigningCertURL'] = 'http://sns.us-east-1.amazonaws.com/cert.pem';

		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
	}

	public function test_unknown_signature_version_is_rejected(): void {
		$message                     = $this->signed_notification();
		$message['SignatureVersion'] = '99';

		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
	}

	public function test_missing_timestamp_is_rejected(): void {
		$message = $this->signed_notification();
		unset( $message['Timestamp'] );

		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
	}

	public function test_timestamp_older_than_replay_window_is_rejected(): void {
		// Two hours ago — well outside the default 1-hour window.
		$old = ( new \DateTimeImmutable( '-2 hours' ) )->format( 'Y-m-d\TH:i:s.v\Z' );

		$message = $this->signed_notification( [ 'Timestamp' => $old ] );

		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
	}

	public function test_timestamp_far_in_future_is_rejected(): void {
		// One hour ahead — outside the 5-minute future-skew tolerance.
		$future = ( new \DateTimeImmutable( '+1 hour' ) )->format( 'Y-m-d\TH:i:s.v\Z' );

		$message = $this->signed_notification( [ 'Timestamp' => $future ] );

		$result = $this->controller->verify_request( $this->build_request( $message ) );

		$this->assertWPError( $result );
	}

	public function test_replay_window_is_filterable(): void {
		$age = ( new \DateTimeImmutable( '-2 hours' ) )->format( 'Y-m-d\TH:i:s.v\Z' );

		$message = $this->signed_notification( [ 'Timestamp' => $age ] );

		// Allow 3 hours — should now pass.
		add_filter(
			'leastudios_mailer_sns_max_age_seconds',
			static fn(): int => 3 * HOUR_IN_SECONDS
		);

		$this->assertTrue( $this->controller->verify_request( $this->build_request( $message ) ) );
	}

	public function test_subscription_confirmation_string_to_sign(): void {
		// SubscriptionConfirmation uses a different canonical key set than
		// Notification. Build one, sign it, verify it passes.
		$message = $this->signed_subscription_confirmation();

		$this->assertTrue( $this->controller->verify_request( $this->build_request( $message ) ) );
	}

	/**
	 * Build a signed SNS Notification message with sensible defaults that
	 * the caller can override per-field.
	 *
	 * @param array<string, string> $overrides Fields to override.
	 * @return array<string, mixed>
	 */
	private function signed_notification( array $overrides = [] ): array {
		$base = [
			'Type'             => 'Notification',
			'MessageId'        => 'msg-' . uniqid(),
			'TopicArn'         => 'arn:aws:sns:us-east-1:111122223333:test-topic',
			'Subject'          => 'Test subject',
			'Message'          => wp_json_encode(
				[
					'notificationType' => 'Delivery',
					'mail'             => [ 'messageId' => 'ses-msg-id' ],
				]
			),
			'Timestamp'        => ( new \DateTimeImmutable( 'now' ) )->format( 'Y-m-d\TH:i:s.v\Z' ),
			'SignatureVersion' => '1',
			'SigningCertURL'   => self::CERT_URL,
		];

		$message = array_merge( $base, $overrides );

		$string_to_sign = $this->canonical_for_notification( $message );

		openssl_sign( $string_to_sign, $raw_signature, $this->private_key, OPENSSL_ALGO_SHA1 );

		$message['Signature'] = base64_encode( $raw_signature );

		return $message;
	}

	/**
	 * Build a signed SubscriptionConfirmation message.
	 *
	 * @return array<string, mixed>
	 */
	private function signed_subscription_confirmation(): array {
		$message = [
			'Type'             => 'SubscriptionConfirmation',
			'MessageId'        => 'msg-' . uniqid(),
			'Token'            => str_repeat( 'a', 64 ),
			'TopicArn'         => 'arn:aws:sns:us-east-1:111122223333:test-topic',
			'Message'          => 'You have chosen to subscribe to the topic …',
			'SubscribeURL'     => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
			'Timestamp'        => ( new \DateTimeImmutable( 'now' ) )->format( 'Y-m-d\TH:i:s.v\Z' ),
			'SignatureVersion' => '1',
			'SigningCertURL'   => self::CERT_URL,
		];

		// Order matters: same as build_string_to_sign() for non-Notification.
		$keys           = [ 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' ];
		$string_to_sign = '';
		foreach ( $keys as $k ) {
			$string_to_sign .= $k . "\n" . $message[ $k ] . "\n";
		}

		openssl_sign( $string_to_sign, $raw_signature, $this->private_key, OPENSSL_ALGO_SHA1 );

		$message['Signature'] = base64_encode( $raw_signature );

		return $message;
	}

	/**
	 * Build the AWS-canonical string-to-sign for a Notification message.
	 */
	private function canonical_for_notification( array $message ): string {
		$keys           = [ 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' ];
		$string_to_sign = '';
		foreach ( $keys as $k ) {
			if ( isset( $message[ $k ] ) ) {
				$string_to_sign .= $k . "\n" . $message[ $k ] . "\n";
			}
		}
		return $string_to_sign;
	}

	/**
	 * Wrap a message in a WP_REST_Request the controller can read.
	 *
	 * @param array<string, mixed> $message The SNS envelope to serialize as the request body.
	 */
	private function build_request( array $message ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/leastudios-mailer/v1/sns-webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $message ) );
		return $request;
	}

	/**
	 * Generate a fresh self-signed RSA cert for the duration of a single
	 * test class. Returned as [ pem-encoded cert, private key ].
	 *
	 * @return array{0: string, 1: \OpenSSLAsymmetricKey}
	 */
	private static function generate_test_cert(): array {
		$private_key = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		$csr = openssl_csr_new(
			[ 'commonName' => 'sns.amazonaws.com' ],
			$private_key,
			[ 'digest_alg' => 'sha256' ]
		);

		$cert = openssl_csr_sign( $csr, null, $private_key, 1, [ 'digest_alg' => 'sha256' ] );

		openssl_x509_export( $cert, $cert_pem );

		return [ $cert_pem, $private_key ];
	}
}
