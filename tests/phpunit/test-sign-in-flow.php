<?php
/**
 * Tests for SignInFlow.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\OAuthException;
use Halawa\ChatGptAiProvider\OAuth\Pkce;
use Halawa\ChatGptAiProvider\OAuth\SignInFlow;

require_once __DIR__ . '/includes/class-oauth-test-helpers.php';

/**
 * SignInFlow tests.
 */
class Test_Sign_In_Flow extends Test_Case {

	const USER = 7;

	/**
	 * Token store under test.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Fresh store and host id per test.
	 */
	public function set_up() {
		parent::set_up();
		$this->store = new TokenStore();
		update_option( 'halawa_chatgpt_installation_id', '0b6a2c8e-1f0d-4c55-9a3e-6f1b2d7c9e10', false );
		delete_transient( 'halawa_chatgpt_jwks' );
	}

	/**
	 * Parses the query of an authorize URL.
	 *
	 * @param string $url Authorize URL.
	 * @return array<string, string>
	 */
	private function query( $url ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		return $params;
	}

	/**
	 * Builds the callback URL for the pending attempt.
	 *
	 * @param array $overrides Query overrides; null removes a key.
	 * @return string
	 */
	private function callback_url( array $overrides = array() ) {
		$pending = get_transient( SignInFlow::pending_key( self::USER ) );
		$params  = array_merge(
			array(
				'code'      => 'ac_1',
				'scope'     => 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct',
				'state'     => $pending['state'],
				'client_id' => 'oaiapp_x',
			),
			$overrides
		);
		return 'http://127.0.0.1:1455/auth/callback?' . http_build_query( array_filter( $params, 'is_string' ), '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Callback overrides for a denied consent.
	 *
	 * @return array
	 */
	private static function denied() {
		return array(
			'error' => 'access_denied',
			'code'  => null,
		);
	}

	/**
	 * Queues the token response and the JWKS for a successful exchange.
	 *
	 * @param array $claims         ID token claim overrides.
	 * @param array $token_override Token response overrides.
	 */
	private function queue_exchange( array $claims = array(), array $token_override = array() ) {
		$pending  = get_transient( SignInFlow::pending_key( self::USER ) );
		$id_token = Jwks_Fixture::sign(
			array_merge(
				array(
					'iss'   => 'https://auth.openai.com',
					'sub'   => 'user_1',
					'aud'   => array( 'oaiapp_x' ),
					'nonce' => $pending['nonce'],
					'email' => 'ada@example.com',
					'name'  => 'Ada',
					'exp'   => time() + 3600,
				),
				$claims
			)
		);
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response( array_merge( array( 'id_token' => $id_token ), $token_override ) ) ) );
		Http_Mock::queue( Http_Mock::json( json_decode( Jwks_Fixture::jwks(), true ) ) );
	}

	/**
	 * Runs complete() and returns the OAuthException code.
	 *
	 * @param string $url Callback URL.
	 * @return string
	 */
	private function complete_error( $url ) {
		try {
			( new SignInFlow( $this->store ) )->complete( self::USER, $url );
		} catch ( OAuthException $e ) {
			return $e->getErrorCode();
		}
		$this->fail( 'Expected OAuthException' );
	}

