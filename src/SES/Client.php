<?php
/**
 * Amazon SES v2 API client.
 *
 * @package LEAStudios\Mailer\SES
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\SES;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Mailer\Encryption\Options_Encryptor;

/**
 * Lightweight SES v2 HTTP client using wp_remote_post().
 */
class Client {

	/**
	 * Allowed AWS regions for SES.
	 *
	 * Single source of truth for both the runtime region check (in
	 * `is_allowed_region` below) and the admin settings dropdown
	 * (via `Settings_Page::region_choices`).
	 */
	public const ALLOWED_REGIONS = [
		'us-east-1',
		'us-east-2',
		'us-west-1',
		'us-west-2',
		'af-south-1',
		'ap-south-1',
		'ap-northeast-1',
		'ap-northeast-2',
		'ap-northeast-3',
		'ap-southeast-1',
		'ap-southeast-2',
		'ca-central-1',
		'eu-central-1',
		'eu-west-1',
		'eu-west-2',
		'eu-west-3',
		'eu-north-1',
		'eu-south-1',
		'il-central-1',
		'me-south-1',
		'sa-east-1',
	];

	/**
	 * Constructor.
	 *
	 * @param Options_Encryptor $encryptor The options encryptor.
	 * @param Signer            $signer    The AWS SigV4 signer.
	 */
	public function __construct(
		private readonly Options_Encryptor $encryptor,
		private readonly Signer $signer,
	) {}

	/**
	 * Send an email via SES v2 API.
	 *
	 * @param string   $from      Sender email address.
	 * @param string[] $to        Recipient email addresses.
	 * @param string   $subject   Email subject.
	 * @param string   $body_html HTML body content.
	 * @param string   $body_text Plain text body content.
	 * @param string[] $cc        CC addresses.
	 * @param string[] $bcc       BCC addresses.
	 * @param string[] $reply_to  Reply-To addresses.
	 * @return array{success: bool, message_id: string|null, error: string|null}
	 */
	public function send_email(
		string $from,
		array $to,
		string $subject,
		string $body_html,
		string $body_text = '',
		array $cc = [],
		array $bcc = [],
		array $reply_to = [],
	): array {
		$credentials = $this->get_credentials();

		if ( null === $credentials ) {
			return $this->credentials_error();
		}

		$body = $this->build_request_body( $from, $to, $subject, $body_html, $body_text, $cc, $bcc, $reply_to );

		/**
		 * Filter the JSON request body before sending to the SES API.
		 *
		 * Allows adding custom SES headers, tags, configuration sets, etc.
		 *
		 * @param array    $body_array The decoded request body array.
		 * @param string   $from       The sender address.
		 * @param string[] $to         The recipient addresses.
		 * @param string   $subject    The email subject.
		 */
		$body_array = apply_filters(
			'leastudios_mailer_ses_request_body',
			json_decode( $body, true ),
			$from,
			$to,
			$subject
		);

		return $this->dispatch( $credentials, (string) wp_json_encode( $body_array ) );
	}

	/**
	 * Send an email with attachments via SES v2 SendEmail (Raw content).
	 *
	 * Builds an RFC 5322 MIME message using the PHPMailer library bundled with
	 * WordPress core, then submits it to the same SES v2 endpoint as
	 * {@see self::send_email()} but using `Content.Raw` so attachments are
	 * preserved.
	 *
	 * @param string                                        $from_email  Sender email address.
	 * @param string                                        $from_name   Sender display name (may be empty).
	 * @param string[]                                      $to          Recipient email addresses.
	 * @param string                                        $subject     Email subject.
	 * @param string                                        $body_html   HTML body content.
	 * @param string                                        $body_text   Plain text body content.
	 * @param string[]                                      $cc          CC addresses.
	 * @param string[]                                      $bcc         BCC addresses.
	 * @param string[]                                      $reply_to    Reply-To addresses.
	 * @param array<int, array{name: string, path: string}> $attachments Validated attachments.
	 * @return array{success: bool, message_id: string|null, error: string|null}
	 */
	public function send_raw_email(
		string $from_email,
		string $from_name,
		array $to,
		string $subject,
		string $body_html,
		string $body_text = '',
		array $cc = [],
		array $bcc = [],
		array $reply_to = [],
		array $attachments = [],
	): array {
		$credentials = $this->get_credentials();

		if ( null === $credentials ) {
			return $this->credentials_error();
		}

		$raw_message = $this->build_raw_message(
			$from_email,
			$from_name,
			$to,
			$subject,
			$body_html,
			$body_text,
			$cc,
			$reply_to,
			$attachments
		);

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

		/**
		 * Filter the JSON request body before sending a Raw email to the SES API.
		 *
		 * Mirrors {@see leastudios_mailer_ses_request_body} but is fired only for
		 * the Raw (attachment-bearing) send path. The decoded body contains a
		 * `Content.Raw.Data` field whose value is the base64-encoded MIME blob.
		 *
		 * @param array    $body_array The decoded request body array.
		 * @param string   $from_email The sender address.
		 * @param string[] $to         The recipient addresses.
		 * @param string   $subject    The email subject.
		 */
		$body_array = apply_filters(
			'leastudios_mailer_ses_raw_request_body',
			$body_array,
			$from_email,
			$to,
			$subject
		);

		return $this->dispatch( $credentials, (string) wp_json_encode( $body_array ) );
	}

