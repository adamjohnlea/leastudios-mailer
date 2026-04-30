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
	private const SCHEMA_VERSION = 2;

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

		if ( $from_version < 2 ) {
			$this->widen_log_columns( $wpdb );
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
			to_email text NOT NULL,
			from_email varchar(255) NOT NULL DEFAULT '',
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'sent',
			message_id varchar(255) DEFAULT NULL,
			error_message text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY status (status),
			KEY message_id (message_id),
			KEY created_at (created_at),
			KEY from_email (from_email)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Widen the log to_email column and add a from_email column.
	 *
	 * Multi-recipient sends previously truncated at 255 chars, losing audit
	 * information. Recording the From address makes abuse investigation
	 * possible without correlating against the full message body.
	 *
	 * @param \wpdb $wpdb WordPress database abstraction.
	 * @return void
	 */
	private function widen_log_columns( \wpdb $wpdb ): void {
		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table_name} MODIFY COLUMN to_email text NOT NULL" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$column_exists = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name} LIKE 'from_email'" );

		if ( empty( $column_exists ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN from_email varchar(255) NOT NULL DEFAULT '' AFTER to_email, ADD KEY from_email (from_email)" );
		}
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
