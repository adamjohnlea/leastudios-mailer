<?php
/**
 * Email log list table for admin display.
 *
 * @package LEAStudios\Mailer\Admin
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Admin;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

use LEAStudios\Mailer\Log\Email_Logger;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the email log as a WP_List_Table.
 */
class Email_Log_Table extends \WP_List_Table {

	/**
	 * Constructor.
	 *
	 * @param Email_Logger $logger The email logger.
	 */
	public function __construct(
		private readonly Email_Logger $logger,
	) {
		parent::__construct(
			[
				'singular' => __( 'Email Log', 'leastudios-mailer' ),
				'plural'   => __( 'Email Logs', 'leastudios-mailer' ),
				'ajax'     => false,
			]
		);
	}

	/**
	 * Get columns.
	 *
	 * @return array Column definitions.
	 */
	public function get_columns(): array {
		return [
			'cb'         => '<input type="checkbox" />',
			'to_email'   => __( 'To', 'leastudios-mailer' ),
			'subject'    => __( 'Subject', 'leastudios-mailer' ),
			'status'     => __( 'Status', 'leastudios-mailer' ),
			'message_id' => __( 'Message ID', 'leastudios-mailer' ),
			'created_at' => __( 'Date Sent', 'leastudios-mailer' ),
		];
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array Sortable column definitions.
	 */
	public function get_sortable_columns(): array {
		return [
			'created_at' => [ 'created_at', true ],
			'status'     => [ 'status', false ],
		];
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$per_page = 20;
		$page     = $this->get_pagenum();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : null;

		$this->items = $this->logger->get_logs( $page, $per_page, $status );
		$total       = $this->logger->get_total_count( $status );

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			]
		);

		$this->_column_headers = [
			$this->get_columns(),
			[],
			$this->get_sortable_columns(),
		];
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param object $item The log row.
	 * @return string Column HTML.
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="log_ids[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Render the status column with a colored badge.
	 *
	 * @param object $item The log row.
	 * @return string Column HTML.
	 */
	public function column_status( $item ): string {
		$status = esc_html( $item->status );
		$colors = [
			'sent'       => '#2271b1',
			'delivered'  => '#00a32a',
			'failed'     => '#d63638',
			'bounced'    => '#d63638',
			'complained' => '#dba617',
		];

		$color = $colors[ $item->status ] ?? '#787c82';

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:%s;color:#fff;font-size:12px;font-weight:500;">%s</span>',
			esc_attr( $color ),
			$status
		);
	}

	/**
	 * Render the message ID column (truncated).
	 *
	 * @param object $item The log row.
	 * @return string Column HTML.
	 */
	public function column_message_id( $item ): string {
		if ( empty( $item->message_id ) ) {
			return '—';
		}

		return sprintf(
			'<code title="%s" style="font-size:11px;">%s</code>',
			esc_attr( $item->message_id ),
			esc_html( substr( $item->message_id, 0, 20 ) . '…' )
		);
	}

	/**
	 * Render the date column.
	 *
	 * @param object $item The log row.
	 * @return string Column HTML.
	 */
	public function column_created_at( $item ): string {
		return esc_html(
			wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				strtotime( $item->created_at )
			)
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param object $item        The log row.
	 * @param string $column_name The column name.
	 * @return string Column HTML.
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( $item->$column_name ?? '' );
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array Bulk action definitions.
	 */
	public function get_bulk_actions(): array {
		return [
			'delete' => __( 'Delete', 'leastudios-mailer' ),
		];
	}

	/**
	 * Get view filters.
	 *
	 * @return array View link definitions.
	 */
	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$base    = admin_url( 'admin.php?page=leastudios-mailer&tab=email-log' );

		$statuses = [
			''           => __( 'All', 'leastudios-mailer' ),
			'sent'       => __( 'Sent', 'leastudios-mailer' ),
			'delivered'  => __( 'Delivered', 'leastudios-mailer' ),
			'failed'     => __( 'Failed', 'leastudios-mailer' ),
			'bounced'    => __( 'Bounced', 'leastudios-mailer' ),
			'complained' => __( 'Complained', 'leastudios-mailer' ),
		];

		$views = [];

		foreach ( $statuses as $slug => $label ) {
			$count = $this->logger->get_total_count( '' === $slug ? null : $slug );
			$url   = '' === $slug ? $base : $base . '&status=' . $slug;
			$class = ( $current === $slug ) ? 'current' : '';

			$views[ $slug ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$class,
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	/**
	 * Display when no items are found.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No emails have been logged yet.', 'leastudios-mailer' );
	}
}