	/**
	 * Check if the configured credentials are valid by calling GetAccount.
	 *
	 * @return array{valid: bool, error: string|null}
	 */
	public function check_credentials(): array {
		$credentials = $this->get_credentials();

		if ( null === $credentials ) {
			return [
				'valid' => false,
				'error' => __( 'Credentials not configured or decryption failed.', 'leastudios-mailer' ),
			];
		}

		$url     = "https://email.{$credentials['region']}.amazonaws.com/v2/email/account";
		$headers = [ 'Content-Type' => 'application/json' ];

		$signed_headers = $this->signer->sign(
			'GET',
			$url,
			$headers,
			'',
			$credentials['access_key'],
			$credentials['secret_key'],
			$credentials['region']
		);

		$response = wp_remote_get(
			$url,
			[
				'headers' => $signed_headers,
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'valid' => false,
				'error' => $response->get_error_message(),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return [
				'valid' => true,
				'error' => null,
			];
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = $body['message'] ?? __( 'Unknown error.', 'leastudios-mailer' );

		return [
			'valid' => false,
			'error' => sprintf(
				/* translators: 1: HTTP status code, 2: error message. */
				__( 'SES returned HTTP %1$d: %2$s', 'leastudios-mailer' ),
				$code,
				$message
			),
		];
	}

	/**
	 * Check whether a sender address is a verified SES sending identity.
	 *
	 * SES authorises a `From` address when *either* the address itself or
	 * its domain is a verified identity, so this checks both: the address
	 * first, then — if the address is not verified — the domain (the part
	 * after the `@`). A site that verified its whole domain in SES, rather
	 * than each individual address, therefore still reports as healthy.
	 *
	 * @param string $email The sender email address to check.
	 * @return array{verified: bool, error: string|null}
	 */
	public function check_sender_identity( string $email ): array {
		$credentials = $this->get_credentials();

		if ( null === $credentials ) {
			return [
				'verified' => false,
				'error'    => __( 'Credentials not configured.', 'leastudios-mailer' ),
			];
		}

		$email_status = $this->fetch_identity( $email, $credentials );

		if ( $email_status['verified'] ) {
			return [
				'verified' => true,
				'error'    => null,
			];
		}

		// A failure other than "identity not found" (404) — a network error,
		// access denied, a 5xx — is a real problem that the domain lookup
		// would just hit again. Surface it rather than masking it behind a
		// generic "sender not verified" message.
		if ( 200 !== $email_status['http_code'] && 404 !== $email_status['http_code'] ) {
			return [
				'verified' => false,
				'error'    => $email_status['error'],
			];
		}

		$at_position = strrpos( $email, '@' );
		$domain      = false === $at_position ? '' : substr( $email, $at_position + 1 );

		if ( '' === $domain ) {
			return [
				'verified' => false,
				'error'    => $email_status['error'],
			];
		}

		$domain_status = $this->fetch_identity( $domain, $credentials );

		if ( $domain_status['verified'] ) {
			return [
				'verified' => true,
				'error'    => null,
			];
		}

		return [
			'verified' => false,
			'error'    => $this->unverified_sender_message( $email, $domain, $email_status, $domain_status ),
		];
	}

	/**
	 * Fetch the SES verification status of a single identity.
	 *
	 * The identity may be an email address or a domain — SES's
	 * `GetEmailIdentity` accepts either.
	 *
	 * @param string                                                        $identity    The identity (email address or domain).
	 * @param array{access_key: string, secret_key: string, region: string} $credentials The AWS credentials.
	 * @return array{verified: bool, error: string|null, http_code: int|null}
	 */
	private function fetch_identity( string $identity, array $credentials ): array {
		$encoded = rawurlencode( $identity );
		$url     = "https://email.{$credentials['region']}.amazonaws.com/v2/email/identities/{$encoded}";
		$headers = [ 'Content-Type' => 'application/json' ];

		$signed_headers = $this->signer->sign(
			'GET',
			$url,
			$headers,
			'',
			$credentials['access_key'],
			$credentials['secret_key'],
			$credentials['region']
		);

		$response = wp_remote_get(
			$url,
			[
				'headers' => $signed_headers,
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'verified'  => false,
				'error'     => $response->get_error_message(),
				'http_code' => null,
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return [
				'verified'  => false,
				'error'     => is_array( $body ) && isset( $body['message'] )
					? (string) $body['message']
					: __( 'Identity not found.', 'leastudios-mailer' ),
				'http_code' => $code,
			];
		}

		$verified = is_array( $body ) && true === ( $body['VerifiedForSendingStatus'] ?? false );

		return [
			'verified'  => $verified,
			'error'     => $verified ? null : __( 'Identity exists but is not verified for sending.', 'leastudios-mailer' ),
			'http_code' => 200,
		];
	}

	/**
	 * Build a human-readable message explaining why a sender is not verified.
	 *
	 * @param string                                                         $email         The sender address.
	 * @param string                                                         $domain        The sender domain.
	 * @param array{verified: bool, error: string|null, http_code: int|null} $email_status  Result of the address identity lookup.
	 * @param array{verified: bool, error: string|null, http_code: int|null} $domain_status Result of the domain identity lookup.
	 * @return string
	 */
	private function unverified_sender_message( string $email, string $domain, array $email_status, array $domain_status ): string {
		if ( 200 === $domain_status['http_code'] ) {
			return sprintf(
				/* translators: %s: sender domain. */
				__( 'The domain %s is registered in SES but is not verified for sending yet.', 'leastudios-mailer' ),
				$domain
			);
		}

		if ( 200 === $email_status['http_code'] ) {
			return sprintf(
				/* translators: %s: sender email address. */
				__( 'The address %s is registered in SES but is not verified for sending yet.', 'leastudios-mailer' ),
				$email
			);
		}

		return sprintf(
			/* translators: 1: sender email address, 2: sender domain. */
			__( 'Neither the address %1$s nor its domain %2$s is a verified SES sender identity.', 'leastudios-mailer' ),
			$email,
			$domain
		);
	}

	/**
	 * Get the SES v2 API endpoint URL.
	 *
	 * @param string $region AWS region.
	 * @return string The endpoint URL.
	 */
	private function get_endpoint( string $region ): string {
		return "https://email.{$region}.amazonaws.com/v2/email/outbound-emails";
	}

	/**
	 * Build the JSON request body for SendEmail.
	 *
	 * @param string   $from      Sender address.
	 * @param string[] $to        To addresses.
	 * @param string   $subject   Subject line.
	 * @param string   $body_html HTML body.
	 * @param string   $body_text Text body.
	 * @param string[] $cc        CC addresses.
	 * @param string[] $bcc       BCC addresses.
	 * @param string[] $reply_to  Reply-To addresses.
	 * @return string JSON-encoded request body.
	 */
	private function build_request_body(
		string $from,
		array $to,
		string $subject,
		string $body_html,
		string $body_text,
		array $cc,
		array $bcc,
		array $reply_to,
	): string {
		$destination = [ 'ToAddresses' => array_values( $to ) ];

		if ( ! empty( $cc ) ) {
			$destination['CcAddresses'] = array_values( $cc );
		}

		if ( ! empty( $bcc ) ) {
			$destination['BccAddresses'] = array_values( $bcc );
		}

		$body_content = [];

		if ( '' !== $body_html ) {
			$body_content['Html'] = [
				'Data'    => $body_html,
				'Charset' => 'UTF-8',
			];
		}

		if ( '' !== $body_text ) {
			$body_content['Text'] = [
				'Data'    => $body_text,
				'Charset' => 'UTF-8',
			];
		}

		if ( empty( $body_content ) ) {
			$body_content['Text'] = [
				'Data'    => '',
				'Charset' => 'UTF-8',
			];
		}

		$payload = [
			'FromEmailAddress' => $from,
			'Destination'      => $destination,
			'Content'          => [
				'Simple' => [
					'Subject' => [
						'Data'    => $subject,
						'Charset' => 'UTF-8',
					],
					'Body'    => $body_content,
				],
			],
		];

		if ( ! empty( $reply_to ) ) {
			$payload['ReplyToAddresses'] = array_values( $reply_to );
		}

		return (string) wp_json_encode( $payload );
	}

	/**
	 * Send a JSON body to the SES v2 SendEmail endpoint and fire the response action.
	 *
	 * @param array{access_key: string, secret_key: string, region: string} $credentials The AWS credentials.
	 * @param string                                                        $body        The JSON request body.
	 * @return array{success: bool, message_id: string|null, error: string|null}
	 */
	private function dispatch( array $credentials, string $body ): array {
		$url    = $this->get_endpoint( $credentials['region'] );
		$result = $this->make_request( $url, $body, $credentials );

		/**
		 * Fires after the SES API response is received.
		 *
		 * @param array  $result The result array with keys: success, message_id, error.
		 * @param string $url    The SES API endpoint URL.
		 * @param string $body   The JSON request body that was sent.
		 */
		do_action( 'leastudios_mailer_ses_response', $result, $url, $body );

		return $result;
	}

	/**
	 * Build a raw RFC 5322 MIME message using PHPMailer.
	 *
	 * Returns null if PHPMailer raises an exception while assembling the
	 * message (for example, when an attachment path becomes unreadable
	 * between validation and sending).
	 *
	 * BCC recipients are intentionally NOT included in the MIME message; they
	 * are delivered via the SES envelope (Destination.BccAddresses) only, so
	 * the BCC list is not exposed in the headers each recipient receives.
	 *
	 * @param string                                        $from_email  Sender email.
	 * @param string                                        $from_name   Sender display name.
	 * @param string[]                                      $to          To addresses.
	 * @param string                                        $subject     Subject.
	 * @param string                                        $body_html   HTML body.
	 * @param string                                        $body_text   Text body.
	 * @param string[]                                      $cc          CC addresses.
	 * @param string[]                                      $reply_to    Reply-To addresses.
	 * @param array<int, array{name: string, path: string}> $attachments Validated attachments.
	 * @return string|null The raw RFC 5322 message, or null on failure.
	 */
	private function build_raw_message(
		string $from_email,
		string $from_name,
		array $to,
		string $subject,
		string $body_html,
		string $body_text,
		array $cc,
		array $reply_to,
		array $attachments,
	): ?string {
		// PHPMailer ships with WordPress core, but is not autoloaded until
		// wp_mail() proper would otherwise need it. Load it on demand so we
		// can use the same MIME builder WordPress itself uses.
		if ( ! class_exists( \PHPMailer\PHPMailer\PHPMailer::class, false ) ) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php'; // @phpstan-ignore-line constant.notFound
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php'; // @phpstan-ignore-line constant.notFound
		}

		try {
			$phpmailer           = new \PHPMailer\PHPMailer\PHPMailer( true );
			$phpmailer->CharSet  = 'UTF-8';
			$phpmailer->Encoding = 'base64';
			$phpmailer->XMailer  = ' ';
			$phpmailer->Sender   = $from_email;

			$phpmailer->setFrom( $from_email, $from_name, false );

			foreach ( $to as $address ) {
				$phpmailer->addAddress( $address );
			}

			foreach ( $cc as $address ) {
				$phpmailer->addCC( $address );
			}

			// Intentionally do NOT call addBCC(): BCC recipients are an
			// envelope-only concern delivered via SES Destination.BccAddresses
			// in build_raw_request_body(). Including BCC in the MIME headers
			// would expose those addresses to every recipient.

			foreach ( $reply_to as $address ) {
				$phpmailer->addReplyTo( $address );
			}

			$phpmailer->Subject = $subject;

			if ( '' !== $body_html ) {
				$phpmailer->isHTML( true );
				$phpmailer->Body = $body_html;
				if ( '' !== $body_text ) {
					$phpmailer->AltBody = $body_text;
				}
			} else {
				$phpmailer->Body = $body_text;
			}

			foreach ( $attachments as $attachment ) {
				$phpmailer->addAttachment( $attachment['path'], $attachment['name'] );
			}

			if ( ! $phpmailer->preSend() ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- third-party PHPMailer property name.
				$this->log_phpmailer_error( $phpmailer->ErrorInfo );
				return null;
			}

			return $phpmailer->getSentMIMEMessage();
		} catch ( \PHPMailer\PHPMailer\Exception $e ) {
			$this->log_phpmailer_error( $e->getMessage() );
			return null;
		}
	}

	/**
	 * Surface a PHPMailer error so admins debugging "Failed to build raw
	 * MIME message" don't have to enable a debugger to find the cause.
	 *
	 * Gated on WP_DEBUG so the production error log doesn't acquire a
	 * line per failed build, but the message itself is just an internal
	 * MIME-construction error (no PII).
	 *
	 * @param string $message PHPMailer error string.
	 * @return void
	 */
	private function log_phpmailer_error( string $message ): void {
		if ( '' === $message || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[leaStudios Mailer] PHPMailer: ' . $message );
	}

	/**
	 * Build the SES v2 SendEmail JSON payload for the Raw content path.
	 *
	 * @param string   $from_email   Envelope sender.
	 * @param string[] $to           To addresses.
	 * @param string[] $cc           CC addresses.
	 * @param string[] $bcc          BCC addresses.
	 * @param string[] $reply_to     Reply-To addresses.
	 * @param string   $raw_message  The RFC 5322 MIME message.
	 * @return array<string, mixed>
	 */
	private function build_raw_request_body(
		string $from_email,
		array $to,
		array $cc,
		array $bcc,
		array $reply_to,
		string $raw_message,
	): array {
		$destination = [ 'ToAddresses' => array_values( $to ) ];

		if ( ! empty( $cc ) ) {
			$destination['CcAddresses'] = array_values( $cc );
		}

		if ( ! empty( $bcc ) ) {
			$destination['BccAddresses'] = array_values( $bcc );
		}

		$payload = [
			'FromEmailAddress' => $from_email,
			'Destination'      => $destination,
			'Content'          => [
				'Raw' => [
					// SES v2 expects the MIME blob base64-encoded inside the JSON payload.
					'Data' => base64_encode( $raw_message ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				],
			],
		];

		if ( ! empty( $reply_to ) ) {
			$payload['ReplyToAddresses'] = array_values( $reply_to );
		}

		return $payload;
	}

	/**
	 * Build the standard "credentials missing" failure result.
	 *
	 * @return array{success: bool, message_id: string|null, error: string|null}
	 */
	private function credentials_error(): array {
		return [
			'success'    => false,
			'message_id' => null,
			'error'      => __( 'SES credentials are not configured or could not be decrypted.', 'leastudios-mailer' ),
		];
	}

	/**
	 * Make a signed HTTP request to SES with transient-error retry.
	 *
	 * Retries on:
	 *  - `WP_Error` from {@see wp_remote_post()} (connection / timeout / DNS)
	 *  - HTTP 429 (Throttling) and 5xx (server-side transient failures)
	 *
	 * Does NOT retry on 4xx other than 429 — those are configuration errors
	 * (bad creds, unverified sender, invalid request) that won't resolve
	 * with a retry and would just delay surfacing the real failure.
	 *
	 * @param string                                                        $url         The endpoint URL.
	 * @param string                                                        $body        The request body.
	 * @param array{access_key: string, secret_key: string, region: string} $credentials The AWS credentials.
	 * @return array{success: bool, message_id: string|null, error: string|null}
	 */
	private function make_request( string $url, string $body, array $credentials ): array {
		/**
		 * Filter the total number of attempts (initial + retries) for an SES request.
		 *
		 * Set to 1 to disable retries. Capped at 1..5.
		 *
		 * @param int $max_attempts Default 3.
		 */
		$max_attempts = (int) apply_filters( 'leastudios_mailer_ses_max_attempts', 3 );
		$max_attempts = max( 1, min( 5, $max_attempts ) );

		/**
		 * Filter the base backoff delay (milliseconds) between retry attempts.
		 *
		 * Final delay is `base * 2^attempt + random(0, base/2)` (full jitter).
		 *
		 * @param int $base_delay_ms Default 500.
		 */
		$base_delay_ms = (int) apply_filters( 'leastudios_mailer_ses_retry_delay_ms', 500 );
		$base_delay_ms = max( 0, $base_delay_ms );

		$result = [
			'success'    => false,
			'message_id' => null,
			'error'      => __( 'Unknown error.', 'leastudios-mailer' ),
		];

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$attempt_result = $this->attempt_request( $url, $body, $credentials );
			$result         = $attempt_result;

			if ( $attempt_result['success'] || ! $attempt_result['retryable'] || $attempt >= $max_attempts ) {
				break;
			}

			if ( $base_delay_ms > 0 ) {
				$delay_ms = $base_delay_ms * ( 2 ** ( $attempt - 1 ) ) + random_int( 0, max( 0, (int) ( $base_delay_ms / 2 ) ) );
				usleep( $delay_ms * 1000 );
			}
		}

		return [
			'success'    => $result['success'],
			'message_id' => $result['message_id'],
			'error'      => $result['error'],
		];
	}

	/**
	 * Perform a single signed POST to SES and classify the outcome.
	 *
	 * @param string                                                        $url         The endpoint URL.
	 * @param string                                                        $body        The request body.
	 * @param array{access_key: string, secret_key: string, region: string} $credentials The AWS credentials.
	 * @return array{success: bool, message_id: string|null, error: string|null, retryable: bool}
	 */
	private function attempt_request( string $url, string $body, array $credentials ): array {
		$headers = [ 'Content-Type' => 'application/json' ];

		$signed_headers = $this->signer->sign(
			'POST',
			$url,
			$headers,
			$body,
			$credentials['access_key'],
			$credentials['secret_key'],
			$credentials['region']
		);

		$response = wp_remote_post(
			$url,
			[
				'headers' => $signed_headers,
				'body'    => $body,
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'success'    => false,
				'message_id' => null,
				'error'      => $response->get_error_message(),
				'retryable'  => true,
			];
		}

		$code          = (int) wp_remote_retrieve_response_code( $response );
		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return [
				'success'    => true,
				'message_id' => $response_body['MessageId'] ?? null,
				'error'      => null,
				'retryable'  => false,
			];
		}

		return [
			'success'    => false,
			'message_id' => null,
			'error'      => sprintf(
				/* translators: 1: HTTP status code, 2: error message. */
				__( 'SES API error (HTTP %1$d): %2$s', 'leastudios-mailer' ),
				$code,
				$response_body['message'] ?? __( 'Unknown error.', 'leastudios-mailer' )
			),
			'retryable'  => ( 429 === $code || $code >= 500 ),
		];
	}

	/**
	 * Get decrypted credentials from options.
	 *
	 * @return array{access_key: string, secret_key: string, region: string}|null
	 */
	private function get_credentials(): ?array {
		$options = get_option( 'leastudios_mailer_options', [] );

		if ( ! is_array( $options ) ) {
			return null;
		}

		$access_key = $this->encryptor->decrypt( $options['access_key'] ?? '' );
		$secret_key = $this->encryptor->decrypt( $options['secret_key'] ?? '' );
		$region     = $options['region'] ?? 'us-east-1';

		if ( '' === $access_key || '' === $secret_key ) {
			return null;
		}

		if ( ! in_array( $region, self::ALLOWED_REGIONS, true ) ) {
			$region = 'us-east-1';
		}

		return [
			'access_key' => $access_key,
			'secret_key' => $secret_key,
			'region'     => $region,
		];
	}
}
