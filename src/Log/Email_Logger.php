<?php
/**
 * Email log database handler.
 *
 * @package LEAStudios\Mailer\Log
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Log;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Mailer\Database\Migration;
use LEAStudios\Mailer\Shared\Datetime_Util;

/**
 * Inserts, updates, and queries the email log table.
 */
class Email_Logger {

	/**
	 * Log an email send attempt.
	 *
	 * @param string      $to            Recipient email address(es).
	 * @param string      $subject       Email subject.
	 * @param string      $status        Status: sent, failed, delivered, bounced, complained.
	 * @param string|null $message_id    SES message ID.
	 * @param string|null $error_message Error details on failure.
	 * @param string      $from          From address (envelope sender) of the message.
	 * @return int The inserted row ID, or 0 on failure.
	 */
	public function log(
		string $to,
		string $subject,
		string $status,
		?string $message_id = null,
		?string $error_message = null,
		string $from = '',
	): int {
		global $wpdb;

		/**
		 * Filter log data before database insertion.
		 *
		 * Return false to skip logging this email entirely.
		 *
		 * @param array $log_data {
		 *     The log data to insert.
		 *
		 *     @type string      $to_email      Recipient email address(es).
		 *     @type string      $from_email    Envelope sender / From address.
		 *     @type string      $subject       Email subject.
		 *     @type string      $status        Status: sent, failed, delivered, bounced, complained.
		 *     @type string|null $message_id    SES message ID.
		 *     @type string|null $error_message Error details on failure.
		 * }
		 */
		$log_data = apply_filters(
			'leastudios_mailer_before_log',
			[
				'to_email'      => $to,
				'from_email'    => $from,
				'subject'       => $subject,
				'status'        => $status,
				'message_id'    => $message_id,
				'error_message' => $error_message,
			]
		);

		if ( false === $log_data ) {
			return 0;
		}

		$now_utc = Datetime_Util::utc_now_mysql();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Migration::get_table_name(),
			[
				'to_email'      => $log_data['to_email'],
				'from_email'    => $log_data['from_email'] ?? '',
				'subject'       => $log_data['subject'],
				'status'        => $log_data['status'],
				'message_id'    => $log_data['message_id'],
				'error_message' => $log_data['error_message'],
				'created_at'    => $now_utc,
				'updated_at'    => $now_utc,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update log status by SES message ID.
	 *
	 * @param string      $message_id    The SES message ID.
	 * @param string      $status        New status.
	 * @param string|null $error_message Optional error message.
	 * @return bool Whether the update succeeded.
	 */
	public function update_status( string $message_id, string $status, ?string $error_message = null ): bool {
		global $wpdb;

		$data   = [
			'status'     => $status,
			'updated_at' => Datetime_Util::utc_now_mysql(),
		];
		$format = [ '%s', '%s' ];

		if ( null !== $error_message ) {
			$data['error_message'] = $error_message;
			$format[]              = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			Migration::get_table_name(),
			$data,
			[ 'message_id' => $message_id ],
			$format,
			[ '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Look up the delivery status of a single sent message by its SES message ID.
	 *
	 * This is the canonical, public read path into the mailer log for sibling
	 * plugins (e.g. leastudios-forms) that recorded the message ID returned by
	 * the `leastudios_mailer_email_sent` action and later want to surface the
	 * delivery outcome. It reads only the mailer's own table.
	 *
	 * @param string $message_id The SES message ID captured at send time.
	 * @return array{status: string, error_message: string}|null The status row, or null when the message ID is unknown.
	 */
	public function get_status_by_message_id( string $message_id ): ?array {
		if ( '' === $message_id ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT status, error_message FROM %i WHERE message_id = %s ORDER BY id DESC LIMIT 1',
				Migration::get_table_name(),
				$message_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return [
			'status'        => (string) ( $row['status'] ?? '' ),
			'error_message' => (string) ( $row['error_message'] ?? '' ),
		];
	}

	/**
	 * Get paginated log entries.
	 *
	 * @param int         $page     Page number (1-indexed).
	 * @param int         $per_page Items per page.
	 * @param string|null $status   Optional status filter.
	 * @return array<int, \stdClass> Log rows as wpdb-hydrated objects.
	 */
	public function get_logs( int $page = 1, int $per_page = 20, ?string $status = null ): array {
		global $wpdb;

		$table  = Migration::get_table_name();
		$offset = ( $page - 1 ) * $per_page;

		if ( null !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
					$table,
					$status,
					$per_page,
					$offset
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$table,
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Get total count of log entries.
	 *
	 * @param string|null $status Optional status filter.
	 * @return int Total count.
	 */
	public function get_total_count( ?string $status = null ): int {
		global $wpdb;

		$table = Migration::get_table_name();

		if ( null !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE status = %s',
					$table,
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
		);
	}

	/**
	 * Get a status-keyed map of row counts in a single query.
	 *
	 * Used by the email-log view filters so rendering counts for all
	 * statuses is one `GROUP BY` round-trip instead of one `COUNT(*)` per
	 * status. Statuses not present in the table are absent from the map;
	 * callers should treat missing keys as zero.
	 *
	 * @return array<string, int>
	 */
	public function get_counts_by_status(): array {
		global $wpdb;

		$table = Migration::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table ) );

		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ (string) $row->status ] = (int) $row->total;
		}
		return $counts;
	}

	/**
	 * Delete a log entry by ID.
	 *
	 * @param int $id The log entry ID.
	 * @return bool Whether the deletion succeeded.
	 */
	public function delete_log( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			Migration::get_table_name(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Delete log entries older than the specified number of days.
	 *
	 * @param int $days Number of days to retain.
	 * @return int Number of rows deleted.
	 */
	public function delete_old_logs( int $days = 30 ): int {
		global $wpdb;

		$table  = Migration::get_table_name();
		$cutoff = ( new \DateTimeImmutable( "-{$days} days", new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				$table,
				$cutoff
			)
		);

		return ( false !== $result ) ? (int) $result : 0;
	}
}
