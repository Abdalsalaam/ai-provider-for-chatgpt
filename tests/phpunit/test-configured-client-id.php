<?php
/**
 * Tests for the CHATGPT_OAUTH_CLIENT_ID constant, one process per test.
 *
 * @package Halawa\ChatGptAiProvider
 */

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
}
