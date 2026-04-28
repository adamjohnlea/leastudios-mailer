<?php
/**
 * Uninstall handler — runs when the plugin is deleted via WP admin.
 *
 * @package LEAStudios\Mailer
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Autoload.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Clean up options.
delete_option( 'leastudios_mailer_options' );
delete_option( 'leastudios_mailer_schema_version' );

// Drop custom tables.
LEAStudios\Mailer\Database\Migration::drop_tables();

// Clear any scheduled cron events.
$timestamp = wp_next_scheduled( 'leastudios_mailer_cleanup_logs' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'leastudios_mailer_cleanup_logs' );
}
