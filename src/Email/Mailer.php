<?php
/**
 * WordPress wp_mail() override via SES.
 *
 * @package LEAStudios\Mailer\Email
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Mailer\Log\Email_Logger;
use LEAStudios\Mailer\SES\Client;

/**
 * Intercepts wp_mail() and routes emails through Amazon SES.
 */
class Mailer {

	/**
	 * Constructor.
	 *
	 * @param Client       $ses_client The SES API client.
	 * @param Email_Logger $logger     The email logger.
	 */
	public function __construct(
		private readonly Client $ses_client,
		private readonly Email_Logger $logger,
	) {}

	/**
	 * Hook into WordPress mail system.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'pre_wp_mail', [ $this, 'send' ], 10, 2 );
	}

	/**
	 * Handle wp_mail() interception via pre_wp_mail filter.
	 *
	 * @param null|bool $result Null to allow default behavior, bool to short-circuit.
	 * @param array     $atts   The wp_mail() arguments.
	 * @return bool|null True on success, false on failure.
	 */
	public function send( $result, array $atts ) {
		$options = get_option( 'leastudios_mailer_options', [] );

		if ( ! is_array( $options ) || empty( $options['enabled'] ) ) {
			return null;
		}

		/**
		 * Filter whether the mailer should intercept this email.
		 *
		 * Return false to let WordPress handle the email with its default transport
		 * instead of routing through Amazon SES.
		 *
		 * @param bool  $should_intercept Whether to intercept. Default true.
		 * @param array $atts             The original wp_mail() arguments.
		 */
		$should_intercept = apply_filters( 'leastudios_mailer_should_intercept', true, $atts );

		if ( ! $should_intercept ) {
			return null;
		}

		$to      = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', $atts['to'] );
		$to      = array_map( 'trim', $to );
		$subject = $atts['subject'] ?? '';
		$message = $atts['message'] ?? '';
		$headers = $atts['headers'] ?? [];

		$parsed = $this->parse_headers( $headers );

		$from_name  = '' !== $parsed['from_name'] ? $parsed['from_name'] : ( $options['from_name'] ?? get_option( 'blogname' ) );
		$from_email = '' !== $parsed['from_email'] ? $parsed['from_email'] : ( $options['from_email'] ?? get_option( 'admin_email' ) );

		/** This filter is documented in wp-includes/pluggable.php. */
		$from_email = apply_filters( 'wp_mail_from', $from_email );

		/** This filter is documented in wp-includes/pluggable.php. */
		$from_name = apply_filters( 'wp_mail_from_name', $from_name );

		$from = $from_name ? "{$from_name} <{$from_email}>" : $from_email;

		$content_type = '' !== $parsed['content_type'] ? $parsed['content_type'] : 'text/plain';
		$is_html      = ( 'text/html' === strtolower( $content_type ) );

		$body_html = $is_html ? $message : '';
		$body_text = $is_html ? '' : $message;

		$raw_attachments = isset( $atts['attachments'] ) ? (array) $atts['attachments'] : [];
		$attachments     = $this->normalize_attachments( $raw_attachments );

		/**
		 * Filter the full email arguments before sending via SES.
		 *
		 * Return null to skip sending the email entirely.
		 *
		 * @param array $args The email arguments with keys: from, to, subject,
		 *                    body_html, body_text, cc, bcc, reply_to, headers,
		 *                    attachments.
		 * @param array $atts The original wp_mail() arguments.
		 * @return array|null Filtered args, or null to skip sending.
		 */
		$filtered_args = apply_filters(
			'leastudios_mailer_pre_send',
			[
				'from'        => $from,
				'to'          => $to,
				'subject'     => $subject,
				'body_html'   => $body_html,
				'body_text'   => $body_text,
				'cc'          => $parsed['cc'],
				'bcc'         => $parsed['bcc'],
				'reply_to'    => $parsed['reply_to'],
				'headers'     => $headers,
				'attachments' => $attachments,
			],
			$atts
		);

		if ( null === $filtered_args ) {
			return null;
		}

		$from        = $filtered_args['from'];
		$to          = $filtered_args['to'];
		$subject     = $filtered_args['subject'];
		$body_html   = $filtered_args['body_html'];
		$body_text   = $filtered_args['body_text'];
		$attachments = isset( $filtered_args['attachments'] ) ? (array) $filtered_args['attachments'] : [];

		if ( ! empty( $attachments ) ) {
			$ses_result = $this->ses_client->send_raw_email(
				$from_email,
				$from_name,
				$to,
				$subject,
				$body_html,
				$body_text,
				$filtered_args['cc'],
				$filtered_args['bcc'],
				$filtered_args['reply_to'],
				$attachments
			);
		} else {
			$ses_result = $this->ses_client->send_email(
				$from,
				$to,
				$subject,
				$body_html,
				$body_text,
				$filtered_args['cc'],
				$filtered_args['bcc'],
				$filtered_args['reply_to']
			);
		}

		$to_string = implode( ', ', $to );
		$status    = $ses_result['success'] ? 'sent' : 'failed';

		$this->logger->log(
			$to_string,
			$subject,
			$status,
			$ses_result['message_id'],
			$ses_result['error']
		);

		/**
		 * Fires after an email is sent (or fails) via SES.
		 *
		 * @param array  $ses_result The SES send result.
		 * @param array  $atts       The original wp_mail arguments.
		 * @param string $status     The log status.
		 */
		do_action( 'leastudios_mailer_email_sent', $ses_result, $atts, $status );

		return $ses_result['success'];
	}

