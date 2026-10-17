<?php
/**
 * Harness self-tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

/**
 * Checks the plugin loads and the test helpers behave.
 */
class Test_Harness extends Test_Case {

	/**
	 * The main plugin file was loaded by the bootstrap.
	 */
	public function test_plugin_loads() {
		$this->assertTrue( function_exists( 'Halawa\\ChatGptAiProvider\\register_provider' ) );
	}

	/**
	 * A queued response is served once and the request is recorded.
	 */
	public function test_queued_response_is_served_and_recorded() {
		Http_Mock::queue( Http_Mock::json( array( 'ok' => true ), 201, array( 'x-oai-request-id' => 'req_1' ) ) );

		$response = wp_remote_post(
			'https://auth.openai.com/api/accounts/oauth/token',
			array( 'body' => array( 'grant_type' => 'refresh_token' ) )
		);

		$this->assertSame( 201, wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( '{"ok":true}', wp_remote_retrieve_body( $response ) );
		$this->assertSame( 'req_1', wp_remote_retrieve_header( $response, 'x-oai-request-id' ) );
		$requests = Http_Mock::requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://auth.openai.com/api/accounts/oauth/token', $requests[0]['url'] );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertSame( array( 'grant_type' => 'refresh_token' ), $requests[0]['body'] );
	}

	/**
	 * A callable response receives the request arguments and URL.
	 */
	public function test_callable_response_sees_the_request() {
		Http_Mock::queue(
			static function ( $args, $url ) {
				return Http_Mock::json(
					array(
						'url'    => $url,
						'method' => $args['method'],
					)
				);
			}
		);

		$response = wp_remote_get( 'https://api.openai.com/v1/models' );

		$this->assertSame( '{"url":"https:\/\/api.openai.com\/v1\/models","method":"GET"}', wp_remote_retrieve_body( $response ) );
	}

	/**
	 * A request with nothing queued fails closed instead of reaching the network.
	 */
	public function test_unqueued_request_fails_closed() {
		$response = wp_remote_get( 'https://api.openai.com/v1/models' );

		$this->assertWPError( $response );
		$this->assertSame( 'http_mock_unqueued', $response->get_error_code() );
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * Leaves an item queued so the next test proves the reset.
	 */
	public function test_leaves_an_item_queued() {
		Http_Mock::queue( Http_Mock::json( array() ) );
		$this->assertCount( 0, Http_Mock::requests() );
	}

	/**
	 * Runs after the test above and sees an empty mock.
	 *
	 * @depends test_leaves_an_item_queued
	 */
	public function test_queue_is_empty_at_start() {
		$this->assertSame( array(), Http_Mock::requests() );
		$this->assertWPError( wp_remote_get( 'https://example.com/' ) );
	}

	/**
	 * Requests sent through the AI Client transporter reach the mock.
	 */
	public function test_ai_client_transporter_goes_through_the_mock() {
		$transporter = AiClient::defaultRegistry()->getHttpTransporter();
		Http_Mock::queue( Http_Mock::json( array( 'models' => array() ) ) );

		$response = $transporter->send( new Request( HttpMethodEnum::from( 'GET' ), 'https://api.openai.com/v1/models' ) );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( '{"models":[]}', $response->getBody() );
		$this->assertSame( 'https://api.openai.com/v1/models', Http_Mock::requests()[0]['url'] );

		$failed = null;
		try {
			$transporter->send( new Request( HttpMethodEnum::from( 'GET' ), 'https://api.openai.com/v1/models' ) );
		} catch ( Throwable $e ) {
			$failed = $e;
		}
		$this->assertInstanceOf( Throwable::class, $failed );
		$this->assertCount( 2, Http_Mock::requests() );
	}

	/**
	 * The JWKS key verifies a token the fixture signed.
	 */
	public function test_jwks_fixture_signs_verifiable_tokens() {
		$jwt   = Jwks_Fixture::sign(
			array(
				'sub' => 'user_1',
				'aud' => array( 'oaiapp_x' ),
			)
		);
		$parts = explode( '.', $jwt );
		$this->assertCount( 3, $parts );

		$this->assertSame(
			array(
				'alg' => 'RS256',
				'typ' => 'JWT',
				'kid' => Jwks_Fixture::KID,
			),
			json_decode( self::b64url_decode( $parts[0] ), true )
		);
		$this->assertSame(
			array(
				'sub' => 'user_1',
				'aud' => array( 'oaiapp_x' ),
			),
			json_decode( self::b64url_decode( $parts[1] ), true )
		);

		$verified = openssl_verify( $parts[0] . '.' . $parts[1], self::b64url_decode( $parts[2] ), Jwks_Fixture::public_pem(), OPENSSL_ALGO_SHA256 );
		$this->assertSame( 1, $verified );

		$jwks = json_decode( Jwks_Fixture::jwks(), true );
		$this->assertSame( Jwks_Fixture::KID, $jwks['keys'][0]['kid'] );
		$this->assertSame( 'RSA', $jwks['keys'][0]['kty'] );
		$this->assertSame( 'AQAB', $jwks['keys'][0]['e'] );

		$this->assertSame( 'HS256', json_decode( self::b64url_decode( explode( '.', Jwks_Fixture::sign( array(), array( 'alg' => 'HS256' ) ) )[0] ), true )['alg'] );
	}

	/**
	 * Every stream fixture is well-formed and the JSON fixtures decode.
	 */
	public function test_fixtures_parse() {
		foreach ( array( 'stream-plain.sse', 'stream-tool-call.sse', 'stream-tool-round-trip.sse', 'stream-json-schema.sse', 'stream-failed.sse' ) as $name ) {
			$events = array();
			foreach ( preg_split( "/\n\n+/", trim( self::fixture( $name ) ) ) as $block ) {
				$this->assertMatchesRegularExpression( '/^event: [a-z_.]+\ndata: \{.*\}$/s', $block, $name );
				$data = json_decode( substr( $block, strpos( $block, 'data: ' ) + 6 ), true );
				$this->assertIsArray( $data, $name );
				$events[] = $data['type'];
			}
			$this->assertSame( 'response.created', $events[0], $name );
			$this->assertContains( end( $events ), array( 'response.completed', 'response.failed' ), $name );
		}

		$models = json_decode( self::fixture( 'models.json' ), true );
		$this->assertCount( 5, $models['models'] );
		foreach ( array( 'error-temperature.json', 'error-system-item.json' ) as $name ) {
			$this->assertArrayHasKey( 'detail', json_decode( self::fixture( $name ), true ), $name );
		}
	}

	/**
	 * Decodes base64url without padding.
	 *
	 * @param string $value Encoded value.
	 * @return string
	 */
	private static function b64url_decode( string $value ): string {
		return (string) base64_decode( strtr( $value, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT decoding.
	}
}
