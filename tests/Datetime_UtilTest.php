<?php
/**
 * Tests for Datetime_Util.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\Shared\Datetime_Util;
use LEAStudios\Tests\TestCase;

/**
 * @covers \LEAStudios\Mailer\Shared\Datetime_Util
 */
class Datetime_UtilTest extends TestCase {

	public function test_utc_now_mysql_returns_utc_string(): void {
		$before = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$mysql  = Datetime_Util::utc_now_mysql();
		$after  = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		// Format check.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $mysql );

		// Round-trip the string back through DateTimeImmutable interpreted as UTC,
		// and confirm the resulting timestamp falls between the two boundary "now"s.
		$parsed_ts = ( new \DateTimeImmutable( $mysql, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

		$this->assertGreaterThanOrEqual( $before, $parsed_ts );
		$this->assertLessThanOrEqual( $after, $parsed_ts );
	}

	public function test_utc_now_mysql_is_independent_of_php_default_timezone(): void {
		// phpcs:disable WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- this test legitimately mutates the runtime tz to assert the helper ignores it.
		$original = date_default_timezone_get();

		try {
			date_default_timezone_set( 'America/Boise' );
			$boise_view = Datetime_Util::utc_now_mysql();

			date_default_timezone_set( 'Asia/Tokyo' );
			$tokyo_view = Datetime_Util::utc_now_mysql();

			// Both calls run within the same second window; the produced strings
			// should agree to the minute regardless of PHP's default tz.
			$this->assertSame( substr( $boise_view, 0, 16 ), substr( $tokyo_view, 0, 16 ) );
		} finally {
			date_default_timezone_set( $original );
		}
		// phpcs:enable WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set
	}

	public function test_format_for_display_converts_utc_to_wp_timezone(): void {
		update_option( 'timezone_string', 'America/Boise' );

		// 02:41 UTC on 2026-04-30 == 20:41 (8:41 PM) on 2026-04-29 in Boise (MDT, UTC-6).
		$formatted = Datetime_Util::format_for_display( '2026-04-30 02:41:00', 'Y-m-d H:i' );

		$this->assertSame( '2026-04-29 20:41', $formatted );
	}

	public function test_format_for_display_returns_empty_string_for_null_or_empty(): void {
		$this->assertSame( '', Datetime_Util::format_for_display( null, 'Y-m-d' ) );
		$this->assertSame( '', Datetime_Util::format_for_display( '', 'Y-m-d' ) );
	}
}
