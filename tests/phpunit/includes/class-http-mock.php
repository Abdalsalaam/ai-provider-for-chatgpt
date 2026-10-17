<?php
/**
 * HTTP mock for the plugin tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

/**
 * Serves queued responses through pre_http_request and records every request.
 */
final class Http_Mock {

	/**
	 * Responses waiting to be served, oldest first.
	 *
	 * @var array<int, callable|array>
	 */
	private static $queue = array();

	/**
	 * Requests seen since the last reset.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $requests = array();

	/**
	 * Hooks the mock into WordPress; the bootstrap calls this once.
	 */
	public static function install(): void {
		add_filter( 'pre_http_request', array( self::class, 'intercept' ), 10, 3 );
	}

	/**
	 * Queues one response.
	 *
	 * @param callable|array $response Response array, or a callable returning one.
	 */
	public static function queue( $response ): void {
		self::$queue[] = $response;
	}

	/**
	 * Returns the recorded requests.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function requests(): array {
		return self::$requests;
	}

	/**
	 * Clears queued responses and recorded requests.
	 */
	public static function reset(): void {
		self::$queue    = array();
		self::$requests = array();
	}

	/**
	 * Builds a JSON response.
	 *
	 * @param array $data    Body data.
	 * @param int   $code    HTTP status.
	 * @param array $headers Extra headers, lower-case keys.
	 * @return array
	 */
	public static function json( array $data, int $code = 200, array $headers = array() ): array {
		return self::response( (string) wp_json_encode( $data ), $code, array_merge( array( 'content-type' => 'application/json' ), $headers ) );
	}

	/**
	 * Builds a server-sent events response.
	 *
	 * @param string $body    Raw event stream.
	 * @param int    $code    HTTP status.
	 * @param array  $headers Extra headers, lower-case keys.
	 * @return array
	 */
	public static function sse( string $body, int $code = 200, array $headers = array() ): array {
		return self::response( $body, $code, array_merge( array( 'content-type' => 'text/event-stream' ), $headers ) );
	}

	/**
	 * Answers a request from the queue, or fails closed when the queue is empty.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value from earlier filters.
	 * @param array                $args Request arguments.
	 * @param string               $url  Request URL.
	 * @return array|WP_Error
	 */
	public static function intercept( $pre, $args, $url ) {
		self::$requests[] = array(
			'url'     => $url,
			'method'  => isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET',
			'headers' => isset( $args['headers'] ) ? (array) $args['headers'] : array(),
			'body'    => $args['body'] ?? null,
			'args'    => $args,
		);
		if ( array() === self::$queue ) {
			return new WP_Error( 'http_mock_unqueued', 'No mocked response queued for ' . $url );
		}
		$next = array_shift( self::$queue );
		return is_callable( $next ) ? $next( $args, $url ) : $next;
	}

	/**
	 * Builds a response array in the shape wp_remote_request returns.
	 *
	 * @param string $body    Body.
	 * @param int    $code    HTTP status.
	 * @param array  $headers Headers, lower-case keys.
	 * @return array
	 */
	private static function response( string $body, int $code, array $headers ): array {
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
