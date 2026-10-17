<?php
/**
 * Tests for CallbackUrl.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\OAuth\CallbackUrl;

/**
 * CallbackUrl tests.
 */
class Test_Callback_Url extends Test_Case {

	const REDIRECT = 'http://127.0.0.1:1455/auth/callback';

	/**
	 * A real callback parses into its parts.
	 */
	public function test_parses_full_callback() {
		$parsed = CallbackUrl::parse(
			'  http://127.0.0.1:1455/auth/callback?code=ac_123&scope=openid%20profile+email&state=st_1&client_id=oaiapp_abc ',
			self::REDIRECT
		);
		$this->assertSame(
			array(
				'code'      => 'ac_123',
				'state'     => 'st_1',
				'client_id' => 'oaiapp_abc',
				'scope'     => 'openid profile email',
				'error'     => null,
			),
			$parsed
		);
	}

	/**
	 * An error callback is returned, not thrown, and needs no code.
	 */
	public function test_error_callback_is_returned() {
		$parsed = CallbackUrl::parse( self::REDIRECT . '?error=access_denied&state=st_1', self::REDIRECT );
		$this->assertSame( 'access_denied', $parsed['error'] );
		$this->assertSame( '', $parsed['code'] );
		$this->assertNull( $parsed['client_id'] );
	}

	/**
	 * Addresses of the wrong page.
	 *
	 * @return array<string, array{string}>
	 */
	public function wrong_page_provider() {
		return array(
			'authorize url'  => array( 'https://auth.openai.com/api/accounts/authorize?client_id=dynamic_agent_client&state=st_1&code_challenge=x' ),
			'chatgpt.com'    => array( 'https://chatgpt.com/auth/callback?code=ac_123&state=st_1' ),
			'localhost'      => array( 'http://localhost:1455/auth/callback?code=ac_123&state=st_1' ),
			'https scheme'   => array( 'https://127.0.0.1:1455/auth/callback?code=ac_123&state=st_1' ),
			'other port'     => array( 'http://127.0.0.1:1456/auth/callback?code=ac_123&state=st_1' ),
			'no port'        => array( 'http://127.0.0.1/auth/callback?code=ac_123&state=st_1' ),
			'trailing slash' => array( 'http://127.0.0.1:1455/auth/callback/?code=ac_123&state=st_1' ),
			'userinfo'       => array( 'http://user:pw@127.0.0.1:1455/auth/callback?code=ac_123&state=st_1' ),
			'fragment'       => array( 'http://127.0.0.1:1455/auth/callback?code=ac_123&state=st_1#x' ),
			'empty fragment' => array( 'http://127.0.0.1:1455/auth/callback?code=ac_123&state=st_1#' ),
			'relative'       => array( '/auth/callback?code=ac_123&state=st_1' ),
			'query only'     => array( 'code=ac_123&state=st_1' ),
			'empty'          => array( '' ),
			'too long'       => array( self::REDIRECT . '?state=st_1&code=' . str_repeat( 'a', 16384 ) ),
		);
	}

	/**
	 * Every wrong page gets the same instruction.
	 *
	 * @dataProvider wrong_page_provider
	 *
	 * @param string $url Pasted address.
	 */
	public function test_rejects_wrong_page( $url ) {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Paste the full address of the page you landed on. It starts with http://127.0.0.1' );
		CallbackUrl::parse( $url, self::REDIRECT );
	}

	/**
	 * Repeated query keys are rejected, including encoded duplicates.
	 */
	public function test_rejects_repeated_parameters() {
		foreach ( array( '?code=a&code=b&state=s', '?state=s&code=a&%73tate=t' ) as $query ) {
			try {
				CallbackUrl::parse( self::REDIRECT . $query, self::REDIRECT );
				$this->fail( 'Expected rejection for ' . $query );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'The address has repeated parameters.', $e->getMessage() );
			}
		}
	}

	/**
	 * A missing or empty state is rejected.
	 */
	public function test_rejects_missing_state() {
		foreach ( array( '?code=a', '?code=a&state=' ) as $query ) {
			try {
				CallbackUrl::parse( self::REDIRECT . $query, self::REDIRECT );
				$this->fail( 'Expected rejection for ' . $query );
			} catch ( InvalidArgumentException $e ) {
				$this->assertSame( 'The address is missing the state value.', $e->getMessage() );
			}
		}
	}

	/**
	 * A callback with neither code nor error is rejected.
	 */
	public function test_rejects_missing_code() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The address is missing the authorization code.' );
		CallbackUrl::parse( self::REDIRECT . '?state=s&code=', self::REDIRECT );
	}

	/**
	 * Host and scheme compare case-insensitively, the path does not.
	 */
	public function test_host_case_insensitive_path_exact() {
		$this->assertSame( 'a', CallbackUrl::parse( 'HTTP://127.0.0.1:1455/auth/callback?code=a&state=s', self::REDIRECT )['code'] );
		$this->expectException( InvalidArgumentException::class );
		CallbackUrl::parse( 'http://127.0.0.1:1455/Auth/Callback?code=a&state=s', self::REDIRECT );
	}

	/**
	 * A filtered https redirect URI is matched with its default port.
	 */
	public function test_https_redirect_default_port() {
		$parsed = CallbackUrl::parse( 'https://example.test/cb?code=a&state=s', 'https://example.test:443/cb' );
		$this->assertSame( 'a', $parsed['code'] );
	}
}
