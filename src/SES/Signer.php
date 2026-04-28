<?php
/**
 * AWS Signature Version 4 signer.
 *
 * @package LEAStudios\Mailer\SES
 */

declare(strict_types=1);

namespace LEAStudios\Mailer\SES;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Implements AWS Signature Version 4 for HTTP requests.
 */
class Signer {

	/**
	 * Sign an HTTP request with AWS Signature Version 4.
	 *
	 * @param string $method     HTTP method (POST, GET, etc.).
	 * @param string $url        Full request URL.
	 * @param array  $headers    Request headers (key => value).
	 * @param string $body       Request body.
	 * @param string $access_key AWS access key ID.
	 * @param string $secret_key AWS secret access key.
	 * @param string $region     AWS region.
	 * @param string $service    AWS service name.
	 * @return array Signed headers array.
	 */
	public function sign(
		string $method,
		string $url,
		array $headers,
		string $body,
		string $access_key,
		string $secret_key,
		string $region,
		string $service = 'ses'
	): array {
		$timestamp  = gmdate( 'Ymd\THis\Z' );
		$date_stamp = gmdate( 'Ymd' );

		$parsed_url = wp_parse_url( $url );
		$host       = $parsed_url['host'] ?? '';
		$path       = $this->normalize_path( $parsed_url['path'] ?? '/' );
		$query      = $parsed_url['query'] ?? '';

		$payload_hash = hash( 'sha256', $body );

		$headers['Host']                 = $host;
		$headers['X-Amz-Date']           = $timestamp;
		$headers['X-Amz-Content-Sha256'] = $payload_hash;

		$canonical_request = $this->create_canonical_request(
			$method,
			$path,
			$query,
			$headers,
			$payload_hash
		);

		$credential_scope = "{$date_stamp}/{$region}/{$service}/aws4_request";

		$string_to_sign = $this->create_string_to_sign(
			$timestamp,
			$credential_scope,
			$canonical_request
		);

		$signing_key = $this->get_signing_key( $secret_key, $date_stamp, $region, $service );

		$signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$signed_headers_str = $this->get_signed_headers_string( $headers );

		$headers['Authorization'] = sprintf(
			'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			$access_key,
			$credential_scope,
			$signed_headers_str,
			$signature
		);

		return $headers;
	}

	/**
	 * Create the canonical request string.
	 *
	 * @param string $method       HTTP method.
	 * @param string $path         URI path.
	 * @param string $query        Query string.
	 * @param array  $headers      Request headers.
	 * @param string $payload_hash SHA256 hash of the request body.
	 * @return string The canonical request.
	 */
	private function create_canonical_request(
		string $method,
		string $path,
		string $query,
		array $headers,
		string $payload_hash
	): string {
		$canonical_headers = '';
		$lower_headers     = [];

		foreach ( $headers as $key => $value ) {
			$lower_headers[ strtolower( $key ) ] = trim( (string) $value );
		}

		ksort( $lower_headers );

		foreach ( $lower_headers as $key => $value ) {
			$canonical_headers .= $key . ':' . $value . "\n";
		}

		$signed_headers = implode( ';', array_keys( $lower_headers ) );

		return implode(
			"\n",
			[
				$method,
				$path,
				$query,
				$canonical_headers,
				$signed_headers,
				$payload_hash,
			]
		);
	}

	/**
	 * Create the string to sign.
	 *
	 * @param string $timestamp         ISO 8601 timestamp.
	 * @param string $credential_scope  Credential scope string.
	 * @param string $canonical_request The canonical request.
	 * @return string The string to sign.
	 */
	private function create_string_to_sign(
		string $timestamp,
		string $credential_scope,
		string $canonical_request
	): string {
		return implode(
			"\n",
			[
				'AWS4-HMAC-SHA256',
				$timestamp,
				$credential_scope,
				hash( 'sha256', $canonical_request ),
			]
		);
	}

	/**
	 * Derive the signing key.
	 *
	 * @param string $secret_key AWS secret key.
	 * @param string $date_stamp Date in Ymd format.
	 * @param string $region     AWS region.
	 * @param string $service    AWS service.
	 * @return string The derived signing key.
	 */
	private function get_signing_key(
		string $secret_key,
		string $date_stamp,
		string $region,
		string $service
	): string {
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );

		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * Get the signed headers string (lowercase, sorted, semicolon-separated).
	 *
	 * @param array $headers The request headers.
	 * @return string The signed headers string.
	 */
	private function get_signed_headers_string( array $headers ): string {
		$keys = array_map( 'strtolower', array_keys( $headers ) );
		sort( $keys );

		return implode( ';', $keys );
	}

	/**
	 * Normalize a URI path for the canonical request per AWS SigV4 spec.
	 *
	 * For non-S3 services, each path segment must be URI-encoded twice.
	 * Decode first to get the raw value, then double-encode.
	 * e.g. user@example.com -> user%40example.com -> user%2540example.com
	 *
	 * @param string $path The raw URI path.
	 * @return string The normalized path for signature calculation.
	 */
	private function normalize_path( string $path ): string {
		if ( '' === $path || '/' === $path ) {
			return '/';
		}

		$segments = explode( '/', $path );
		$encoded  = [];

		foreach ( $segments as $segment ) {
			$decoded   = rawurldecode( $segment );
			$encoded[] = rawurlencode( rawurlencode( $decoded ) );
		}

		return implode( '/', $encoded );
	}
}
