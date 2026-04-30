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
 * Centralises every "now" timestamp the plugin records and every datetime
 * read back from MySQL. Always operates in UTC so the storage format is
 * stable across timezone-misconfigured PHP runtimes, which lets WordPress's
 * `get_date_from_gmt()` helper convert correctly to the user's configured
 * display timezone.
 *
 * Why a helper instead of `new \DateTimeImmutable()` / `CURRENT_TIMESTAMP`:
 *
 * - `new \DateTimeImmutable()` uses PHP's `date.timezone` ini setting, which
 *   differs between Herd local dev (often the OS timezone) and production
 *   servers (usually UTC).
 * - MySQL's `CURRENT_TIMESTAMP` default reflects whatever the connection's
 *   `time_zone` session variable is — generally `SYSTEM`, which is the
 *   server's local timezone. Hosts vary.
 *
 * Mixing those writes inconsistent strings to the same column and causes the
 * Email Log to display stale or future-shifted timestamps.
 */
final class Datetime_Util {

	/**
	 * Current time in UTC, formatted for a MySQL `datetime` column.
	 *
	 * @return string e.g. "2026-04-30 02:41:00".
	 */
	public static function utc_now_mysql(): string {
		return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Convert a stored UTC datetime string to the WordPress display timezone.
	 *
	 * Why not `mysql2date()`: that helper interprets its input string as
	 * already being in `wp_timezone()` and only re-formats the wall clock,
	 * which silently re-labels UTC values as local time without applying any
	 * offset. `get_date_from_gmt()` is the canonical "input is UTC, output
	 * is WP-timezone" conversion.
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
}
