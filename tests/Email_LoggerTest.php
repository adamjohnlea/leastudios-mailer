<?php
/**
 * Tests for the email log database handler.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\Database\Migration;
use LEAStudios\Mailer\Log\Email_Logger;
use LEAStudios\Tests\TestCase;

/**
 * Each test runs inside the WP test framework's per-test transaction,
 * so inserts here roll back automatically. We DELETE first so any rows
 * created by prior test runs (when the test transaction wasn't active)
 * don't bleed in.
 *
 * @covers \LEAStudios\Mailer\Log\Email_Logger
 */
class Email_LoggerTest extends TestCase {

	private Email_Logger $logger;

	public function set_up(): void {
		parent::set_up();
		$this->logger = new Email_Logger();
		$this->truncate_log_table();
	}

	private function truncate_log_table(): void {
		global $wpdb;
		$table = Migration::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table}" );
	}

	public function test_log_inserts_a_row_and_returns_id(): void {
		$id = $this->logger->log(
			'to@example.com',
			'Hello world',
			'sent',
			'ses-msg-1',
			null,
			'from@example.com'
		);

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 1, $this->logger->get_total_count() );
	}

	public function test_log_persists_all_columns_including_from_email(): void {
		$this->logger->log(
			'to@example.com',
			'Subject text',
			'sent',
			'msg-A',
			null,
			'from@example.com'
		);

		$rows = $this->logger->get_logs();
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'to@example.com', $row->to_email );
		$this->assertSame( 'from@example.com', $row->from_email );
		$this->assertSame( 'Subject text', $row->subject );
		$this->assertSame( 'sent', $row->status );
		$this->assertSame( 'msg-A', $row->message_id );
	}

	public function test_log_accepts_very_long_subject_after_v3_widening(): void {
		// 1 KiB of subject — would have truncated under the old varchar(255).
		$long_subject = str_repeat( 'A', 1024 );

		$id = $this->logger->log(
			'to@example.com',
			$long_subject,
			'sent',
			'msg-long',
			null,
			'from@example.com'
		);

		$this->assertGreaterThan( 0, $id );

		$rows = $this->logger->get_logs();
		$this->assertSame( $long_subject, $rows[0]->subject );
	}

	public function test_update_status_by_message_id(): void {
		$this->logger->log( 'to@example.com', 'S', 'sent', 'ses-id-42', null, 'from@example.com' );

		$this->assertTrue( $this->logger->update_status( 'ses-id-42', 'delivered' ) );

		$rows = $this->logger->get_logs();
		$this->assertSame( 'delivered', $rows[0]->status );
	}

	public function test_update_status_records_error_message_when_provided(): void {
		$this->logger->log( 'to@example.com', 'S', 'sent', 'ses-id-bounce', null, 'from@example.com' );

		$this->logger->update_status( 'ses-id-bounce', 'bounced', 'Bounce type: Permanent' );

		$rows = $this->logger->get_logs();
		$this->assertSame( 'bounced', $rows[0]->status );
		$this->assertSame( 'Bounce type: Permanent', $rows[0]->error_message );
	}

	public function test_get_logs_filters_by_status(): void {
		$this->logger->log( 'a@example.com', 'A', 'sent', 'm-a', null, 'from@example.com' );
		$this->logger->log( 'b@example.com', 'B', 'failed', null, 'oops', 'from@example.com' );
		$this->logger->log( 'c@example.com', 'C', 'sent', 'm-c', null, 'from@example.com' );

		$sent = $this->logger->get_logs( 1, 20, 'sent' );
		$this->assertCount( 2, $sent );
		foreach ( $sent as $row ) {
			$this->assertSame( 'sent', $row->status );
		}

		$failed = $this->logger->get_logs( 1, 20, 'failed' );
		$this->assertCount( 1, $failed );
	}

	public function test_get_logs_paginates(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->logger->log( "to{$i}@example.com", "S{$i}", 'sent', "m-{$i}", null, 'from@example.com' );
		}

		$page_one = $this->logger->get_logs( 1, 2 );
		$this->assertCount( 2, $page_one );

		$page_three = $this->logger->get_logs( 3, 2 );
		$this->assertCount( 1, $page_three );
	}

	public function test_get_total_count_with_and_without_status(): void {
		$this->logger->log( 'a@example.com', 'A', 'sent', 'm-a', null, 'from@example.com' );
		$this->logger->log( 'b@example.com', 'B', 'failed', null, 'oops', 'from@example.com' );

		$this->assertSame( 2, $this->logger->get_total_count() );
		$this->assertSame( 1, $this->logger->get_total_count( 'sent' ) );
		$this->assertSame( 1, $this->logger->get_total_count( 'failed' ) );
		$this->assertSame( 0, $this->logger->get_total_count( 'bounced' ) );
	}

	public function test_delete_log_removes_a_row(): void {
		$id = $this->logger->log( 'a@example.com', 'A', 'sent', 'm-a', null, 'from@example.com' );

		$this->assertTrue( $this->logger->delete_log( $id ) );
		$this->assertSame( 0, $this->logger->get_total_count() );
	}

	public function test_delete_old_logs_respects_cutoff(): void {
		global $wpdb;
		$table = Migration::get_table_name();

		// Insert one fresh + one ancient row by patching created_at directly.
		$fresh_id   = $this->logger->log( 'fresh@example.com', 'F', 'sent', 'm-fresh', null, 'f@example.com' );
		$ancient_id = $this->logger->log( 'ancient@example.com', 'A', 'sent', 'm-ancient', null, 'f@example.com' );

		$old_date = ( new \DateTimeImmutable( '-45 days', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s WHERE id = %d", $old_date, $ancient_id ) );

		$deleted = $this->logger->delete_old_logs( 30 );

		$this->assertSame( 1, $deleted );
		$this->assertSame( 1, $this->logger->get_total_count() );

		// The remaining row must be the fresh one.
		$rows = $this->logger->get_logs();
		$this->assertSame( $fresh_id, (int) $rows[0]->id );
	}

	public function test_get_status_by_message_id_returns_status_row(): void {
		$this->logger->log( 'to@example.com', 'S', 'sent', 'ses-lookup-1', null, 'from@example.com' );
		$this->logger->update_status( 'ses-lookup-1', 'delivered' );

		$result = $this->logger->get_status_by_message_id( 'ses-lookup-1' );

		$this->assertIsArray( $result );
		$this->assertSame( 'delivered', $result['status'] );
		$this->assertSame( '', $result['error_message'] );
	}

	public function test_get_status_by_message_id_includes_error_message(): void {
		$this->logger->log( 'to@example.com', 'S', 'sent', 'ses-lookup-bounce', null, 'from@example.com' );
		$this->logger->update_status( 'ses-lookup-bounce', 'bounced', 'Bounce type: Permanent' );

		$result = $this->logger->get_status_by_message_id( 'ses-lookup-bounce' );

		$this->assertIsArray( $result );
		$this->assertSame( 'bounced', $result['status'] );
		$this->assertSame( 'Bounce type: Permanent', $result['error_message'] );
	}

	public function test_get_status_by_message_id_returns_null_for_unknown_id(): void {
		$this->assertNull( $this->logger->get_status_by_message_id( 'ses-does-not-exist' ) );
	}

	public function test_get_status_by_message_id_returns_null_for_empty_id(): void {
		$this->assertNull( $this->logger->get_status_by_message_id( '' ) );
	}

	public function test_before_log_filter_can_block_insert(): void {
		add_filter( 'leastudios_mailer_before_log', '__return_false' );

		$id = $this->logger->log( 'to@example.com', 'S', 'sent', 'm-x', null, 'from@example.com' );

		$this->assertSame( 0, $id );
		$this->assertSame( 0, $this->logger->get_total_count() );
	}

	public function test_before_log_filter_can_mutate_data(): void {
		add_filter(
			'leastudios_mailer_before_log',
			static function ( array $data ): array {
				$data['subject'] = '[redacted]';
				return $data;
			}
		);

		$this->logger->log( 'to@example.com', 'Original subject', 'sent', 'm-y', null, 'from@example.com' );

		$rows = $this->logger->get_logs();
		$this->assertSame( '[redacted]', $rows[0]->subject );
	}
}
