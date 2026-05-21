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
	 * @param null|bool            $result Null to allow default behavior, bool to short-circuit.
	 * @param array<string, mixed> $atts   The wp_mail() arguments (to/subject/message/headers/attachments).
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

		// Match core wp_mail()'s content-type resolution: an explicit header
		// in $atts wins, otherwise the wp_mail_content_type filter is
		// consulted, with text/plain as the final fallback. Plugins (notably
		// leastudios-email-templates) rely on this filter to opt every
		// outgoing email into HTML.
		if ( '' !== $parsed['content_type'] ) {
			$content_type = $parsed['content_type'];
		} else {
			/** This filter is documented in wp-includes/pluggable.php. */
			$content_type = (string) apply_filters( 'wp_mail_content_type', 'text/plain' );
		}

		$is_html = ( 'text/html' === strtolower( $content_type ) );

		$body_html = $is_html ? $message : '';
		$body_text = $is_html ? '' : $message;

		$raw_attachments = isset( $atts['attachments'] ) ? (array) $atts['attachments'] : [];
		$normalized      = $this->normalize_attachments( $raw_attachments );
		$attachments     = $normalized['attachments'];
		$skipped         = $normalized['skipped'];

		/**
		 * Filter the full email arguments before sending via SES.
		 *
		 * Return null to drop the email entirely — neither SES nor the default
		 * WordPress transport will send it. (We short-circuit `pre_wp_mail`
		 * with `false`, which core treats as "already handled, do not send".)
		 *
		 * @param array $args The email arguments with keys: from, to, subject,
		 *                    body_html, body_text, cc, bcc, reply_to, headers,
		 *                    attachments.
		 * @param array $atts The original wp_mail() arguments.
		 * @return array|null Filtered args, or null to drop the send.
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
			return false;
		}

		$from        = $filtered_args['from'];
		$to          = $filtered_args['to'];
		$subject     = $filtered_args['subject'];
		$body_html   = $filtered_args['body_html'];
		$body_text   = $filtered_args['body_text'];
		$attachments = isset( $filtered_args['attachments'] ) ? (array) $filtered_args['attachments'] : [];

		// Re-parse the (possibly filter-overridden) `from` so the raw-send
		// path and the log entry use the same sender as the Simple path.
		// Without this, a `leastudios_mailer_pre_send` listener that rewrites
		// `from` would silently lose its override on attachment-bearing
		// emails (the raw path takes email + name as separate args).
		$split      = self::split_from_address( $from );
		$from_email = $split['email'];
		$from_name  = $split['name'];

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
			$this->log_error_message( $ses_result['error'], $skipped ),
			$from_email
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
	 * @return array{attachments: array<int, array{name: string, path: string}>, skipped: array<int|string, mixed>} The validated attachments plus the entries that were skipped.
	 */
	private function normalize_attachments( array $attachments ): array {
		if ( empty( $attachments ) ) {
			return [
				'attachments' => [],
				'skipped'     => [],
			];
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

		return [
			'attachments' => $normalized,
			'skipped'     => $skipped,
		];
	}

	/**
	 * Combine the SES send error (if any) with a note about skipped
	 * attachments into the single message stored in the email log.
	 *
	 * @param string|null              $ses_error The SES send error, or null on success.
	 * @param array<int|string, mixed> $skipped Attachment entries that were dropped.
	 * @return string|null The combined message, or null when there is nothing to record.
	 */
	private function log_error_message( ?string $ses_error, array $skipped ): ?string {
		$note = $this->skipped_attachments_note( $skipped );

		if ( '' === $note ) {
			return $ses_error;
		}

		if ( null === $ses_error || '' === $ses_error ) {
			return $note;
		}

		return $ses_error . ' ' . $note;
	}

	/**
	 * Build a human-readable note naming the attachments that were skipped.
	 *
	 * For the keyed `wp_mail()` form the array key is the caller's intended
	 * display name and is used directly; for the legacy indexed form the
	 * file's base name is used instead.
	 *
	 * @param array<int|string, mixed> $skipped Attachment entries that were dropped.
	 * @return string The note, or an empty string when nothing was skipped.
	 */
	private function skipped_attachments_note( array $skipped ): string {
		if ( empty( $skipped ) ) {
			return '';
		}

		$names = [];

		// A non-numeric string key is the caller's display name (keyed
		// wp_mail() form); a numeric key is just a positional index from the
		// legacy indexed form, so fall back to the file's base name there.
		foreach ( $skipped as $key => $value ) {
			if ( is_string( $key ) && '' !== $key && ! ctype_digit( $key ) ) {
				$names[] = $key;
			} elseif ( is_string( $value ) && '' !== $value ) {
				$names[] = basename( $value );
			} else {
				$names[] = __( '(invalid entry)', 'leastudios-mailer' );
			}
		}

		$count = count( $names );

		return sprintf(
			/* translators: 1: number of attachments, 2: comma-separated list of filenames. */
			_n(
				'%1$d attachment could not be read and was not sent: %2$s',
				'%1$d attachments could not be read and were not sent: %2$s',
				$count,
				'leastudios-mailer'
			),
			$count,
			implode( ', ', $names )
		);
	}

	/**
	 * Parse wp_mail-style headers.
	 *
	 * @param string|array<int, string> $headers Raw headers (CRLF-joined string or list of header lines).
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

			$name = strtolower( trim( $name ) );
			// Strip any CR/LF from the value to neutralise header-injection
			// attempts such as `From: foo@bar\r\nBcc: attacker@evil`. Core
			// wp_mail() runs this defence in WP_Validate_Header_Address; we
			// short-circuit that path via pre_wp_mail and so must do it here.
			$value = self::strip_header_crlf( trim( $value ) );

			switch ( $name ) {
				case 'from':
					// Match `Name <email@host>` or bare `<email@host>` with
					// named captures so the From row reliably resolves to
					// (display-name, address) regardless of which form the
					// caller used. Anything that doesn't match either form
					// is treated as a bare address.
					if ( preg_match( '/^\s*(?:"?(?P<name>[^"<]*?)"?\s*)?<(?P<email>[^>]+)>\s*$/', $value, $matches ) ) {
						$result['from_name']  = trim( $matches['name'] );
						$result['from_email'] = trim( $matches['email'] );
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

	/**
	 * Remove every CR/LF (and embedded NUL) from a header value so a
	 * malicious wp_mail() caller cannot smuggle additional headers through
	 * a single field.
	 *
	 * @param string $value The raw header value.
	 * @return string
	 */
	private static function strip_header_crlf( string $value ): string {
		return (string) preg_replace( '/[\r\n\x00]+/', '', $value );
	}

	/**
	 * Split a `Name <email@host>` or bare `email@host` string into parts.
	 *
	 * Mirrors the From-header regex in {@see self::parse_headers()} so the
	 * Raw send path can recover the same (display-name, address) pair from a
	 * filter-overridden `from` string.
	 *
	 * @param string $from Sender expression: `Name <addr>`, `"Name" <addr>`,
	 *                     `<addr>`, or `addr`.
	 * @return array{name: string, email: string}
	 */
	private static function split_from_address( string $from ): array {
		if ( preg_match( '/^\s*(?:"?(?P<name>[^"<]*?)"?\s*)?<(?P<email>[^>]+)>\s*$/', $from, $matches ) ) {
			return [
				'name'  => trim( $matches['name'] ),
				'email' => trim( $matches['email'] ),
			];
		}

		return [
			'name'  => '',
			'email' => trim( $from ),
		];
	}
}
