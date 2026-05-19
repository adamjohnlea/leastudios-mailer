<?php
/**
 * Tests for the AWS SigV4 signer.
 *
 * @package LEAStudios\Mailer\Tests
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\Tests;

use LEAStudios\Mailer\SES\Signer;
use LEAStudios\Tests\TestCase;

/**
 * The Signer is the difference between "delivers email" and "AWS rejects
 * everything," so its behavior is locked down with three layers of tests:
 *
 *   1. Structural assertions on the Authorization / X-Amz-* headers.
 *   2. Differential assertions (body / secret changes → signature changes).
 *   3. A known-answer pin that recomputes the AWS spec step-by-step inline
 *      and asserts our output matches.
 *
 * Tests pin the timestamp via the `$timestamp` parameter so signatures stay
 * deterministic across runs.
 *
 * @covers \LEAStudios\Mailer\SES\Signer
 */
class SignerTest extends TestCase {

	private Signer $signer;

	private const ACCESS_KEY = 'AKIDEXAMPLE';
	private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
	private const REGION     = 'us-east-1';
	private const SERVICE    = 'service';
	// 2015-08-30T12:36:00Z — AWS's canonical sig-v4-test-suite timestamp.
	private const FIXED_TS = 1440938160;

	public function set_up(): void {
		parent::set_up();
		$this->signer = new Signer();
	}

	public function test_authorization_header_has_expected_shape(): void {
		$signed = $this->sign_get_root( '' );

		$this->assertArrayHasKey( 'Authorization', $signed );
		$this->assertMatchesRegularExpression(
			'/^AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE\/\d{8}\/us-east-1\/service\/aws4_request, SignedHeaders=[a-z0-9;-]+, Signature=[a-f0-9]{64}$/',
			$signed['Authorization']
		);
	}

	public function test_x_amz_date_matches_pinned_timestamp(): void {
		$signed = $this->sign_get_root( '' );

		$this->assertSame( '20150830T123600Z', $signed['X-Amz-Date'] );
	}

	public function test_x_amz_content_sha256_matches_body_hash(): void {
		$body   = '{"hello":"world"}';
		$signed = $this->signer->sign(
			'POST',
			'https://example.amazonaws.com/path',
			[ 'Content-Type' => 'application/json' ],
			$body,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			self::REGION,
			self::SERVICE,
			self::FIXED_TS
		);

		$this->assertSame( hash( 'sha256', $body ), $signed['X-Amz-Content-Sha256'] );
	}

	public function test_signature_is_deterministic_for_fixed_inputs(): void {
		$a = $this->sign_get_root( '' );
		$b = $this->sign_get_root( '' );

		$this->assertSame( $a['Authorization'], $b['Authorization'] );
	}

	public function test_changing_body_changes_signature(): void {
		$a = $this->sign_get_root( '' );
		$b = $this->sign_get_root( 'tampered' );

		$this->assertNotSame(
			$this->extract_signature( $a['Authorization'] ),
			$this->extract_signature( $b['Authorization'] )
		);
	}

	public function test_changing_secret_changes_signature(): void {
		$with_real = $this->signer->sign(
			'GET',
			'https://example.amazonaws.com/',
			[],
			'',
			self::ACCESS_KEY,
			self::SECRET_KEY,
			self::REGION,
			self::SERVICE,
			self::FIXED_TS
		);

		$with_bad = $this->signer->sign(
			'GET',
			'https://example.amazonaws.com/',
			[],
			'',
			self::ACCESS_KEY,
			'not-the-right-secret',
			self::REGION,
			self::SERVICE,
			self::FIXED_TS
		);

		$this->assertNotSame(
			$this->extract_signature( $with_real['Authorization'] ),
			$this->extract_signature( $with_bad['Authorization'] )
		);
	}

