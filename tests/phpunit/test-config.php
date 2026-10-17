<?php
/**
 * Tests for the configuration functions in the main plugin file.
 *
 * @package Halawa\ChatGptAiProvider
 */

use function Halawa\ChatGptAiProvider\configured_client_id;
use function Halawa\ChatGptAiProvider\host_id;
use function Halawa\ChatGptAiProvider\redirect_uri;
use function Halawa\ChatGptAiProvider\user_agent;

/**
 * Config function tests.
 */
class Test_Config extends Test_Case {

	/**
	 * The default redirect URI is the loopback address, never localhost.
	 */
	public function test_redirect_uri_default() {
		$this->assertSame( 'http://127.0.0.1:1455/auth/callback', redirect_uri() );
	}

	/**
	 * The redirect URI filter replaces the default with a non-empty string.
	 */
	public function test_redirect_uri_filter() {
		add_filter(
			'halawa_chatgpt_redirect_uri',
			static function () {
				return 'https://example.test/callback';
			}
		);
		$this->assertSame( 'https://example.test/callback', redirect_uri() );
	}

	/**
	 * A filter returning an empty or non-string value falls back to the default.
	 */
	public function test_redirect_uri_filter_bad_value_falls_back() {
		add_filter( 'halawa_chatgpt_redirect_uri', '__return_empty_string' );
		$this->assertSame( 'http://127.0.0.1:1455/auth/callback', redirect_uri() );
		remove_all_filters( 'halawa_chatgpt_redirect_uri' );
		add_filter( 'halawa_chatgpt_redirect_uri', '__return_false' );
		$this->assertSame( 'http://127.0.0.1:1455/auth/callback', redirect_uri() );
	}

	/**
	 * Without the constant there is no configured client id.
	 */
	public function test_configured_client_id_absent() {
		$this->assertFalse( defined( 'CHATGPT_OAUTH_CLIENT_ID' ) );
		$this->assertNull( configured_client_id() );
	}

	/**
	 * The host id is created once and stays the same.
	 */
	public function test_host_id_is_created_once_and_stable() {
		delete_option( 'halawa_chatgpt_installation_id' );
		$first = host_id();
		$this->assertMatchesRegularExpression( '/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first );
		$this->assertSame( $first, host_id() );
		$this->assertSame( 'urn:uuid:' . get_option( 'halawa_chatgpt_installation_id' ), $first );
	}

	/**
	 * An existing installation id from 0.1.x is reused.
	 */
	public function test_host_id_reuses_existing_installation_id() {
		update_option( 'halawa_chatgpt_installation_id', '0b6a2c8e-1f0d-4c55-9a3e-6f1b2d7c9e10', false );
		$this->assertSame( 'urn:uuid:0b6a2c8e-1f0d-4c55-9a3e-6f1b2d7c9e10', host_id() );
	}

	/**
	 * The user agent names the plugin and WordPress, nothing from Codex.
	 */
	public function test_user_agent() {
		$this->assertSame( 'ai-provider-for-chatgpt/0.2.0 (WordPress/' . get_bloginfo( 'version' ) . ')', user_agent() );
		$this->assertStringNotContainsStringIgnoringCase( 'codex', user_agent() );
	}

	/**
	 * The scope constant is the exact documented set and the Codex client id is gone.
	 */
	public function test_scopes_constant() {
		$this->assertSame( 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct', \Halawa\ChatGptAiProvider\SCOPES );
		$this->assertFalse( function_exists( 'Halawa\\ChatGptAiProvider\\oauth_client_id' ) );
		$this->assertFalse( defined( 'Halawa\\ChatGptAiProvider\\DEFAULT_OAUTH_CLIENT_ID' ) );
	}
}
