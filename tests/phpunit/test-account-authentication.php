<?php
/**
 * Tests for the provider base URL and request authentication.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\OAuthException;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;

require_once __DIR__ . '/includes/class-api-test-helpers.php';

/**
 * Pins headers, refresh decisions and the 401 retry.
 */
class Test_Account_Authentication extends Test_Case {

	/**
	 * Builds the authentication with a refresher double.
	 *
	 * @param TokenStore $store Store.
	 * @return array{0: ChatGptAccountAuthentication, 1: Api_Test_Refresher}
	 */
	private function auth( TokenStore $store ): array {
		$refresher = new Api_Test_Refresher( $store );
		return array( new ChatGptAccountAuthentication( $store, $refresher ), $refresher );
	}

	/**
	 * Sends one prompt with $auth.
	 *
	 * @param ChatGptAccountAuthentication $auth Authentication.
	 * @return void
	 */
	private function send( ChatGptAccountAuthentication $auth ): void {
		Api_Test_Helpers::model( null, $auth )->generateTextResult( array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );
	}

	/**
	 * Queues one successful stream.
	 *
	 * @return void
	 */
	private function queue_ok(): void {
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
	}

	/**
	 * Model request goes to API OpenAI com.
	 */
	public function test_model_request_goes_to_api_openai_com() {
		$this->queue_ok();
		list( $auth ) = $this->auth( Api_Test_Helpers::connected_store() );
		$this->send( $auth );
		$request = Api_Test_Helpers::last_request();
		$this->assertSame( 'https://api.openai.com/v1/responses', $request['url'] );
		$this->assertSame( 'POST', $request['method'] );
	}

	/**
	 * Only bearer and user agent are added.
	 */
	public function test_only_bearer_and_user_agent_are_added() {
		$this->queue_ok();
		list( $auth ) = $this->auth( Api_Test_Helpers::connected_store() );
		$this->send( $auth );
		$headers = Api_Test_Helpers::request_headers( Api_Test_Helpers::last_request() );
		$this->assertSame( 'Bearer access-1', $headers['authorization'] );
		$this->assertSame( \Halawa\ChatGptAiProvider\user_agent(), $headers['user-agent'] );
		unset( $headers['host'], $headers['content-type'], $headers['accept'], $headers['content-length'] );
		$this->assertSame( array( 'authorization', 'user-agent' ), array_keys( $headers ) );
	}

	/**
	 * Needs signin throws without any request.
	 */
	public function test_needs_signin_throws_without_any_request() {
		$store = Api_Test_Helpers::connected_store();
		$store->mark_needs_signin();
		list( $auth, $refresher ) = $this->auth( $store );
		try {
			$this->send( $auth );
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( ChatGptAccountAuthentication::SESSION_ENDED, $e->getMessage() );
		}
		$this->assertSame( array(), Http_Mock::requests() );
		$this->assertSame( array(), $refresher->calls );
	}

	/**
	 * No record throws not connected without refresh.
	 */
	public function test_no_record_throws_not_connected_without_refresh() {
		list( $auth, $refresher ) = $this->auth( new TokenStore() );
		$refresher->due = true;
		try {
			$this->send( $auth );
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( ChatGptAccountAuthentication::NOT_CONNECTED, $e->getMessage() );
		}
		$this->assertSame( array(), Http_Mock::requests() );
		$this->assertSame( array(), $refresher->calls );
	}

	/**
	 * Due token is refreshed with the current refresh token first.
	 */
	public function test_due_token_is_refreshed_with_the_current_refresh_token_first() {
		$store                    = Api_Test_Helpers::connected_store();
		list( $auth, $refresher ) = $this->auth( $store );
		$refresher->due           = true;
		$refresher->on_refresh    = static function () use ( $store ) {
			$store->update(
				array(
					'access_token'  => 'access-2',
					'refresh_token' => 'refresh-2',
				)
			);
		};
		$this->queue_ok();
		$this->send( $auth );
		$this->assertSame( array( 'refresh-1' ), $refresher->calls );
		$this->assertSame( 'Bearer access-2', Api_Test_Helpers::request_headers( Api_Test_Helpers::last_request() )['authorization'] );
	}