	/**
	 * First registration sends the exact parameter set with the agent name.
	 */
	public function test_start_first_registration() {
		$start  = ( new SignInFlow( $this->store ) )->start( self::USER );
		$params = $this->query( $start['authorize_url'] );

		$this->assertStringStartsWith( 'https://auth.openai.com/api/accounts/authorize?', $start['authorize_url'] );
		$this->assertSame(
			array( 'agent_name_hint', 'client_id', 'code_challenge', 'code_challenge_method', 'ext_agent_host_id', 'nonce', 'redirect_uri', 'resource', 'response_type', 'scope', 'state' ),
			array_keys( $this->sorted( $params ) )
		);
		$this->assertSame( 'dynamic_agent_client', $params['client_id'] );
		$this->assertSame( 'AI Provider for ChatGPT', $params['agent_name_hint'] );
		$this->assertSame( 'urn:uuid:0b6a2c8e-1f0d-4c55-9a3e-6f1b2d7c9e10', $params['ext_agent_host_id'] );
		$this->assertSame( 'code', $params['response_type'] );
		$this->assertSame( 'http://127.0.0.1:1455/auth/callback', $params['redirect_uri'] );
		$this->assertSame( 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct', $params['scope'] );
		$this->assertSame( 'https://api.openai.com/v1', $params['resource'] );
		$this->assertSame( 'S256', $params['code_challenge_method'] );
		$this->assertStringNotContainsString( '+', $start['authorize_url'] );

		$pending = get_transient( SignInFlow::pending_key( self::USER ) );
		$this->assertSame( $pending['state'], $params['state'] );
		$this->assertSame( $pending['nonce'], $params['nonce'] );
		$this->assertSame( Pkce::challenge( $pending['verifier'] ), $params['code_challenge'] );
		$this->assertSame( 64, strlen( $pending['verifier'] ) );
		$this->assertSame( 'dynamic_agent_client', $pending['client_id'] );
		$this->assertSame( 'http://127.0.0.1:1455/auth/callback', $start['redirect_uri'] );
		$this->assertEqualsWithDelta( time() + 600, $start['expires_at'], 2 );
		$this->assertEqualsWithDelta( time() + 600, (int) get_option( '_transient_timeout_' . SignInFlow::pending_key( self::USER ) ), 2 );
	}

	/**
	 * Sorts params by key for an order-independent comparison.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	private function sorted( array $params ) {
		ksort( $params );
		return $params;
	}

	/**
	 * Re-login uses the issued client id and the hints, without the agent name.
	 */
	public function test_start_relogin_hints() {
		$this->store->save( OAuth_Test_Helpers::record( array( 'needs_signin' => true ) ) );
		$params = $this->query( ( new SignInFlow( $this->store ) )->start( self::USER )['authorize_url'] );

		$this->assertSame( 'oaiapp_x', $params['client_id'] );
		$this->assertSame( 'it_1', $params['id_token_hint'] );
		$this->assertSame( 'ada@example.com', $params['login_hint'] );
		$this->assertArrayNotHasKey( 'agent_name_hint', $params );
	}

	/**
	 * Starting again replaces the pending attempt.
	 */
	public function test_start_again_replaces_pending() {
		$flow  = new SignInFlow( $this->store );
		$first = $this->query( $flow->start( self::USER )['authorize_url'] )['state'];
		$flow->start( self::USER );
		$this->assertNotSame( $first, get_transient( SignInFlow::pending_key( self::USER ) )['state'] );
	}

	/**
	 * A successful first sign-in saves the full record and the notice flag.
	 */
	public function test_complete_success() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->queue_exchange();

		$record = ( new SignInFlow( $this->store ) )->complete( self::USER, $this->callback_url() );

		$this->assertSame( 'oaiapp_x', $record['client_id'] );
		$this->assertSame( 'user_1', $this->store->get_subject() );
		$this->assertSame( 'ada@example.com', $this->store->get_email() );
		$this->assertSame( 'Ada', $this->store->get_name() );
		$this->assertSame( 'at_2', $this->store->get_access_token() );
		$this->assertSame( 'urn:uuid:0b6a2c8e-1f0d-4c55-9a3e-6f1b2d7c9e10', $this->store->get_host_id() );
		$this->assertTrue( $this->store->has_plan_scope() );
		$this->assertFalse( $this->store->needs_signin() );
		$this->assertEqualsWithDelta( time() + 3600, $this->store->get_expires_at(), 2 );
		$this->assertSame( '1', (string) get_option( SignInFlow::FIRST_NOTICE_OPTION ) );
		$this->assertFalse( get_transient( SignInFlow::pending_key( self::USER ) ) );

		$exchange = Http_Mock::requests()[0];
		$this->assertSame( 'oaiapp_x', $exchange['body']['client_id'] );
		$this->assertSame( 'ac_1', $exchange['body']['code'] );
	}

