<?php
/**
 * IdTokenClaims class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

/**
 * Lightweight reader for the custom claims OpenAI embeds in `id_token` JWTs.
 *
 * Only the payload is inspected; the signature is not verified because the
 * tokens are returned over TLS directly from the OpenAI token endpoint and
 * are stored locally (we never trust an id_token presented by a third party).
 *
 * @since 0.1.0
 */
final class IdTokenClaims {

	private const NAMESPACE_PREFIX = 'https://api.openai.com/auth';

	/**
	 * Decodes the JWT payload into an associative array.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id_token The JWT.
	 * @return array<string, mixed> The decoded payload, or an empty array on failure.
	 */
	public static function payload( string $id_token ): array {
		$parts = explode( '.', $id_token );
		if ( count( $parts ) < 2 ) {
			return array();
		}
		$json = self::base64UrlDecode( $parts[1] );
		if ( null === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Returns true when the given JWT's `exp` claim is in the past (with a
	 * small leeway).
	 *
	 * Fail-secure semantics: a JWT with no `exp` claim, a non-numeric `exp`,
	 * or an undecodable payload is treated as expired so the auth layer will
	 * try to refresh rather than blindly trust a malformed token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $jwt          The JWT to inspect.
	 * @param int    $leeway_seconds Seconds before actual `exp` to consider it expired.
	 * @return bool True when expired.
	 */
	public static function is_expired( string $jwt, int $leeway_seconds = 60 ): bool {
		$payload = self::payload( $jwt );
		if ( array() === $payload ) {
			return true;
		}
		if ( ! isset( $payload['exp'] ) || ! is_int( $payload['exp'] ) ) {
			return true;
		}
		return $payload['exp'] - $leeway_seconds <= time();
	}

	/**
	 * Returns the user-visible email associated with the id_token, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id_token The JWT.
	 * @return string|null The email.
	 */
	public static function email( string $id_token ): ?string {
		$payload = self::payload( $id_token );
		$email   = $payload['email'] ?? null;
		return is_string( $email ) ? $email : null;
	}

	/**
	 * Returns the ChatGPT workspace/account id namespaced claim, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id_token The JWT.
	 * @return string|null The account id.
	 */
	public static function account_id( string $id_token ): ?string {
		return self::namespaced_string( $id_token, 'chatgpt_account_id' );
	}

	/**
	 * Returns the ChatGPT plan type namespaced claim (e.g. "plus", "pro"), or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id_token The JWT.
	 * @return string|null The plan type.
	 */
	public static function plan_type( string $id_token ): ?string {
		return self::namespaced_string( $id_token, 'chatgpt_plan_type' );
	}

	/**
	 * Returns true when the account is FedRAMP-flagged.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id_token The JWT.
	 * @return bool True when FedRAMP.
	 */
	public static function is_fedramp( string $id_token ): bool {
		$payload = self::payload( $id_token );
		$key     = self::NAMESPACE_PREFIX;
		if ( ! isset( $payload[ $key ] ) || ! is_array( $payload[ $key ] ) ) {
			return false;
		}
		return ! empty( $payload[ $key ]['chatgpt_account_is_fedramp'] );
	}

	/**
	 * Looks up a namespaced string claim under the OpenAI custom-claims namespace.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id_token The JWT.
	 * @param string $key     The claim key inside the namespace.
	 * @return string|null The claim value, or null when missing.
	 */
	private static function namespaced_string( string $id_token, string $key ): ?string {
		$payload = self::payload( $id_token );
		if ( ! isset( $payload[ self::NAMESPACE_PREFIX ] ) || ! is_array( $payload[ self::NAMESPACE_PREFIX ] ) ) {
			return null;
		}
		$value = $payload[ self::NAMESPACE_PREFIX ][ $key ] ?? null;
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Decodes a base64url-encoded JWT segment.
	 *
	 * @since 0.1.0
	 *
	 * @param string $segment The segment.
	 * @return string|null The decoded bytes, or null when invalid.
	 */
	private static function base64UrlDecode( string $segment ): ?string {
		$padded    = strtr( $segment, '-_', '+/' );
		$remainder = strlen( $padded ) % 4;
		if ( $remainder > 0 ) {
			$padded .= str_repeat( '=', 4 - $remainder );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding base64url-encoded JWT segment per RFC 7519.
		$decoded = base64_decode( $padded, true );
		return false === $decoded ? null : $decoded;
	}
}