	/**
	 * Refresh network failure with unexpired token still sends.
	 */
	public function test_refresh_network_failure_with_unexpired_token_still_sends() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		$refresher->due           = true;
		$refresher->expired       = false;
		$refresher->on_refresh    = static function () {
			throw new OAuthException( 'network_error', 'cURL error 28: timed out' );
		};
		$this->queue_ok();
		$this->send( $auth );
		$this->assertCount( 1, Http_Mock::requests() );
		$this->assertSame( 'Bearer access-1', Api_Test_Helpers::request_headers( Api_Test_Helpers::last_request() )['authorization'] );
	}

	/**
	 * Refresh 5xx failure with unexpired token still sends.
	 */
	public function test_refresh_5xx_failure_with_unexpired_token_still_sends() {
		foreach ( array( array( 'server_error', 503 ), array( 'temporarily_unavailable', 503 ), array( 'http_502', 502 ) ) as list( $code, $status ) ) {
			Http_Mock::reset();
			list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
			$refresher->due           = true;
			$refresher->on_refresh    = static function () use ( $code, $status ) {
				throw new OAuthException( $code, 'Service unavailable', $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
			};
			$this->queue_ok();
			$this->send( $auth );
			$this->assertCount( 1, Http_Mock::requests(), $code );
		}
	}

	/**
	 * Refresh 4xx failure is not treated as transient.
	 */
	public function test_refresh_4xx_failure_is_not_treated_as_transient() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		$refresher->due           = true;
		$refresher->on_refresh    = static function () {
			throw new OAuthException( 'invalid_client', 'Unknown client', 401 );
		};
		$this->expectException( OAuthException::class );
		$this->send( $auth );
	}

	/**
	 * Refresh lock timeout with unexpired token still sends.
	 */
	public function test_refresh_lock_timeout_with_unexpired_token_still_sends() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		$refresher->due           = true;
		$refresher->on_refresh    = static function () {
			throw new OAuthException( 'refresh_in_progress', 'Another request is refreshing the token.' );
		};
		$this->queue_ok();
		$this->send( $auth );
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * Refresh lock timeout with expired token throws.
	 */
	public function test_refresh_lock_timeout_with_expired_token_throws() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		$refresher->due           = true;
		$refresher->expired       = true;
		$refresher->on_refresh    = static function () {
			throw new OAuthException( 'refresh_in_progress', 'Another request is refreshing the token.' );
		};
		$this->expectException( OAuthException::class );
		$this->send( $auth );
	}

	/**
	 * Refresh failure with expired token throws.
	 */
	public function test_refresh_failure_with_expired_token_throws() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		$refresher->due           = true;
		$refresher->expired       = true;
		$refresher->on_refresh    = static function () {
			throw new OAuthException( 'network_error', 'cURL error 28: timed out' );
		};
		$this->expectException( OAuthException::class );
		try {
			$this->send( $auth );
		} finally {
			$this->assertSame( array(), Http_Mock::requests() );
		}
	}

	/**
	 * Terminal refresh failure throws even when unexpired.
	 */
	public function test_terminal_refresh_failure_throws_even_when_unexpired() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		$refresher->due           = true;
		$refresher->on_refresh    = static function () {
			throw new OAuthException( 'needs_signin', 'ChatGPT session ended. Sign in again under Settings > ChatGPT.' );
		};
		$this->expectException( OAuthException::class );
		$this->send( $auth );
	}

	/**
	 * 401 refreshes once and retries with the new token.
	 */
	public function test_401_refreshes_once_and_retries_with_the_new_token() {
		$store                    = Api_Test_Helpers::connected_store();
		list( $auth, $refresher ) = $this->auth( $store );
		$refresher->on_refresh    = static function () use ( $store ) {
			$store->update( array( 'access_token' => 'access-2' ) );
		};
		Http_Mock::queue( Api_Test_Helpers::response( 401, '{"detail":"Unauthorized"}' ) );
		$this->queue_ok();
		$this->send( $auth );
		$requests = Http_Mock::requests();
		$this->assertCount( 2, $requests );
		$this->assertSame( array( 'refresh-1' ), $refresher->calls );
		$this->assertSame( 'Bearer access-2', Api_Test_Helpers::request_headers( $requests[1] )['authorization'] );
	}

	/**
	 * Second 401 surfaces the error message.
	 */
	public function test_second_401_surfaces_the_error_message() {
		$store                    = Api_Test_Helpers::connected_store();
		list( $auth, $refresher ) = $this->auth( $store );
		$refresher->on_refresh    = static function () use ( $store ) {
			$store->update( array( 'access_token' => 'access-2' ) );
		};
		Http_Mock::queue( Api_Test_Helpers::response( 401, '{"detail":"Unauthorized"}' ) );
		Http_Mock::queue( Api_Test_Helpers::response( 401, '{"detail":"Unauthorized"}', array( 'x-oai-request-id' => 'req_7' ) ) );
		try {
			$this->send( $auth );
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'ChatGPT: Unauthorized (request id req_7)', $e->getMessage() );
		}
		$this->assertCount( 2, Http_Mock::requests() );
	}

	/**
	 * A 401 whose refresh leaves the same token raises the 401 without a retry.
	 */
	public function test_401_without_a_new_token_raises_without_a_retry() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		Http_Mock::queue( Api_Test_Helpers::response( 401, '{"detail":"Unauthorized"}', array( 'x-oai-request-id' => 'req_8' ) ) );
		try {
			$this->send( $auth );
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'ChatGPT: Unauthorized (request id req_8)', $e->getMessage() );
		}
		$this->assertCount( 1, Http_Mock::requests() );
		$this->assertSame( array( 'refresh-1' ), $refresher->calls );
	}

	/**
	 * 401 then terminal refresh failure surfaces the refresh error.
	 */
	public function test_401_then_terminal_refresh_failure_surfaces_the_refresh_error() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		$refresher->on_refresh    = static function () {
			throw new OAuthException( 'needs_signin', 'ChatGPT session ended. Sign in again under Settings > ChatGPT.' );
		};
		Http_Mock::queue( Api_Test_Helpers::response( 401, '{"error":{"code":"subscription_sharing_invalid_user","message":"bad"}}' ) );
		try {
			$this->send( $auth );
			$this->fail( 'No exception' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'ChatGPT session ended. Sign in again under Settings > ChatGPT.', $e->getMessage() );
		}
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * Force refresh skips when the stored token already changed.
	 */
	public function test_force_refresh_skips_when_the_stored_token_already_changed() {
		$store                    = Api_Test_Helpers::connected_store( array( 'access_token' => 'access-2' ) );
		list( $auth, $refresher ) = $this->auth( $store );
		$this->assertTrue( $auth->force_refresh( 'access-1' ) );
		$this->assertSame( array(), $refresher->calls );
	}

	/**
	 * Force refresh refreshes when the token is unchanged.
	 */
	public function test_force_refresh_refreshes_when_the_token_is_unchanged() {
		$store                    = Api_Test_Helpers::connected_store();
		list( $auth, $refresher ) = $this->auth( $store );
		$refresher->on_refresh    = static function () use ( $store ) {
			$store->update( array( 'access_token' => 'access-2' ) );
		};
		$this->assertTrue( $auth->force_refresh( 'access-1' ) );
		$this->assertSame( array( 'refresh-1' ), $refresher->calls );
		$this->assertSame( array( true ), $refresher->forced_calls );
	}

	/**
	 * Force refresh reports false when the refresh leaves the same token.
	 */
	public function test_force_refresh_reports_false_when_the_token_is_unchanged() {
		list( $auth ) = $this->auth( Api_Test_Helpers::connected_store() );
		$this->assertFalse( $auth->force_refresh( 'access-1' ) );
	}

	/**
	 * A timed-out lock wait during force refresh reports false instead of throwing.
	 */
	public function test_force_refresh_reports_false_after_a_lock_wait_timeout() {
		list( $auth, $refresher ) = $this->auth( Api_Test_Helpers::connected_store() );
		$refresher->on_refresh    = static function () {
			throw new OAuthException( 'refresh_in_progress', 'ChatGPT tokens are being refreshed by another request. Try again in a moment.' );
		};
		$this->assertFalse( $auth->force_refresh( 'access-1' ) );
	}

	/**
	 * Registry placeholder does not replace the account authentication.
	 */
	public function test_registry_placeholder_does_not_replace_the_account_authentication() {
		$this->queue_ok();
		list( $auth ) = $this->auth( Api_Test_Helpers::connected_store() );
		$model        = Api_Test_Helpers::model( null, $auth );
		$model->setRequestAuthentication( new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( 'chatgpt-account-managed' ) );
		$model->generateTextResult( array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );
		$this->assertSame( 'Bearer access-1', Api_Test_Helpers::request_headers( Api_Test_Helpers::last_request() )['authorization'] );
	}
}
