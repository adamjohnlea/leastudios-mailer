<?php
/**
 * SES health check and test email.
 *
 * @package LEAStudios\Mailer\Email
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Email;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Mailer\SES\Client;

/**
 * Validates SES configuration and sends test emails.
 */
class Health_Check {

	/**
	 * Constructor.
	 *
	 * @param Client $ses_client The SES API client.
	 */
	public function __construct(
		private readonly Client $ses_client,
	) {}

	/**
	 * Send a test email.
	 *
	 * @param string $to        Recipient email address.
	 * @param string $from      Sender email address (bare, no display name).
	 * @param string $from_name Sender display name. Empty string sends with no display name.
	 * @return array{success: bool, message_id: string|null, error: string|null}
	 */
	public function send_test_email( string $to, string $from, string $from_name = '' ): array {
		$from_expression = '' !== $from_name ? "{$from_name} <{$from}>" : $from;

		return $this->ses_client->send_email(
			$from_expression,
			[ $to ],
			__( 'leaStudios Mailer — Test Email', 'leastudios-mailer' ),
			$this->get_test_email_html(),
			__( 'This is a test email from leaStudios Mailer. If you are reading this, your SES configuration is working correctly.', 'leastudios-mailer' )
		);
	}

	/**
	 * Run all health checks and return a structured report.
	 *
	 * @return array{credentials: array{valid: bool, error?: string}, sender: array{verified: bool, error?: string}, overall: bool}
	 */
	public function get_overall_status(): array {
		$options = get_option( 'leastudios_mailer_options', [] );

		$credentials = $this->ses_client->check_credentials();

		$sender = [
			'verified' => false,
			'error'    => __( 'Skipped — credentials are invalid.', 'leastudios-mailer' ),
		];

		if ( $credentials['valid'] ) {
			$from_email = $options['from_email'] ?? get_option( 'admin_email' );
			$sender     = $this->ses_client->check_sender_identity( $from_email );
		}

		return [
			'credentials' => $credentials,
			'sender'      => $sender,
			'overall'     => $credentials['valid'] && $sender['verified'],
		];
	}

	/**
	 * Get the test email HTML body.
	 *
	 * @return string HTML content.
	 */
	private function get_test_email_html(): string {
		$site_name = get_option( 'blogname' );

		return sprintf(
			'<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
				<h2 style="color: #1d2327;">%s</h2>
				<p style="color: #50575e; font-size: 16px; line-height: 1.5;">%s</p>
				<hr style="border: none; border-top: 1px solid #dcdcde; margin: 20px 0;">
				<p style="color: #787c82; font-size: 13px;">%s</p>
			</div>',
			esc_html__( 'leaStudios Mailer — Test Email', 'leastudios-mailer' ),
			esc_html__( 'If you are reading this, your Amazon SES configuration is working correctly. Emails from your WordPress site will be delivered reliably through SES.', 'leastudios-mailer' ),
			/* translators: %s: site name. */
			sprintf( esc_html__( 'Sent from %s via leaStudios Mailer.', 'leastudios-mailer' ), esc_html( $site_name ) )
		);
	}
}
