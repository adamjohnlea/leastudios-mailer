<?php
/**
 * UTC-anchored datetime helpers.
 *
 * @package LEAStudios\Mailer\Shared
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Shared;

defined( 'ABSPATH' ) || exit;

/**
 * Centralises UTC↔WP-timezone conversion for stored timestamps.
 *
 * Two parallel APIs ship side by side:
 *
 *   - **String / MySQL form** — `utc_now_mysql()`, `format_for_display()`.
 *     Used by callers that round-trip through `$wpdb` and prefer to keep
 *     timestamps as plain MySQL datetime strings.
 *   - **DateTimeImmutable form** — `now()`, `from_mysql()`,
 *     `format_immutable_for_display()`. Used by callers that prefer to
 *     hold immutable date objects in their domain models.
 *
 * Entries are written via `current_time( 'mysql', true )` (GMT). On display,
 * the same strings need converting back to the WordPress display timezone.
 * `mysql2date()` re-labels UTC values as local without applying any offset,
 * so use `get_date_from_gmt()` — the canonical "input is UTC, output is
 * WP-tz" WordPress API.
 *
 * The class is intentionally identical (modulo the namespace) across every
 * leastudios-* plugin that ships it — see
 * `leastudios-dev-tools/bin/check-shared.sh` for the drift guard.
 */
final class Datetime_Util {

	/**
	 * Current time in UTC as an immutable DateTime object.
	 *
	 * @return \DateTimeImmutable
	 */
	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Current time in UTC, formatted for a MySQL `datetime` column.
	 *
	 * @return string e.g. "2026-04-30 02:41:00".
	 */
	public static function utc_now_mysql(): string {
		return self::now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Parse a stored UTC MySQL datetime string back into an immutable DateTime.
	 *
	 * @param string $mysql_datetime Stored UTC datetime, e.g. "2026-04-30 02:41:00".
	 *
	 * @return \DateTimeImmutable
	 */
	public static function from_mysql( string $mysql_datetime ): \DateTimeImmutable {
		return new \DateTimeImmutable( $mysql_datetime, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Convert a stored UTC datetime string to the WordPress display timezone.
	 *
	 * @param string|null $utc_mysql Stored UTC datetime, e.g. "2026-04-30 02:41:00".
	 * @param string      $format    PHP date format string.
	 *
	 * @return string Empty string for null/empty input.
	 */
	public static function format_for_display( ?string $utc_mysql, string $format ): string {
		if ( null === $utc_mysql || '' === $utc_mysql ) {
			return '';
		}

		return get_date_from_gmt( $utc_mysql, $format );
	}

	/**
	 * Convert a UTC DateTimeImmutable to a string in the WordPress display timezone.
	 *
	 * @param \DateTimeImmutable|null $utc    Source instant in UTC.
	 * @param string                  $format PHP date format string.
	 *
	 * @return string Empty string when `$utc` is null.
	 */
	public static function format_immutable_for_display( ?\DateTimeImmutable $utc, string $format ): string {
		if ( null === $utc ) {
			return '';
		}

		return get_date_from_gmt( $utc->format( 'Y-m-d H:i:s' ), $format );
	}
}