	/**
	 * Normalize a wp_mail-style attachments array.
	 *
	 * Accepts both forms accepted by core `wp_mail()`:
	 *
	 *   - Legacy: `[ '/abs/path/to/file.pdf', ... ]`
	 *   - WP 5.6+: `[ 'display-name.pdf' => '/abs/path/to/file.pdf', ... ]`
	 *
	 * Each entry is checked for file existence and readability. Unreadable
	 * entries are dropped and reported via the
	 * {@see 'leastudios_mailer_attachments_skipped'} action so site owners can
	 * log or alert on them.
	 *
	 * @param array<int|string, mixed> $attachments Raw attachments array.
	 * @return array<int, array{name: string, path: string}> Validated attachments.
	 */
	private function normalize_attachments( array $attachments ): array {
		if ( empty( $attachments ) ) {
			return [];
		}

		$normalized = [];
		$skipped    = [];

		foreach ( $attachments as $key => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				$skipped[ (string) $key ] = $value;
				continue;
			}

			if ( ! is_file( $value ) || ! is_readable( $value ) ) {
				$skipped[ (string) $key ] = $value;
				continue;
			}

			$name = is_string( $key ) && '' !== $key ? $key : basename( $value );

			$normalized[] = [
				'name' => $name,
				'path' => $value,
			];
		}

		if ( ! empty( $skipped ) ) {
			/**
			 * Fires when one or more attachments cannot be read and are dropped.
			 *
			 * Each entry preserves its original key so handlers can distinguish
			 * between the indexed and keyed `wp_mail()` attachment forms.
			 *
			 * @param array $skipped Attachment entries that were dropped.
			 */
			do_action( 'leastudios_mailer_attachments_skipped', $skipped );
		}

		return $normalized;
	}

	/**
	 * Parse wp_mail-style headers.
	 *
	 * @param string|array $headers Raw headers.
	 * @return array{from_name: string, from_email: string, content_type: string, cc: string[], bcc: string[], reply_to: string[]}
	 */
	private function parse_headers( $headers ): array {
		$result = [
			'from_name'    => '',
			'from_email'   => '',
			'content_type' => '',
			'cc'           => [],
			'bcc'          => [],
			'reply_to'     => [],
		];

		if ( empty( $headers ) ) {
			return $result;
		}

		if ( is_string( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		foreach ( $headers as $header ) {
			if ( ! str_contains( $header, ':' ) ) {
				continue;
			}

			[ $name, $value ] = explode( ':', $header, 2 );

			$name  = strtolower( trim( $name ) );
			$value = trim( $value );

			switch ( $name ) {
				case 'from':
					if ( preg_match( '/(.+)<(.+)>/', $value, $matches ) ) {
						$result['from_name']  = trim( $matches[1], " \t\n\r\0\x0B\"" );
						$result['from_email'] = trim( $matches[2] );
					} else {
						$result['from_email'] = trim( $value );
					}
					break;
				case 'content-type':
					if ( str_contains( $value, ';' ) ) {
						[ $type ]               = explode( ';', $value );
						$result['content_type'] = trim( $type );
					} else {
						$result['content_type'] = $value;
					}
					break;
				case 'cc':
					$result['cc'] = array_merge( $result['cc'], array_map( 'trim', explode( ',', $value ) ) );
					break;
				case 'bcc':
					$result['bcc'] = array_merge( $result['bcc'], array_map( 'trim', explode( ',', $value ) ) );
					break;
				case 'reply-to':
					$result['reply_to'] = array_merge( $result['reply_to'], array_map( 'trim', explode( ',', $value ) ) );
					break;
			}
		}

		return $result;
	}
}
