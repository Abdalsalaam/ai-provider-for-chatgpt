<?php
/**
 * Tests for ConnectionController.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\SignInFlow;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

require_once __DIR__ . '/includes/class-oauth-test-helpers.php';

/**
 * ConnectionController tests.
 */
class Test_Connection_Controller extends Test_Case {

	const NS = '/ai-provider-for-chatgpt/v1';

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private static $admin;

	/**
	 * Subscriber user id.
	 *
	 * @var int
	 */
	private static $subscriber;

	/**
	 * Creates the users once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Boots the REST server as the admin.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		// rest_get_server() builds a fresh server and fires rest_api_init.
		$wp_rest_server = null;
		rest_get_server();
		wp_set_current_user( self::$admin );
		delete_transient( 'halawa_chatgpt_jwks' );
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 * @param array  $body   Body params.
	 * @return WP_REST_Response
	 */
	private function dispatch( $method, $route, array $body = array() ) {
		$request = new WP_REST_Request( $method, self::NS . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Exactly the five routes exist; the pairing routes are gone.
	 */
	public function test_route_list() {
		$routes = array_keys( rest_get_server()->get_routes( 'ai-provider-for-chatgpt/v1' ) );
		foreach ( array( '/connection', '/connection/sign-in', '/connection/callback', '/connection/refresh' ) as $route ) {
			$this->assertContains( self::NS . $route, $routes );
		}
		$this->assertNotContains( self::NS . '/connection/pair', $routes );
		$this->assertNotContains( self::NS . '/connection/pairing', $routes );
		$methods = rest_get_server()->get_routes()[ self::NS . '/connection' ];
		$this->assertCount( 2, $methods );
		$this->assertFalse( class_exists( 'Halawa\\ChatGptAiProvider\\Authentication\\AuthJsonImporter' ) );
		$this->assertFalse( class_exists( 'Halawa\\ChatGptAiProvider\\Authentication\\PairingTokenStore' ) );
	}

	/**
	 * A subscriber and a logged-out visitor are refused on every route.
	 */
	public function test_permissions() {
		$calls = array(
			array( 'GET', '/connection' ),
			array( 'DELETE', '/connection' ),
			array( 'POST', '/connection/sign-in' ),
			array( 'POST', '/connection/callback', array( 'callback_url' => 'http://127.0.0.1:1455/auth/callback?code=a&state=s' ) ),
			array( 'POST', '/connection/refresh' ),
		);
		foreach ( array( self::$subscriber, 0 ) as $user ) {
			wp_set_current_user( $user );
			foreach ( $calls as $call ) {
				$response = $this->dispatch( $call[0], $call[1], $call[2] ?? array() );
				$this->assertSame( $user ? 403 : 401, $response->get_status(), $call[0] . ' ' . $call[1] );
			}
		}
		$this->assertCount( 0, Http_Mock::requests() );
	}

	/**
	 * Status shape when not connected.
	 */
	public function test_status_not_connected() {
		$data = $this->dispatch( 'GET', '/connection' )->get_data();
		$this->assertSame(
			array( 'client_id', 'connected', 'email', 'expires_at', 'last_refresh', 'name', 'needs_signin', 'plan_usage_enabled', 'provider', 'redirect_uri', 'scopes', 'show_first_signin_notice', 'subject', 'usage_url' ),
			$this->sorted_keys( $data )
		);
		$this->assertFalse( $data['connected'] );
		$this->assertFalse( $data['needs_signin'] );
		$this->assertNull( $data['email'] );
		$this->assertSame( array(), $data['scopes'] );
		$this->assertFalse( $data['plan_usage_enabled'] );
		$this->assertNull( $data['expires_at'] );
		$this->assertNull( $data['last_refresh'] );
		$this->assertSame( 'http://127.0.0.1:1455/auth/callback', $data['redirect_uri'] );
		$this->assertSame( 'https://chatgpt.com/settings/usage', $data['usage_url'] );
		$this->assertSame( array( 'registered', 'configured' ), array_keys( $data['provider'] ) );
	}

	/**
	 * Returns the sorted keys of an array.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private function sorted_keys( array $data ) {
		$keys = array_keys( $data );
		sort( $keys );
		return $keys;
	}

	/**
	 * Status when connected, and when the record needs a new sign-in.
	 */
	public function test_status_connected_and_needs_signin() {
		$store  = new TokenStore();
		$record = OAuth_Test_Helpers::record();
		$store->save( $record );

		$data = $this->dispatch( 'GET', '/connection' )->get_data();
		$this->assertTrue( $data['connected'] );
		$this->assertSame( 'ada@example.com', $data['email'] );
		$this->assertSame( 'Ada', $data['name'] );
		$this->assertSame( 'user_1', $data['subject'] );
		$this->assertSame( 'oaiapp_x', $data['client_id'] );
		$this->assertTrue( $data['plan_usage_enabled'] );
		$this->assertSame( $record['expires_at'], $data['expires_at'] );
		$this->assertIsInt( $data['last_refresh'] );
		$this->assertStringNotContainsString( 'at_1', wp_json_encode( $data ) );
		$this->assertStringNotContainsString( 'rt_1', wp_json_encode( $data ) );

		$store->mark_needs_signin();
		$data = $this->dispatch( 'GET', '/connection' )->get_data();
		$this->assertFalse( $data['connected'] );
		$this->assertTrue( $data['needs_signin'] );
		$this->assertSame( 'ada@example.com', $data['email'] );
	}

	/**
	 * The first sign-in notice is true exactly once on GET.
	 */
	public function test_first_signin_notice_read_once() {
		update_option( SignInFlow::FIRST_NOTICE_OPTION, 1, false );
		$this->assertTrue( $this->dispatch( 'GET', '/connection' )->get_data()['show_first_signin_notice'] );
		$this->assertFalse( get_option( SignInFlow::FIRST_NOTICE_OPTION ) );
		$this->assertFalse( $this->dispatch( 'GET', '/connection' )->get_data()['show_first_signin_notice'] );
	}

	/**
	 * Sign-in returns the authorize URL and creates the pending attempt.
	 */
	public function test_sign_in() {
		$response = $this->dispatch( 'POST', '/connection/sign-in' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'authorize_url', 'redirect_uri', 'expires_at' ), array_keys( $data ) );
		$this->assertStringStartsWith( 'https://auth.openai.com/api/accounts/authorize?', $data['authorize_url'] );
		$this->assertIsArray( get_transient( SignInFlow::pending_key( self::$admin ) ) );
	}

	/**
	 * The callback_url parameter is validated before the flow runs.
	 */
	public function test_callback_url_validation() {
		$this->assertSame( 400, $this->dispatch( 'POST', '/connection/callback' )->get_status() );
		$this->assertSame( 'rest_invalid_param', $this->dispatch( 'POST', '/connection/callback', array( 'callback_url' => '   ' ) )->get_data()['code'] );
		$this->assertSame( 400, $this->dispatch( 'POST', '/connection/callback', array( 'callback_url' => 'http://127.0.0.1:1455/auth/callback?code=' . str_repeat( 'a', 16384 ) ) )->get_status() );
		$this->assertSame( 400, $this->dispatch( 'POST', '/connection/callback', array( 'callback_url' => array( 'x' ) ) )->get_status() );
		$this->assertSame( 'rest_invalid_param', $this->dispatch( 'POST', '/connection/callback', array( 'callback_url' => '' ) )->get_data()['code'] );
	}

	/**
	 * An address without a scheme gets the paste instruction, not a generic error.
	 */
	public function test_callback_without_scheme_gets_paste_instruction() {
		$this->dispatch( 'POST', '/connection/sign-in' );
		foreach ( array( '127.0.0.1:1455/auth/callback?code=a&state=s', 'ftp://127.0.0.1/x' ) as $pasted ) {
			$response = $this->dispatch( 'POST', '/connection/callback', array( 'callback_url' => $pasted ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'bad_callback', $response->get_data()['code'] );
			$this->assertSame( 'Paste the full address of the page you landed on. It starts with http://127.0.0.1', $response->get_data()['message'] );
		}
		$this->assertIsArray( get_transient( SignInFlow::pending_key( self::$admin ) ) );
	}

	/**
	 * Flow errors map to WP_Error codes and statuses.
	 */
	public function test_callback_error_mapping() {
		$response = $this->dispatch( 'POST', '/connection/callback', array( 'callback_url' => 'http://127.0.0.1:1455/auth/callback?code=a&state=s' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'no_pending', $response->get_data()['code'] );

		$this->dispatch( 'POST', '/connection/sign-in' );
		$response = $this->dispatch( 'POST', '/connection/callback', array( 'callback_url' => 'http://localhost:1455/auth/callback?code=a&state=s' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'bad_callback', $response->get_data()['code'] );
		$this->assertStringNotContainsString( 'localhost:1455/auth/callback?code', $response->get_data()['message'] );
	}

	/**
	 * A successful callback returns the status with the notice flag set.
	 */
	public function test_callback_success() {
		$this->dispatch( 'POST', '/connection/sign-in' );
		$pending = get_transient( SignInFlow::pending_key( self::$admin ) );
		Http_Mock::queue(
			Http_Mock::json(
				OAuth_Test_Helpers::token_response(
					array(
						'id_token' => Jwks_Fixture::sign(
							array(
								'iss'   => 'https://auth.openai.com',
								'sub'   => 'user_1',
								'aud'   => array( 'oaiapp_x' ),
								'nonce' => $pending['nonce'],
								'email' => 'ada@example.com',
								'exp'   => time() + 3600,
							)
						),
					)
				)
			)
		);
		Http_Mock::queue( Http_Mock::json( json_decode( Jwks_Fixture::jwks(), true ) ) );

		$response = $this->dispatch( 'POST', '/connection/callback', array( 'callback_url' => 'http://127.0.0.1:1455/auth/callback?code=ac_1&state=' . rawurlencode( $pending['state'] ) . '&client_id=oaiapp_x' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['connected'] );
		$this->assertTrue( $response->get_data()['show_first_signin_notice'] );
		$this->assertSame( '1', (string) get_option( SignInFlow::FIRST_NOTICE_OPTION ) );
	}

	/**
	 * Refresh returns the status and refreshed_at; failures map to WP_Error.
	 */
	public function test_refresh_route() {
		$response = $this->dispatch( 'POST', '/connection/refresh' );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'not_connected', $response->get_data()['code'] );

		( new TokenStore() )->save( OAuth_Test_Helpers::record() );
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response() ) );
		$response = $this->dispatch( 'POST', '/connection/refresh' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsInt( $response->get_data()['refreshed_at'] );
		$this->assertSame( 'at_2', ( new TokenStore() )->get_access_token() );

		Http_Mock::queue( Http_Mock::json( array( 'error' => 'invalid_grant' ), 400 ) );
		$response = $this->dispatch( 'POST', '/connection/refresh' );
		$this->assertSame( 'needs_signin', $response->get_data()['code'] );
	}

	/**
	 * A refresh that returns the same refresh token still answers 200.
	 */
	public function test_refresh_route_with_unrotated_refresh_token() {
		( new TokenStore() )->save( OAuth_Test_Helpers::record() );
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response( array( 'refresh_token' => 'rt_1' ) ) ) );

		$response = $this->dispatch( 'POST', '/connection/refresh' );

		$this->assertSame( 200, $response->get_status() );
		$token_calls = array_filter(
			array_column( Http_Mock::requests(), 'url' ),
			static function ( $url ) {
				return 'https://auth.openai.com/api/accounts/oauth/token' === $url;
			}
		);
		$this->assertCount( 1, $token_calls );
		$this->assertSame( 'at_2', ( new TokenStore() )->get_access_token() );
		$this->assertSame( 'rt_1', ( new TokenStore() )->get_refresh_token() );
	}

	/**
	 * Disconnect revokes, then clears everything the connection created.
	 */
	public function test_disconnect_revokes_then_clears() {
		update_option( 'halawa_chatgpt_installation_id', '0b6a2c8e-1f0d-4c55-9a3e-6f1b2d7c9e10', false );
		( new TokenStore() )->save( OAuth_Test_Helpers::record() );
		update_option( SignInFlow::FIRST_NOTICE_OPTION, 1, false );
		set_transient( SignInFlow::pending_key( self::$admin ), array( 'state' => 's' ), 600 );
		Http_Mock::queue( Http_Mock::json( array() ) );

		$data = $this->dispatch( 'DELETE', '/connection' )->get_data();

		$this->assertTrue( $data['revoked'] );
		$this->assertFalse( $data['connected'] );
		$this->assertSame( 'https://auth.openai.com/api/accounts/oauth/revoke', Http_Mock::requests()[0]['url'] );
		$this->assertSame( 'rt_1', Http_Mock::requests()[0]['body']['token'] );
		$this->assertFalse( get_option( TokenStore::OPTION_NAME ) );
		$this->assertSame( 'absent', get_option( TokenStore::CORE_API_KEY_OPTION, 'absent' ) );
		$this->assertFalse( get_option( SignInFlow::FIRST_NOTICE_OPTION ) );
		$this->assertFalse( get_transient( SignInFlow::pending_key( self::$admin ) ) );
		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
		$this->assertSame( '0b6a2c8e-1f0d-4c55-9a3e-6f1b2d7c9e10', get_option( 'halawa_chatgpt_installation_id' ) );
	}

	/**
	 * A failed revoke still disconnects and reports revoked false.
	 */
	public function test_disconnect_when_revoke_fails() {
		( new TokenStore() )->save( OAuth_Test_Helpers::record() );
		Http_Mock::queue( new WP_Error( 'http_request_failed', 'timeout' ) );
		$data = $this->dispatch( 'DELETE', '/connection' )->get_data();
		$this->assertFalse( $data['revoked'] );
		$this->assertFalse( get_option( TokenStore::OPTION_NAME ) );
	}

	/**
	 * Disconnect without a record makes no HTTP call.
	 */
	public function test_disconnect_without_record() {
		$data = $this->dispatch( 'DELETE', '/connection' )->get_data();
		$this->assertFalse( $data['revoked'] );
		$this->assertCount( 0, Http_Mock::requests() );
	}
	/**
	 * A busy refresh lock gives 409 refresh_in_progress and no HTTP call.
	 */
	public function test_refresh_route_reports_busy_lock() {
		global $wpdb;
		( new TokenStore() )->save( OAuth_Test_Helpers::record() );
		$wpdb->query( $wpdb->prepare( "INSERT INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'off')", TokenRefresher::LOCK_OPTION, (string) time() ) );

		$response = $this->dispatch( 'POST', '/connection/refresh' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'refresh_in_progress', $response->get_data()['code'] );
		$this->assertCount( 0, Http_Mock::requests() );
		$wpdb->delete( $wpdb->options, array( 'option_name' => TokenRefresher::LOCK_OPTION ) );
	}
}
