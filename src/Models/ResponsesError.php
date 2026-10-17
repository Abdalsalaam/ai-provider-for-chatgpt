<?php
/**
 * ResponsesError class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Models;

use const Halawa\ChatGptAiProvider\USAGE_URL;

/**
 * Turns error responses from api.openai.com into one readable message.
 *
 * @since 0.2.0
 */
final class ResponsesError {

	/**
	 * Fixed messages for the plan usage error codes.
	 *
	 * @var array<string, string>
	 */
	private const PLAN_MESSAGES = array(
		'subscription_sharing_user_not_eligible' => 'This ChatGPT account is not eligible for plan usage.',
		'subscription_sharing_usage_unavailable' => 'ChatGPT could not check your usage, try again shortly.',
		'subscription_sharing_user_unavailable'  => 'ChatGPT could not check your usage, try again shortly.',
	);

	/**
	 * Builds the message for an error response.
	 *
	 * @since 0.2.0
	 *
	 * @param int                   $status  HTTP status.
	 * @param string                $body    Response body.
	 * @param array<string, string> $headers Response headers with lower-case names.
	 * @return string
	 */
	public static function message( int $status, string $body, array $headers ): string {
		$decoded = json_decode( $body, true );
		$message = null;
		if ( is_array( $decoded ) && isset( $decoded['detail'] ) && is_string( $decoded['detail'] ) && '' !== $decoded['detail'] ) {
			$message = 'ChatGPT: ' . $decoded['detail'];
		} elseif ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$message = self::from_error( $decoded['error'] );
		}
		if ( null === $message ) {
			$message = sprintf( 'ChatGPT returned HTTP %d.', $status );
		}

		$request_id = $headers['x-oai-request-id'] ?? '';
		if ( '' !== $request_id ) {
			$message .= ' (request id ' . $request_id . ')';
		}
		return $message;
	}

	/**
	 * Returns error.code from a JSON error body.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body Response body.
	 * @return string|null
	 */
	public static function code( string $body ): ?string {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['error'] ) || ! is_array( $decoded['error'] ) ) {
			return null;
		}
		$code = $decoded['error']['code'] ?? null;
		return is_string( $code ) && '' !== $code ? $code : null;
	}

	/**
	 * Flattens response headers to lower-case names and string values.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string|list<string>> $headers Headers as the SDK or WordPress returns them.
	 * @return array<string, string>
	 */
	public static function lower_headers( array $headers ): array {
		$flat = array();
		foreach ( $headers as $name => $value ) {
			$flat[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $flat;
	}

	/**
	 * Builds the message for an error object, from a body or a failed stream event.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $error The error object.
	 * @return string|null Null when the object carries neither code nor message.
	 */
	public static function from_error( array $error ): ?string {
		$code  = is_string( $error['code'] ?? null ) ? $error['code'] : '';
		$text  = is_string( $error['message'] ?? null ) ? $error['message'] : '';
		$param = is_string( $error['param'] ?? null ) ? $error['param'] : '';

		if ( 'subscription_sharing_usage_limit_exceeded' === $code ) {
			return 'Your ChatGPT plan usage limit was reached. Review it at ' . USAGE_URL;
		}
		if ( isset( self::PLAN_MESSAGES[ $code ] ) ) {
			return self::PLAN_MESSAGES[ $code ];
		}
		if ( 'subscription_sharing_unsupported_capability' === $code ) {
			return 'ChatGPT rejected a request feature: ' . ( '' !== $param ? $param : $text );
		}
		if ( '' === $text ) {
			return '' === $code ? null : 'ChatGPT: ' . $code;
		}
		return 'ChatGPT: ' . $text . ( '' !== $code ? ' (' . $code . ')' : '' );
	}
}
