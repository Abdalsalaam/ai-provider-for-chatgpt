<?php
/**
 * JwksVerifier class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

use function Halawa\ChatGptAiProvider\user_agent;

use const Halawa\ChatGptAiProvider\ISSUER;
use const Halawa\ChatGptAiProvider\JWKS_URI;

/**
 * Verifies OpenAI ID tokens against the published JWKS.
 *
 * @since 1.0.0
 */
final class JwksVerifier {

	public const CACHE_KEY = 'halawa_chatgpt_jwks';

	/**
	 * JWKS location.
	 *
	 * @var string
	 */
	private $jwks_uri;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $jwks_uri JWKS location, defaults to OpenAI's.
	 */
	public function __construct( ?string $jwks_uri = null ) {
		$this->jwks_uri = $jwks_uri ?? JWKS_URI;
	}

	/**
	 * Verifies signature, issuer, audience, expiry and nonce.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id_token           The JWT.
	 * @param string $expected_client_id Client id that must be in aud.
	 * @param string $expected_nonce     Nonce sent on authorize.
	 * @param int    $leeway             Seconds of clock skew allowed on exp.
	 * @return array<string, mixed> The verified claims.
	 * @throws OAuthException When any check fails.
	 */
	public function verify( string $id_token, string $expected_client_id, string $expected_nonce, int $leeway = 60 ): array {
		$parts = explode( '.', $id_token );
		if ( 3 !== count( $parts ) ) {
			throw $this->fail( 'id_token_malformed' );
		}
		$header    = json_decode( (string) self::base64url_decode( $parts[0] ), true );
		$claims    = json_decode( (string) self::base64url_decode( $parts[1] ), true );
		$signature = self::base64url_decode( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || null === $signature
			|| 'RS256' !== ( $header['alg'] ?? null ) || ! is_string( $header['kid'] ?? null ) ) {
			throw $this->fail( 'id_token_malformed' );
		}

		$pem = $this->public_key_for( $header['kid'] );
		$key = openssl_pkey_get_public( $pem );
		if ( false === $key || 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw $this->fail( 'id_token_bad_signature' );
		}

		if ( ISSUER !== ( $claims['iss'] ?? null ) ) {
			throw $this->fail( 'id_token_bad_issuer' );
		}
		$aud = $claims['aud'] ?? null;
		$aud = is_string( $aud ) ? array( $aud ) : ( is_array( $aud ) ? $aud : array() );
		if ( ! in_array( $expected_client_id, $aud, true ) ) {
			throw $this->fail( 'id_token_bad_audience' );
		}
		if ( ! is_int( $claims['exp'] ?? null ) || $claims['exp'] + $leeway <= time() ) {
			throw $this->fail( 'id_token_expired' );
		}
		if ( ! is_string( $claims['nonce'] ?? null ) || ! hash_equals( $expected_nonce, $claims['nonce'] ) ) {
			throw $this->fail( 'id_token_bad_nonce' );
		}
		return $claims;
	}

	/**
	 * Returns the PEM for a kid, refetching the JWKS once when the kid is unknown.
	 *
	 * @since 1.0.0
	 *
	 * @param string $kid Key id from the token header.
	 * @return string PEM public key.
	 * @throws OAuthException When the JWKS cannot be loaded or lacks the kid.
	 */
	private function public_key_for( string $kid ): string {
		$cached = get_transient( self::CACHE_KEY );
		$keys   = is_array( $cached ) ? $cached : $this->fetch();
		$jwk    = self::find( $keys, $kid );
		if ( null === $jwk && is_array( $cached ) ) {
			$jwk = self::find( $this->fetch(), $kid );
		}
		if ( null === $jwk ) {
			throw $this->fail( 'id_token_unknown_kid' );
		}
		return $this->jwk_to_pem( $jwk['n'], $jwk['e'] );
	}

	/**
	 * Fetches the JWKS and caches its keys for a day.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, mixed> The keys array.
	 * @throws OAuthException When the response is not a usable JWKS.
	 */
	private function fetch(): array {
		$response = wp_remote_get(
			$this->jwks_uri,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => user_agent(),
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			throw $this->fail( 'jwks_unavailable' );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['keys'] ) || ! is_array( $data['keys'] ) ) {
			throw $this->fail( 'jwks_unavailable' );
		}
		set_transient( self::CACHE_KEY, $data['keys'], DAY_IN_SECONDS );
		return $data['keys'];
	}

	/**
	 * Finds an RSA key by kid.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $keys JWKS keys.
	 * @param string            $kid  Key id.
	 * @return array{n:string, e:string}|null The key, or null.
	 */
	private static function find( array $keys, string $kid ): ?array {
		foreach ( $keys as $key ) {
			if ( is_array( $key ) && ( $key['kid'] ?? null ) === $kid && 'RSA' === ( $key['kty'] ?? null )
				&& is_string( $key['n'] ?? null ) && is_string( $key['e'] ?? null ) ) {
				return array(
					'n' => $key['n'],
					'e' => $key['e'],
				);
			}
		}
		return null;
	}

	/**
	 * Builds a SubjectPublicKeyInfo PEM from the JWK modulus and exponent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $n Base64url modulus.
	 * @param string $e Base64url exponent.
	 * @return string PEM public key.
	 */
	private function jwk_to_pem( string $n, string $e ): string {
		$rsa_key   = self::der( 0x30, self::der_integer( (string) self::base64url_decode( $n ) ) . self::der_integer( (string) self::base64url_decode( $e ) ) );
		$algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
		$spki      = self::der( 0x30, $algorithm . self::der( 0x03, "\x00" . $rsa_key ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM armour for a DER public key.
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * Encodes a DER INTEGER, adding a zero byte when the high bit is set.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Big-endian unsigned bytes.
	 * @return string DER INTEGER.
	 */
	private static function der_integer( string $bytes ): string {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes || ord( $bytes[0] ) > 0x7f ) {
			$bytes = "\x00" . $bytes;
		}
		return self::der( 0x02, $bytes );
	}

	/**
	 * Wraps content in a DER tag and length.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $tag     DER tag.
	 * @param string $content Encoded content.
	 * @return string DER element.
	 */
	private static function der( int $tag, string $content ): string {
		$length = strlen( $content );
		if ( $length < 0x80 ) {
			return chr( $tag ) . chr( $length ) . $content;
		}
		$length_bytes = ltrim( pack( 'N', $length ), "\x00" );
		return chr( $tag ) . chr( 0x80 | strlen( $length_bytes ) ) . $length_bytes . $content;
	}

	/**
	 * Decodes a base64url segment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $segment Encoded segment.
	 * @return string|null Raw bytes, or null when invalid.
	 */
	private static function base64url_decode( string $segment ): ?string {
		$padded = strtr( $segment, '-_', '+/' );
		$pad    = strlen( $padded ) % 4;
		if ( $pad > 0 ) {
			$padded .= str_repeat( '=', 4 - $pad );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a JWT segment per RFC 7515.
		$decoded = base64_decode( $padded, true );
		return false === $decoded ? null : $decoded;
	}

	/**
	 * Builds the exception for a failed check.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Error code.
	 * @return OAuthException The exception.
	 */
	private function fail( string $code ): OAuthException {
		return new OAuthException( $code, 'The ChatGPT ID token could not be verified (' . $code . ').' );
	}
}
