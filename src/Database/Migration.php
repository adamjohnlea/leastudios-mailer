<?php
/**
 * Database migration handler.
 *
 * @package LEAStudios\Mailer\Database
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Handles custom database table creation and migration.
 */
class Migration {

	/**
	 * The schema version option key.
	 */
	private const SCHEMA_VERSION_KEY = 'leastudios_mailer_schema_version';

	/**
	 * The target schema version.
	 */
	private const SCHEMA_VERSION = 1;

	/**
	 * Get the email log table name.
	 *
	 * @return string The full table name with prefix.
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'leastudios_mailer_log';
	}

	/**
	 * Run migrations if needed.
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		$current_version = (int) get_option( self::SCHEMA_VERSION_KEY, 0 );

		if ( $current_version >= self::SCHEMA_VERSION ) {
			return;
		}

		$this->migrate( $current_version );

		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION );
	}

	/**
	 * Run the migration sequence.
	 *
	 * @param int $from_version Current schema version.
	 * @return void
	 */
	private function migrate( int $from_version ): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( $from_version < 1 ) {
			$this->create_email_log_table( $wpdb );
		}
	}

	/**
	 * Create the email log table.
	 *
	 * @param \wpdb $wpdb WordPress database abstraction.
	 * @return void
	 */
	private function create_email_log_table( \wpdb $wpdb ): void {
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = self::get_table_name();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			to_email varchar(255) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'sent',
			message_id varchar(255) DEFAULT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY message_id (message_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Drop all plugin tables. Use on uninstall only.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}leastudios_mailer_log" );

		delete_option( self::SCHEMA_VERSION_KEY );
	}
}