	/**
	 * No pending attempt, or an expired one, is no_pending.
	 */
	public function test_no_pending_and_expired_pending() {
		$this->assertSame( 'no_pending', $this->complete_error( 'http://127.0.0.1:1455/auth/callback?code=a&state=s' ) );

		( new SignInFlow( $this->store ) )->start( self::USER );
		$pending            = get_transient( SignInFlow::pending_key( self::USER ) );
		$pending['created'] = time() - 601;
		set_transient( SignInFlow::pending_key( self::USER ), $pending, 600 );
		$this->assertSame( 'no_pending', $this->complete_error( 'http://127.0.0.1:1455/auth/callback?code=a&state=' . $pending['state'] ) );
	}

	/**
	 * A wrong page or wrong state keeps the attempt so the admin can paste again.
	 */
	public function test_bad_paste_keeps_the_attempt() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		$authorize = 'https://auth.openai.com/api/accounts/authorize?client_id=dynamic_agent_client&state=x';

		$this->assertSame( 'bad_callback', $this->complete_error( $authorize ) );
		$this->assertSame( 'state_mismatch', $this->complete_error( $this->callback_url( array( 'state' => 'other' ) ) ) );
		$this->assertCount( 0, Http_Mock::requests() );
		$this->assertIsArray( get_transient( SignInFlow::pending_key( self::USER ) ) );

