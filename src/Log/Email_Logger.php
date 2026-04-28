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
	 * @return int The inserted row ID, or 0 on failure.
	 */
	public function log(
		string $to,
		string $subject,
		string $status,
		?string $message_id = null,
		?string $error_message = null,
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
				'subject'       => $subject,
				'status'        => $status,
				'message_id'    => $message_id,
				'error_message' => $error_message,
			]
		);

		if ( false === $log_data ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			Migration::get_table_name(),
			[
				'to_email'      => $log_data['to_email'],
				'subject'       => $log_data['subject'],
				'status'        => $log_data['status'],
				'message_id'    => $log_data['message_id'],
				'error_message' => $log_data['error_message'],
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
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

		$data   = [ 'status' => $status ];
		$format = [ '%s' ];

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
	 * Get paginated log entries.
	 *
	 * @param int         $page     Page number (1-indexed).
	 * @param int         $per_page Items per page.
	 * @param string|null $status   Optional status filter.
	 * @return array Log rows as objects.
	 */
	public function get_logs( int $page = 1, int $per_page = 20, ?string $status = null ): array {
		global $wpdb;

		$table  = Migration::get_table_name();
		$offset = ( $page - 1 ) * $per_page;

		if ( null !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
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
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE status = %s",
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table}"
		);
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

		$table = Migration::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);

		return ( false !== $result ) ? (int) $result : 0;
	}
}
