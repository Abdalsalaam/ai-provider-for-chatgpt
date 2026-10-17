<?php
/**
 * CallbackUrl class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

use InvalidArgumentException;

/**
 * Parses the callback address the admin pastes after approving the sign-in.
 *
 * @since 0.2.0
 */
final class CallbackUrl {

	public const MAX_LENGTH    = 16384;
	public const WRONG_PAGE    = 'Paste the full address of the page you landed on. It starts with http://127.0.0.1';
	public const REPEATED      = 'The address has repeated parameters.';
	public const MISSING_STATE = 'The address is missing the state value.';
	public const MISSING_CODE  = 'The address is missing the authorization code.';

	/**
	 * Validates the address against the redirect URI and returns its parameters.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url                   The pasted address.
	 * @param string $expected_redirect_uri The redirect URI sent on authorize.
	 * @return array{code:string, state:string, client_id:?string, scope:?string, error:?string}
	 * @throws InvalidArgumentException When the address is not a usable callback.
	 */
	public static function parse( string $url, string $expected_redirect_uri ): array {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH || false !== strpos( $url, '#' ) ) {
			throw new InvalidArgumentException( self::WRONG_PAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; REST and UI escape on output.
		}

		$actual   = wp_parse_url( $url );
		$expected = wp_parse_url( $expected_redirect_uri );
		if ( ! is_array( $actual ) || ! is_array( $expected ) || ! self::same_endpoint( $actual, $expected ) ) {
			throw new InvalidArgumentException( self::WRONG_PAGE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; REST and UI escape on output.
		}

		$params = self::query_params( (string) ( $actual['query'] ?? '' ) );
		$state  = $params['state'] ?? '';
		if ( '' === $state ) {
			throw new InvalidArgumentException( self::MISSING_STATE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; REST and UI escape on output.
		}
		$error = isset( $params['error'] ) && '' !== $params['error'] ? $params['error'] : null;
		$code  = $params['code'] ?? '';
		if ( null === $error && '' === $code ) {
			throw new InvalidArgumentException( self::MISSING_CODE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; REST and UI escape on output.
		}

		return array(
			'code'      => $code,
			'state'     => $state,
			'client_id' => isset( $params['client_id'] ) && '' !== $params['client_id'] ? $params['client_id'] : null,
			'scope'     => isset( $params['scope'] ) && '' !== $params['scope'] ? $params['scope'] : null,
			'error'     => $error,
		);
	}

	/**
	 * Compares scheme, host, port and path, and rejects userinfo.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $actual   Parsed pasted address.
	 * @param array<string, mixed> $expected Parsed redirect URI.
	 * @return bool True when both point at the same endpoint.
	 */
	private static function same_endpoint( array $actual, array $expected ): bool {
		if ( isset( $actual['user'] ) || isset( $actual['pass'] ) || ! isset( $actual['scheme'], $actual['host'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) $actual['scheme'] );
		return strtolower( (string) ( $expected['scheme'] ?? '' ) ) === $scheme
			&& strtolower( (string) ( $expected['host'] ?? '' ) ) === strtolower( (string) $actual['host'] )
			&& self::port( $expected, $scheme ) === self::port( $actual, $scheme )
			&& (string) ( $expected['path'] ?? '/' ) === (string) ( $actual['path'] ?? '/' );
	}

	/**
	 * Returns the explicit port or the scheme default.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $parts  Parsed URL.
	 * @param string               $scheme Lower-case scheme.
	 * @return int The port.
	 */
	private static function port( array $parts, string $scheme ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}
		return 'https' === $scheme ? 443 : 80;
	}

	/**
	 * Splits a query string, rejecting repeated keys.
	 *
	 * @since 0.2.0
	 *
	 * @param string $query Raw query string.
	 * @return array<string, string> Decoded parameters.
	 * @throws InvalidArgumentException When a key appears twice.
	 */
	private static function query_params( string $query ): array {
		$params = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts = explode( '=', $pair, 2 );
			$key   = urldecode( $parts[0] );
			if ( array_key_exists( $key, $params ) ) {
				throw new InvalidArgumentException( self::REPEATED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; REST and UI escape on output.
			}
			$params[ $key ] = urldecode( $parts[1] ?? '' );
		}
		return $params;
	}
}
