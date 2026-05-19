<?php
/**
 * Plugin Name:       leaStudios Mailer
 * Plugin URI:        https://leastudios.com/plugins/leastudios-mailer
 * Description:       Lightweight Amazon SES email transport for WordPress. Routes all wp_mail() through SES with logging and delivery tracking.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            leaStudios
 * Author URI:        https://leastudios.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leastudios-mailer
 * Domain Path:       /languages
 *
 * @package LEAStudios\Mailer
 */

declare(strict_types=1);

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'LEASTUDIOS_MAILER_VERSION', '1.0.0' );
define( 'LEASTUDIOS_MAILER_FILE', __FILE__ );
define( 'LEASTUDIOS_MAILER_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEASTUDIOS_MAILER_URL', plugin_dir_url( __FILE__ ) );

// Autoloader.
if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong>: %s</p></div>',
				esc_html__( 'leaStudios Mailer', 'leastudios-mailer' ),
				esc_html__( 'Plugin dependencies are missing. Run "composer install" in the plugin directory.', 'leastudios-mailer' )
			);
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Initialize the plugin.
 *
 * @return void
 */
function leastudios_mailer_init(): void {
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		add_action( 'admin_notices', 'leastudios_mailer_php_version_notice' );
		return;
	}

	$plugin = new LEAStudios\Mailer\Plugin();
	$plugin->init();
}
add_action( 'plugins_loaded', 'leastudios_mailer_init' );

/**
 * Display PHP version notice.
 *
 * @return void
 */
function leastudios_mailer_php_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'leaStudios Mailer requires PHP 8.1 or higher.', 'leastudios-mailer' )
	);
}

/**
 * Run on plugin activation.
 *
 * @return void
 */
function leastudios_mailer_activate(): void {
	$migration = new LEAStudios\Mailer\Database\Migration();
	$migration->maybe_migrate();

	if ( false === get_option( 'leastudios_mailer_options' ) ) {
		update_option(
			'leastudios_mailer_options',
			[
				'access_key'         => '',
				'secret_key'         => '',
				'region'             => 'us-east-1',
				'from_email'         => get_option( 'admin_email' ),
				'from_name'          => get_option( 'blogname' ),
				'enabled'            => false,
				'log_retention_days' => 30,
			]
		);
	}

	// Schedule the log-cleanup cron here so a fresh activation always has
	// it queued. Plugin::init() also queues it lazily on plugins_loaded as
	// a safety net for installs that skipped the activation hook (e.g.
	// dropped-in plugin files), but the activation hook is the canonical
	// scheduling point.
	if ( ! wp_next_scheduled( 'leastudios_mailer_cleanup_logs' ) ) {
		wp_schedule_event( time(), 'daily', 'leastudios_mailer_cleanup_logs' );
	}
}
register_activation_hook( __FILE__, 'leastudios_mailer_activate' );

/**
 * Run on plugin deactivation.
 *
 * @return void
 */
function leastudios_mailer_deactivate(): void {
	$timestamp = wp_next_scheduled( 'leastudios_mailer_cleanup_logs' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'leastudios_mailer_cleanup_logs' );
	}
}
register_deactivation_hook( __FILE__, 'leastudios_mailer_deactivate' );
