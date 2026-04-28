<?php
/**
 * Main plugin bootstrap class.
 *
 * @package LEAStudios\Mailer
 */

declare(strict_types=1);

namespace LEAStudios\Mailer;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Mailer\Admin\Settings_Page;
use LEAStudios\Mailer\Database\Migration;
use LEAStudios\Mailer\Email\Health_Check;
use LEAStudios\Mailer\Email\Mailer;
use LEAStudios\Mailer\Encryption\Options_Encryptor;
use LEAStudios\Mailer\Log\Email_Logger;
use LEAStudios\Mailer\SES\Client;
use LEAStudios\Mailer\SES\Signer;
use LEAStudios\Mailer\Webhook\SNS_Controller;

/**
 * Wires all plugin components together.
 */
final class Plugin {

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function init(): void {
		// Run migrations if needed.
		$migration = new Migration();
		$migration->maybe_migrate();

		// Core services.
		$encryptor = new Options_Encryptor();
		$signer    = new Signer();
		$client    = new Client( $encryptor, $signer );
		$logger    = new Email_Logger();

		// Mail transport override.
		$mailer = new Mailer( $client, $logger );
		$mailer->init();

		// Health check service.
		$health_check = new Health_Check( $client );

		// REST API webhook for SNS delivery tracking.
		$sns_controller = new SNS_Controller( $logger );
		add_action( 'rest_api_init', [ $sns_controller, 'register_routes' ] );

		// Admin settings page.
		if ( is_admin() ) {
			$settings = new Settings_Page( $encryptor, $logger, $health_check );
			$settings->init();
		}

		// Scheduled log cleanup.
		add_action( 'leastudios_mailer_cleanup_logs', [ $this, 'cleanup_logs' ] );

		if ( ! wp_next_scheduled( 'leastudios_mailer_cleanup_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'leastudios_mailer_cleanup_logs' );
		}

		/**
		 * Fires after all mailer components are wired up and ready.
		 *
		 * Lets other plugins know the mailer is fully initialized.
		 */
		do_action( 'leastudios_mailer_initialized' );
	}

	/**
	 * Clean up old log entries based on retention setting.
	 *
	 * @return void
	 */
	public function cleanup_logs(): void {
		$options = get_option( 'leastudios_mailer_options', [] );
		$days    = (int) ( $options['log_retention_days'] ?? 30 );

		$logger = new Email_Logger();
		$logger->delete_old_logs( $days );
	}
}
