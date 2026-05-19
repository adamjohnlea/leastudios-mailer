<?php
/**
 * Tests for the schema migration runner.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\Database\Migration;
use LEAStudios\Tests\TestCase;

/**
 * Migrations run schema-altering DDL, which sits outside the WP test
 * suite's per-test transaction wrapper. To keep these tests hermetic we
 * drop the plugin's table (and clear the version option) on both set_up
 * and tear_down.
 *
 * @covers \LEAStudios\Mailer\Database\Migration
 */
class MigrationTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->reset_state();
	}

	public function tear_down(): void {
		$this->reset_state();
		parent::tear_down();
	}

	/**
	 * Drop the plugin's table — both the per-session TEMPORARY shadow that
	 * the WP test framework's `_create_temporary_tables` filter creates,
	 * AND the persistent real table that gets created once at plugin
	 * bootstrap (before any test transaction is active).
	 *
	 * Without bypassing the filter, our DROP would be rewritten to
	 * DROP TEMPORARY TABLE and the real table would stay, defeating the
	 * cleanup.
	 */
	private function reset_state(): void {
		global $wpdb;
		$table = Migration::get_table_name();

		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		add_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		delete_option( 'leastudios_mailer_schema_version' );
	}

	public function test_fresh_install_creates_table_at_current_version(): void {
		( new Migration() )->maybe_migrate();

		$this->assertTableExists();
		$this->assertSame( 3, (int) get_option( 'leastudios_mailer_schema_version' ) );
	}

	public function test_fresh_install_has_wide_to_email_and_subject_columns(): void {
		( new Migration() )->maybe_migrate();

		$this->assertColumnTypeContains( 'to_email', 'text' );
		$this->assertColumnTypeContains( 'subject', 'text' );
	}

	public function test_fresh_install_creates_expected_indexes(): void {
		( new Migration() )->maybe_migrate();

		$indexes = $this->get_indexes();
		$this->assertContains( 'status', $indexes );
		$this->assertContains( 'message_id', $indexes );
		$this->assertContains( 'created_at', $indexes );
		$this->assertContains( 'from_email', $indexes );
	}

	public function test_maybe_migrate_is_idempotent(): void {
		$migration = new Migration();
		$migration->maybe_migrate();
		$migration->maybe_migrate();
		$migration->maybe_migrate();

		$this->assertTableExists();
		$this->assertSame( 3, (int) get_option( 'leastudios_mailer_schema_version' ) );
	}

	public function test_v1_to_v3_widens_subject_and_adds_from_email(): void {
		// Simulate a v1 table: original schema (varchar(255) subject, no
		// from_email column), version option set to 1.
		$this->create_v1_schema();
		update_option( 'leastudios_mailer_schema_version', 1 );

		( new Migration() )->maybe_migrate();

		$this->assertSame( 3, (int) get_option( 'leastudios_mailer_schema_version' ) );
		$this->assertColumnTypeContains( 'subject', 'text' );
		$this->assertColumnTypeContains( 'to_email', 'text' );

		// from_email should have been added by the v2 step.
		$this->assertNotNull( $this->get_column( 'from_email' ) );
	}

	public function test_v2_to_v3_widens_only_subject(): void {
		// v2 schema: to_email already TEXT, from_email present, subject
		// still varchar(255).
		$this->create_v2_schema();
		update_option( 'leastudios_mailer_schema_version', 2 );

		( new Migration() )->maybe_migrate();

		$this->assertSame( 3, (int) get_option( 'leastudios_mailer_schema_version' ) );
		$this->assertColumnTypeContains( 'subject', 'text' );
	}

	public function test_already_current_version_is_a_no_op(): void {
		( new Migration() )->maybe_migrate();
		$first_state = $this->get_column( 'subject' );

		// Bump the option past current and re-run; nothing should change.
		update_option( 'leastudios_mailer_schema_version', 3 );
		( new Migration() )->maybe_migrate();

		$this->assertEquals( $first_state, $this->get_column( 'subject' ) );
	}

	public function test_drop_tables_removes_table_and_version(): void {
		( new Migration() )->maybe_migrate();
		$this->assertTableExists();

		Migration::drop_tables();

		$this->assertFalse( $this->table_exists() );
		$this->assertFalse( get_option( 'leastudios_mailer_schema_version' ) );
	}

	private function assertTableExists(): void {
		$this->assertTrue( $this->table_exists(), 'Email log table should exist after migration' );
	}

	/**
	 * Test whether the plugin's table exists in this DB session. `SHOW
	 * TABLES LIKE` doesn't list TEMPORARY tables (which the WP test
	 * framework creates per-test), so probe with a no-op SELECT against
	 * the table instead — it succeeds for both real and temp tables and
	 * fails cleanly for neither.
	 */
	private function table_exists(): bool {
		global $wpdb;
		$table = Migration::get_table_name();

		$prior = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "SELECT 1 FROM {$table} LIMIT 0" );
		$wpdb->suppress_errors( $prior );

		return '' === $wpdb->last_error;
	}

	/**
	 * @return array<int, object>
	 */
	private function get_columns(): array {
		global $wpdb;
		$table = Migration::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
	}

	private function get_column( string $name ): ?object {
		foreach ( $this->get_columns() as $col ) {
			if ( $col->Field === $name ) {
				return $col;
			}
		}
		return null;
	}

	private function assertColumnTypeContains( string $column, string $needle ): void {
		$col = $this->get_column( $column );
		$this->assertNotNull( $col, "Column {$column} should exist" );
		$this->assertStringContainsString( $needle, strtolower( $col->Type ), "Column {$column} type should contain {$needle}" );
	}

	/**
	 * @return string[]
	 */
	private function get_indexes(): array {
		global $wpdb;
		$table = Migration::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM {$table}" );

		$keys = [];
		foreach ( $rows as $row ) {
			if ( 'PRIMARY' !== $row->Key_name ) {
				$keys[] = $row->Key_name;
			}
		}
		return array_unique( $keys );
	}

	private function create_v1_schema(): void {
		global $wpdb;
		$table  = Migration::get_table_name();
		$prefix = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE {$table} (
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
			) {$prefix}"
		);
	}

	private function create_v2_schema(): void {
		global $wpdb;
		$table  = Migration::get_table_name();
		$prefix = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE {$table} (
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
			) {$prefix}"
		);
	}
}