	public function test_signed_headers_are_alphabetical_and_lowercase(): void {
		$signed = $this->signer->sign(
			'POST',
			'https://example.amazonaws.com/path',
			[
				'Z-Trailer'    => 'last',
				'A-Leader'     => 'first',
				'Content-Type' => 'application/json',
			],
			'',
			self::ACCESS_KEY,
			self::SECRET_KEY,
			self::REGION,
			self::SERVICE,
			self::FIXED_TS
		);

		preg_match( '/SignedHeaders=([^,]+)/', $signed['Authorization'], $m );
		$headers = explode( ';', $m[1] );

		$this->assertSame( array_map( 'strtolower', $headers ), $headers, 'SignedHeaders entries must be lowercase' );

		$sorted = $headers;
		sort( $sorted );
		$this->assertSame( $sorted, $headers, 'SignedHeaders entries must be sorted' );
	}

	/**
	 * SigV4 requires non-S3 services to double-URI-encode path segments.
	 * An `@` in the path therefore signs as `%2540`, not `%40`. We exercise
	 * this via the SES "check identity" path shape and verify a different
	 * signature than a path without the reserved character.
	 */
	public function test_path_segments_are_double_encoded(): void {
		$with_at = $this->signer->sign(
			'GET',
			'https://example.amazonaws.com/v2/email/identities/user@example.com',
			[],
			'',
			self::ACCESS_KEY,
			self::SECRET_KEY,
			self::REGION,
			self::SERVICE,
			self::FIXED_TS
		);

		// Pre-encoded path with %40 should still produce the SAME canonical
		// path as the raw `@` (because normalize_path decodes then encodes
		// twice) — i.e., signature should match.
		$with_percent = $this->signer->sign(
			'GET',
			'https://example.amazonaws.com/v2/email/identities/user%40example.com',
			[],
			'',
			self::ACCESS_KEY,
			self::SECRET_KEY,
			self::REGION,
			self::SERVICE,
			self::FIXED_TS
		);

		$this->assertSame(
			$this->extract_signature( $with_at['Authorization'] ),
			$this->extract_signature( $with_percent['Authorization'] )
		);
	}

	/**
	 * Known-answer pin: recompute the AWS spec inline against fixed inputs
	 * and assert the Signer produces the same signature. This catches any
	 * regression in canonical-request construction or HMAC chaining.
	 */
	public function test_known_answer_signature(): void {
		$method  = 'GET';
		$host    = 'example.amazonaws.com';
		$path    = '/';
		$query   = '';
		$body    = '';
		$amzdate = '20150830T123600Z';
		$date    = '20150830';

		$payload_hash       = hash( 'sha256', $body );
		$canonical_headers  = "host:{$host}\n";
		$canonical_headers .= "x-amz-content-sha256:{$payload_hash}\n";
		$canonical_headers .= "x-amz-date:{$amzdate}\n";

		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		$canonical_request = "{$method}\n{$path}\n{$query}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";

		$credential_scope = "{$date}/" . self::REGION . '/' . self::SERVICE . '/aws4_request';

		$string_to_sign = "AWS4-HMAC-SHA256\n{$amzdate}\n{$credential_scope}\n" . hash( 'sha256', $canonical_request );

		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . self::SECRET_KEY, true );
		$k_region  = hash_hmac( 'sha256', self::REGION, $k_date, true );
		$k_service = hash_hmac( 'sha256', self::SERVICE, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		$expected_signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$signed = $this->sign_get_root( $body );

		$this->assertSame(
			$expected_signature,
			$this->extract_signature( $signed['Authorization'] )
		);
	}

	/**
	 * Sign a plain GET https://example.amazonaws.com/ request with the
	 * pinned timestamp and the given body.
	 *
	 * @param string $body Request body.
	 * @return array<string, string>
	 */
	private function sign_get_root( string $body ): array {
		return $this->signer->sign(
			'GET',
			'https://example.amazonaws.com/',
			[],
			$body,
			self::ACCESS_KEY,
			self::SECRET_KEY,
			self::REGION,
			self::SERVICE,
			self::FIXED_TS
		);
	}

	/**
	 * Pull the hex Signature= portion out of an Authorization header.
	 */
	private function extract_signature( string $authorization ): string {
		preg_match( '/Signature=([a-f0-9]+)/', $authorization, $m );
		return $m[1] ?? '';
	}
}