		$this->queue_exchange();
		( new SignInFlow( $this->store ) )->complete( self::USER, $this->callback_url() );
		$this->assertSame( 'oaiapp_x', $this->store->get_client_id() );
	}

	/**
	 * Every failure after the state check consumes the attempt.
	 */
	public function test_later_failures_consume_the_attempt() {
		$key   = SignInFlow::pending_key( self::USER );
		$cases = array(
			'access_denied'           => function () {
				return $this->callback_url( self::denied() );
			},
			'registration_incomplete' => function () {
				return $this->callback_url( array( 'client_id' => null ) );
			},
			'exchange_failed'         => function () {
				Http_Mock::queue( Http_Mock::json( array( 'error' => 'invalid_grant' ), 400 ) );
				return $this->callback_url();
			},
			'id_token_invalid'        => function () {
				$this->queue_exchange( array( 'nonce' => 'wrong' ) );
				return $this->callback_url();
			},
			'plan_not_enabled'        => function () {
				$this->queue_exchange( array(), array( 'scope' => 'openid' ) );
				return $this->callback_url();
			},
		);
		foreach ( $cases as $code => $build ) {
			delete_transient( 'halawa_chatgpt_jwks' );
			( new SignInFlow( $this->store ) )->start( self::USER );
			$this->assertSame( $code, $this->complete_error( $build() ) );
			$this->assertFalse( get_transient( $key ), $code . ' left the attempt pending' );
		}

		$this->store->save( OAuth_Test_Helpers::record() );
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->queue_exchange( array( 'sub' => 'user_2' ) );
		$this->assertSame( 'account_mismatch', $this->complete_error( $this->callback_url() ) );
		$this->assertFalse( get_transient( $key ) );
	}

	/**
	 * The bad_callback message is the CallbackUrl instruction.
	 */
	public function test_bad_callback_message() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		try {
			( new SignInFlow( $this->store ) )->complete( self::USER, 'http://localhost:1455/auth/callback?code=a&state=s' );
			$this->fail( 'Expected bad_callback' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'Paste the full address of the page you landed on. It starts with http://127.0.0.1', $e->getMessage() );
		}
	}

	/**
	 * An error callback is access_denied with the plan text.
	 */
	public function test_access_denied() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		try {
			( new SignInFlow( $this->store ) )->complete( self::USER, $this->callback_url( self::denied() ) );
			$this->fail( 'Expected access_denied' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'access_denied', $e->getErrorCode() );
			$this->assertStringContainsString( 'ChatGPT plan use was not allowed', $e->getMessage() );
		}
		$this->assertCount( 0, Http_Mock::requests() );
	}

	/**
	 * A first registration without an issued client id is incomplete.
	 */
	public function test_registration_incomplete() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->assertSame( 'registration_incomplete', $this->complete_error( $this->callback_url( array( 'client_id' => null ) ) ) );
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->assertSame( 'registration_incomplete', $this->complete_error( $this->callback_url( array( 'client_id' => 'dynamic_agent_client' ) ) ) );
	}

	/**
	 * On re-login a different callback client id is rejected.
	 */
	public function test_relogin_client_id_must_match() {
		$this->store->save( OAuth_Test_Helpers::record() );
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->assertSame( 'registration_incomplete', $this->complete_error( $this->callback_url( array( 'client_id' => 'oaiapp_other' ) ) ) );
	}

	/**
	 * A used or expired code gives the start-again message.
	 */
	public function test_exchange_failed() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		Http_Mock::queue( Http_Mock::json( array( 'error' => 'invalid_grant' ), 400 ) );
		try {
			( new SignInFlow( $this->store ) )->complete( self::USER, $this->callback_url() );
			$this->fail( 'Expected exchange_failed' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'exchange_failed', $e->getErrorCode() );
			$this->assertSame( 'The sign-in link was already used or expired. Start again.', $e->getMessage() );
		}
		$this->assertNull( $this->store->get() );
	}

	/**
	 * A second paste after the exchange started is no_pending.
	 */
	public function test_second_paste_after_exchange_is_no_pending() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		$url = $this->callback_url();
		Http_Mock::queue( Http_Mock::json( array( 'error' => 'server_error' ), 500 ) );
		$this->assertSame( 'exchange_failed', $this->complete_error( $url ) );
		$this->assertSame( 'no_pending', $this->complete_error( $url ) );
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * An ID token with the wrong nonce is id_token_invalid.
	 */
	public function test_id_token_invalid() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->queue_exchange( array( 'nonce' => 'wrong' ) );
		$this->assertSame( 'id_token_invalid', $this->complete_error( $this->callback_url() ) );
		$this->assertNull( $this->store->get() );
	}

	/**
	 * Without the plan scope nothing is saved.
	 */
	public function test_plan_not_enabled() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->queue_exchange( array(), array( 'scope' => 'openid profile email offline_access resource.invoke' ) );
		try {
			( new SignInFlow( $this->store ) )->complete( self::USER, $this->callback_url() );
			$this->fail( 'Expected plan_not_enabled' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'plan_not_enabled', $e->getErrorCode() );
			$this->assertSame( 'Your ChatGPT account did not grant plan usage. Plan usage needs a ChatGPT Plus, Pro or Go account. Nothing was saved.', $e->getMessage() );
		}
		$this->assertFalse( get_option( TokenStore::OPTION_NAME ) );
		$this->assertFalse( get_option( SignInFlow::FIRST_NOTICE_OPTION ) );
	}

	/**
	 * Re-login with another ChatGPT account keeps the stored record.
	 */
	public function test_account_mismatch() {
		$this->store->save( OAuth_Test_Helpers::record( array( 'needs_signin' => true ) ) );
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->queue_exchange( array( 'sub' => 'user_2' ) );
		$this->assertSame( 'account_mismatch', $this->complete_error( $this->callback_url() ) );
		$this->assertSame( 'user_1', $this->store->get_subject() );
		$this->assertTrue( $this->store->needs_signin() );
	}

	/**
	 * Re-login with the same account clears needs_signin and rotates tokens.
	 */
	public function test_relogin_success() {
		$this->store->save( OAuth_Test_Helpers::record( array( 'needs_signin' => true ) ) );
		( new SignInFlow( $this->store ) )->start( self::USER );
		$this->queue_exchange();
		( new SignInFlow( $this->store ) )->complete( self::USER, $this->callback_url() );
		$this->assertFalse( $this->store->needs_signin() );
		$this->assertSame( 'at_2', $this->store->get_access_token() );
	}
	/**
	 * An exchange error description reaches the admin unescaped and once.
	 */
	public function test_exchange_error_message_is_plain_text() {
		( new SignInFlow( $this->store ) )->start( self::USER );
		Http_Mock::queue(
			Http_Mock::json(
				array(
					'error'             => 'invalid_client',
					'error_description' => "Client isn't allowed & blocked.",
				),
				401
			)
		);
		try {
			( new SignInFlow( $this->store ) )->complete( self::USER, $this->callback_url() );
			$this->fail( 'Expected exchange_failed' );
		} catch ( OAuthException $e ) {
			$this->assertSame( "ChatGPT did not accept the sign-in: Client isn't allowed & blocked.", $e->getMessage() );
		}
	}
}
