<?php
/**
 * Tests for the account authentication with the real refresher and token endpoint.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\OAuthException;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

use const Halawa\ChatGptAiProvider\API_BASE_URL;
use const Halawa\ChatGptAiProvider\TOKEN_ENDPOINT;

require_once __DIR__ . '/includes/class-api-test-helpers.php';
require_once __DIR__ . '/includes/class-oauth-test-helpers.php';

/**
 * Pins the contract between stream A's refresher and stream B's authentication.
 */
class Test_Auth_Real_Refresher extends Test_Case {

	/**
	 * Sends one prompt through a model using the real refresher for $store.
	 *
	 * @param TokenStore $store Store.
	 * @return void
	 */
	private function send( TokenStore $store ): void {
		Api_Test_Helpers::model( null, new ChatGptAccountAuthentication( $store ) )
			->generateTextResult( array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );
	}

	/**
	 * Returns the URLs of the recorded requests, in order.
	 *
	 * @return list<string>
	 */
	private static function urls(): array {
		return array_column( Http_Mock::requests(), 'url' );
	}

	/**
	 * Queues one successful /responses stream.
	 *
	 * @return void
	 */
	private function queue_stream(): void {
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
	}

	/**
	 * A due token is rotated first and the request carries the new token.
	 */
	public function test_due_token_rotates_then_sends_the_new_token() {
		$store = Api_Test_Helpers::connected_store( array( 'expires_at' => time() + 60 ) );
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response() ) );
		$this->queue_stream();

		$this->send( $store );

		$requests = Http_Mock::requests();
		$this->assertSame( array( TOKEN_ENDPOINT, API_BASE_URL . '/responses' ), self::urls() );
		$this->assertSame( 'refresh-1', $requests[0]['body']['refresh_token'] );
		$this->assertSame( 'Bearer at_2', Api_Test_Helpers::request_headers( $requests[1] )['authorization'] );
		$this->assertSame( 'rt_2', $store->get_refresh_token() );
	}

	/**
	 * A due token whose refresh gets a 503 server_error still sends the old token.
	 */
	public function test_due_token_with_refresh_503_still_sends_the_old_token() {
		$store = Api_Test_Helpers::connected_store( array( 'expires_at' => time() + 60 ) );
		Http_Mock::queue( Http_Mock::json( array( 'error' => 'server_error' ), 503 ) );
		$this->queue_stream();

		$this->send( $store );

		$requests = Http_Mock::requests();
		$this->assertSame( array( TOKEN_ENDPOINT, API_BASE_URL . '/responses' ), self::urls() );
		$this->assertSame( 'Bearer access-1', Api_Test_Helpers::request_headers( $requests[1] )['authorization'] );
		$this->assertFalse( $store->needs_signin() );
	}

	/**
	 * A due token whose refresh hits a network error still sends the old token.
	 */
	public function test_due_token_with_refresh_network_error_still_sends() {
		$store = Api_Test_Helpers::connected_store( array( 'expires_at' => time() + 60 ) );
		Http_Mock::queue(
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
			}
		);
		$this->queue_stream();

		$this->send( $store );

		$requests = Http_Mock::requests();
		$this->assertSame( array( TOKEN_ENDPOINT, API_BASE_URL . '/responses' ), self::urls() );
		$this->assertSame( 'Bearer access-1', Api_Test_Helpers::request_headers( $requests[1] )['authorization'] );
	}

	/**
	 * An expired token whose refresh gets a 503 throws server_error with no API call.
	 */
	public function test_expired_token_with_refresh_503_throws_without_an_api_call() {
		$store = Api_Test_Helpers::connected_store( array( 'expires_at' => time() - 10 ) );
		Http_Mock::queue( Http_Mock::json( array( 'error' => 'server_error' ), 503 ) );

		try {
			$this->send( $store );
			$this->fail( 'No exception' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'server_error', $e->getErrorCode() );
			$this->assertSame( 503, $e->getHttpStatus() );
		}
		$this->assertSame( array( TOKEN_ENDPOINT ), self::urls() );
		$this->assertFalse( $store->needs_signin() );
	}

	/**
	 * A reused refresh token marks the record for sign-in after one HTTP call.
	 */
	public function test_refresh_token_reused_marks_needs_signin_with_one_http_call() {
		$store = Api_Test_Helpers::connected_store( array( 'expires_at' => time() + 60 ) );
		Http_Mock::queue( Http_Mock::json( array( 'error' => 'refresh_token_reused' ), 400 ) );

		try {
			$this->send( $store );
			$this->fail( 'No exception' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'needs_signin', $e->getErrorCode() );
			$this->assertSame( ChatGptAccountAuthentication::SESSION_ENDED, $e->getMessage() );
		}
		$this->assertSame( array( TOKEN_ENDPOINT ), self::urls() );
		$this->assertTrue( ( new TokenStore() )->needs_signin() );
	}

	/**
	 * A 401 makes force_refresh rotate the token and the retry carries the new one.
	 */
	public function test_401_rotates_through_force_refresh_and_retries_with_the_new_token() {
		$store = Api_Test_Helpers::connected_store();
		Http_Mock::queue( Http_Mock::json( array( 'detail' => 'Unauthorized' ), 401 ) );
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response() ) );
		$this->queue_stream();

		$this->send( $store );

		$requests = Http_Mock::requests();
		$this->assertSame( array( API_BASE_URL . '/responses', TOKEN_ENDPOINT, API_BASE_URL . '/responses' ), self::urls() );
		$this->assertSame( 'Bearer access-1', Api_Test_Helpers::request_headers( $requests[0] )['authorization'] );
		$this->assertSame( 'Bearer at_2', Api_Test_Helpers::request_headers( $requests[2] )['authorization'] );
	}
}
