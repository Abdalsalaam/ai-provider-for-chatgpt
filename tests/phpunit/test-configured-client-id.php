<?php
/**
 * Tests for the CHATGPT_OAUTH_CLIENT_ID constant, one process per test.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\SignInFlow;

use function Halawa\ChatGptAiProvider\configured_client_id;

/**
 * Configured client id tests.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Configured_Client_Id extends Test_Case {

	/**
	 * A non-empty constant is returned.
	 */
	public function test_constant_is_used() {
		define( 'CHATGPT_OAUTH_CLIENT_ID', 'oaiapp_configured' );
		$this->assertSame( 'oaiapp_configured', configured_client_id() );
	}

	/**
	 * An empty constant counts as not configured.
	 */
	public function test_empty_constant_is_ignored() {
		define( 'CHATGPT_OAUTH_CLIENT_ID', '' );
		$this->assertNull( configured_client_id() );
	}

	/**
	 * A configured client id is used without the agent name hint.
	 */
	public function test_sign_in_with_configured_client_id() {
		define( 'CHATGPT_OAUTH_CLIENT_ID', 'oaiapp_configured' );
		$start = ( new SignInFlow( new TokenStore() ) )->start( 3 );
		parse_str( (string) wp_parse_url( $start['authorize_url'], PHP_URL_QUERY ), $params );
		$this->assertSame( 'oaiapp_configured', $params['client_id'] );
		$this->assertArrayNotHasKey( 'agent_name_hint', $params );
		$this->assertArrayNotHasKey( 'id_token_hint', $params );
		$this->assertSame( 'oaiapp_configured', get_transient( SignInFlow::pending_key( 3 ) )['client_id'] );
	}
}
