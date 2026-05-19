<?php
/**
 * Amazon SNS webhook endpoint for delivery tracking.
 *
 * @package LEAStudios\Mailer\Webhook
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Webhook;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Mailer\Log\Email_Logger;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST API controller for receiving SNS notifications.
 */
class SNS_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @param Email_Logger $logger The email logger.
	 */
	public function __construct(
		private readonly Email_Logger $logger,
	) {
		$this->namespace = 'leastudios-mailer/v1';
		$this->rest_base = 'sns-webhook';
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_notification' ],
				'permission_callback' => [ $this, 'verify_request' ],
			]
		);
	}

	/**
	 * Verify the incoming SNS request.
	 *
	 * SNS cannot use WP auth, so we verify the cryptographic signature.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error
	 */
	public function verify_request( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) || ! isset( $body['Type'] ) ) {
			return new WP_Error(
				'invalid_request',
				__( 'Invalid SNS message format.', 'leastudios-mailer' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $this->verify_sns_signature( $body ) ) {
			return new WP_Error(
				'invalid_signature',
				__( 'SNS signature verification failed.', 'leastudios-mailer' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Handle an incoming SNS notification.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_notification( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$type = $body['Type'] ?? '';

		switch ( $type ) {
			case 'SubscriptionConfirmation':
				$this->handle_subscription_confirmation( $body );
				break;

			case 'Notification':
				$message = json_decode( $body['Message'] ?? '{}', true );
				if ( is_array( $message ) ) {
					$this->process_notification( $message );
				}
				break;
		}

		return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	/**
	 * Auto-confirm an SNS subscription.
	 *
	 * @param array<string, mixed> $body The SNS message body.
	 * @return void
	 */
	private function handle_subscription_confirmation( array $body ): void {
		$subscribe_url = $body['SubscribeURL'] ?? '';

		if ( '' === $subscribe_url ) {
			return;
		}

		// Validate the URL is from AWS.
		$parsed = wp_parse_url( $subscribe_url );
		$host   = $parsed['host'] ?? '';

		if ( ! preg_match( '/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host ) ) {
			return;
		}

		wp_remote_get( $subscribe_url, [ 'timeout' => 10 ] );
	}

	/**
	 * Process an SES notification message.
	 *
	 * @param array<string, mixed> $message The parsed notification message.
	 * @return void
	 */
	private function process_notification( array $message ): void {
		$notification_type = $message['notificationType'] ?? $message['eventType'] ?? '';

		switch ( $notification_type ) {
			case 'Bounce':
				$this->handle_bounce( $message );
				break;

			case 'Complaint':
				$this->handle_complaint( $message );
				break;

			case 'Delivery':
				$this->handle_delivery( $message );
				break;
		}
	}

	/**
	 * Handle a bounce notification.
	 *
	 * @param array<string, mixed> $message The notification message.
	 * @return void
	 */
	private function handle_bounce( array $message ): void {
		$message_id = $this->extract_message_id( $message );
		if ( null !== $message_id ) {
			$bounce_type = $message['bounce']['bounceType'] ?? 'Unknown';
			$this->logger->update_status( $message_id, 'bounced', "Bounce type: {$bounce_type}" );
		}
	}

	/**
	 * Handle a complaint notification.
	 *
	 * @param array<string, mixed> $message The notification message.
	 * @return void
	 */
	private function handle_complaint( array $message ): void {
		$message_id = $this->extract_message_id( $message );
		if ( null !== $message_id ) {
			$feedback_type = $message['complaint']['complaintFeedbackType'] ?? 'Unknown';
			$this->logger->update_status( $message_id, 'complained', "Feedback type: {$feedback_type}" );
		}
	}

	/**
	 * Handle a delivery notification.
	 *
	 * @param array<string, mixed> $message The notification message.
	 * @return void
	 */
	private function handle_delivery( array $message ): void {
		$message_id = $this->extract_message_id( $message );
		if ( null !== $message_id ) {
			$this->logger->update_status( $message_id, 'delivered' );
		}
	}

	/**
	 * Extract the SES message ID from a notification.
	 *
	 * @param array<string, mixed> $message The parsed notification message.
	 * @return string|null The message ID, or null if not found.
	 */
	private function extract_message_id( array $message ): ?string {
		return $message['mail']['messageId'] ?? null;
	}

	/**
	 * Verify the SNS message signature.
	 *
	 * Selects the digest algorithm based on the SignatureVersion field:
	 * version "1" uses SHA1 (legacy default) and version "2" uses SHA256
	 * (AWS-recommended). Any other value is rejected. Trust in the signing
	 * certificate is anchored by the publicly-trusted CA bundle that
	 * wp_remote_get validates against when fetching SigningCertURL over HTTPS.
	 *
	 * The Timestamp field is checked against the local clock so a captured
	 * notification body cannot be replayed indefinitely. The window defaults
	 * to one hour past / five minutes future, and is filterable via
	 * `leastudios_mailer_sns_max_age_seconds` and
	 * `leastudios_mailer_sns_future_skew_seconds`.
	 *
	 * @param array<string, mixed> $message The SNS message envelope (Type, Signature, SignatureVersion, SigningCertURL, Timestamp, …).
	 * @return bool Whether the signature is valid and the message is within the replay window.
	 */
	private function verify_sns_signature( array $message ): bool {
		if ( ! $this->is_within_replay_window( $message['Timestamp'] ?? '' ) ) {
			return false;
		}

		$signature_version = $message['SignatureVersion'] ?? '';
		$algorithm         = match ( (string) $signature_version ) {
			'1'     => OPENSSL_ALGO_SHA1,
			'2'     => OPENSSL_ALGO_SHA256,
			default => null,
		};

		if ( null === $algorithm ) {
			return false;
		}

		$cert_url = $message['SigningCertURL'] ?? '';

		if ( '' === $cert_url ) {
			return false;
		}

		// Validate cert URL is from Amazon SNS to prevent SSRF.
		$parsed = wp_parse_url( $cert_url );
		$host   = $parsed['host'] ?? '';
		$scheme = $parsed['scheme'] ?? '';

		if ( 'https' !== $scheme || ! preg_match( '/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host ) ) {
			return false;
		}

		// Fetch the certificate with transient caching. sslverify is the WP
		// default but we set it explicitly: TLS chain validation is what
		// anchors our trust in the cert we're about to use to verify the
		// message signature.
		$cache_key = 'leastudios_mailer_sns_cert_' . md5( $cert_url );
		$cert_pem  = get_transient( $cache_key );

		if ( false === $cert_pem ) {
			$response = wp_remote_get(
				$cert_url,
				[
					'timeout'   => 10,
					'sslverify' => true,
				]
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$cert_pem = wp_remote_retrieve_body( $response );

			if ( '' === $cert_pem ) {
				return false;
			}

			set_transient( $cache_key, $cert_pem, HOUR_IN_SECONDS );
		}

		$string_to_sign = $this->build_string_to_sign( $message );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$signature = base64_decode( $message['Signature'] ?? '', true );

		if ( false === $signature || '' === $string_to_sign ) {
			return false;
		}

		$public_key = openssl_pkey_get_public( $cert_pem );

		if ( false === $public_key ) {
			return false;
		}

		return 1 === openssl_verify( $string_to_sign, $signature, $public_key, $algorithm );
	}

	/**
	 * Check whether the SNS Timestamp falls within the accepted replay window.
	 *
	 * @param mixed $timestamp The ISO 8601 timestamp from the SNS envelope.
	 * @return bool True if within window. False on missing/malformed timestamp.
	 */
	private function is_within_replay_window( mixed $timestamp ): bool {
		if ( ! is_string( $timestamp ) || '' === $timestamp ) {
			return false;
		}

		try {
			$message_time = new \DateTimeImmutable( $timestamp );
		} catch ( \Exception $e ) {
			return false;
		}

		/**
		 * Filter the maximum age (seconds) for accepted SNS notifications.
		 *
		 * Defaults to one hour. AWS recommends rejecting messages older than
		 * this to prevent replay of captured notification bodies.
		 *
		 * @param int $max_age_seconds Default 3600.
		 */
		$max_age_seconds = (int) apply_filters( 'leastudios_mailer_sns_max_age_seconds', HOUR_IN_SECONDS );

		/**
		 * Filter the clock-skew tolerance (seconds) for future-dated SNS
		 * notifications. A small forward skew protects against legitimate
		 * notifications being rejected when the local clock lags AWS.
		 *
		 * @param int $future_skew_seconds Default 300.
		 */
		$future_skew_seconds = (int) apply_filters( 'leastudios_mailer_sns_future_skew_seconds', 5 * MINUTE_IN_SECONDS );

		$now_ts     = time();
		$message_ts = $message_time->getTimestamp();

		if ( $message_ts > $now_ts + $future_skew_seconds ) {
			return false;
		}

		if ( $message_ts < $now_ts - $max_age_seconds ) {
			return false;
		}

		return true;
	}

	/**
	 * Build the string to sign for SNS signature verification.
	 *
	 * @param array<string, mixed> $message The SNS message envelope.
	 * @return string The string to sign.
	 */
	private function build_string_to_sign( array $message ): string {
		$type = $message['Type'] ?? '';

		if ( 'Notification' === $type ) {
			$keys = [ 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' ];
		} else {
			$keys = [ 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' ];
		}

		$string = '';

		foreach ( $keys as $key ) {
			if ( isset( $message[ $key ] ) ) {
				$string .= $key . "\n" . $message[ $key ] . "\n";
			}
		}

		return $string;
	}
}
