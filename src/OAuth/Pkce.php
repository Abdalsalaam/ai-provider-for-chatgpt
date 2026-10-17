<?php
/**
 * Pkce class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

/**
 * Random values and the S256 code challenge for the authorization request.
 *
 * @since 1.0.0
 */
final class Pkce {

	/**
	 * Returns a random base64url string without padding.
	 *
	 * @since 1.0.0
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string The encoded value.
	 */
	public static function random( int $bytes = 32 ): string {
		return self::base64url( random_bytes( $bytes ) );
	}

	/**
	 * Returns the S256 challenge for a verifier.
	 *
	 * @since 1.0.0
	 *
	 * @param string $verifier The code verifier.
	 * @return string The base64url SHA-256 digest.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/**
	 * Encodes bytes as base64url without padding.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Raw bytes.
	 * @return string The encoded value.
	 */
	private static function base64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 base64url encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
