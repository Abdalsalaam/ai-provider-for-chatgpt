<?php
/**
 * Tests for TokenEndpoint.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\OAuth\OAuthException;
use Halawa\ChatGptAiProvider\OAuth\TokenEndpoint;

/**
 * TokenEndpoint tests.
 */
class Test_Token_Endpoint extends Test_Case {

	/**
	 * A successful token response body.
	 *
	 * @return array
	 */
	private function tokens() {
		return array(
			'access_token'        => 'at_1',
			'refresh_token'       => 'rt_1',
			'id_token'            => 'it_1',
			'token_type'          => 'Bearer',
			'expires_in'          => 3600,
			'scope'               => 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct',
			'earliest_refresh_at' => 1792269913,
		);
	}

	/**
	 * Exchange posts the form fields and returns the typed response.
	 */
	public function test_exchange_form_body_and_result() {
		Http_Mock::queue( Http_Mock::json( $this->tokens() ) );
		$result = ( new TokenEndpoint() )->exchange( 'oaiapp_x', 'ac_1', 'ver_1', 'http://127.0.0.1:1455/auth/callback' );

		$request = Http_Mock::requests()[0];
		$this->assertSame( 'https://auth.openai.com/api/accounts/oauth/token', $request['url'] );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => 'oaiapp_x',
				'code'          => 'ac_1',
				'code_verifier' => 'ver_1',
				'redirect_uri'  => 'http://127.0.0.1:1455/auth/callback',
				'resource'      => 'https://api.openai.com/v1',
			),
			$request['body']
		);
		$this->assertSame( 'application/x-www-form-urlencoded', $request['headers']['Content-Type'] );
		$this->assertSame( 'application/json', $request['headers']['Accept'] );
		$this->assertStringStartsWith( 'ai-provider-for-chatgpt/', $request['headers']['User-Agent'] );
		$this->assertSame( 20, $request['args']['timeout'] );
		$this->assertSame( $this->tokens(), $result );
	}

	/**
	 * Refresh sends the grant, client id, token and resource, and no scope.
	 */
	public function test_refresh_form_body() {
		Http_Mock::queue( Http_Mock::json( $this->tokens() ) );
		( new TokenEndpoint() )->refresh( 'oaiapp_x', 'rt_0' );
		$this->assertSame(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => 'oaiapp_x',
				'refresh_token' => 'rt_0',
				'resource'      => 'https://api.openai.com/v1',
			),
			Http_Mock::requests()[0]['body']
		);
	}

	/**
	 * Optional fields default when absent.
	 */
	public function test_optional_fields_default() {
		$body = $this->tokens();
		unset( $body['earliest_refresh_at'], $body['scope'], $body['token_type'], $body['expires_in'] );
		Http_Mock::queue( Http_Mock::json( $body ) );
		$result = ( new TokenEndpoint() )->refresh( 'oaiapp_x', 'rt_0' );
		$this->assertNull( $result['earliest_refresh_at'] );
		$this->assertSame( '', $result['scope'] );
		$this->assertSame( 'Bearer', $result['token_type'] );
		$this->assertSame( 0, $result['expires_in'] );
	}

	/**
	 * Revoke posts the refresh token and reports HTTP 200 as success.
	 */
	public function test_revoke() {
		Http_Mock::queue( Http_Mock::json( array() ) );
		$this->assertTrue( ( new TokenEndpoint() )->revoke( 'oaiapp_x', 'rt_1' ) );
		$request = Http_Mock::requests()[0];
		$this->assertSame( 'https://auth.openai.com/api/accounts/oauth/revoke', $request['url'] );
		$this->assertSame(
			array(
				'token'           => 'rt_1',
				'token_type_hint' => 'refresh_token',
				'client_id'       => 'oaiapp_x',
			),
			$request['body']
		);

		Http_Mock::queue( Http_Mock::json( array( 'error' => 'invalid_request' ), 400 ) );
		$this->assertFalse( ( new TokenEndpoint() )->revoke( 'oaiapp_x', 'rt_1' ) );
		Http_Mock::queue( new WP_Error( 'http_request_failed', 'timeout' ) );
		$this->assertFalse( ( new TokenEndpoint() )->revoke( 'oaiapp_x', 'rt_1' ) );
	}

	/**
	 * Runs a refresh against a queued response and returns the exception.
	 *
	 * @param array|WP_Error $response Queued response.
	 * @return OAuthException
	 */
	private function failure( $response ) {
		Http_Mock::queue( $response );
		try {
			( new TokenEndpoint() )->refresh( 'oaiapp_x', 'rt_0' );
		} catch ( OAuthException $e ) {
			return $e;
		}
		$this->fail( 'Expected OAuthException' );
	}

	/**
	 * The OAuth error value becomes the code, the description the message.
	 */
	public function test_error_code_extraction() {
		$e = $this->failure(
			Http_Mock::json(
				array(
					'error'             => 'refresh_token_reused',
					'error_description' => 'Refresh token was already used.',
				),
				400,
				array( 'x-oai-request-id' => 'req_9' )
			)
		);
		$this->assertSame( 'refresh_token_reused', $e->getErrorCode() );
		$this->assertSame( 'Refresh token was already used.', $e->getMessage() );
		$this->assertSame( 400, $e->getHttpStatus() );
		$this->assertSame( 'req_9', $e->getRequestId() );
	}

	/**
	 * A detail body has no error value, so the code is the status.
	 */
	public function test_detail_body_gives_http_code() {
		$e = $this->failure( Http_Mock::json( array( 'detail' => 'Bad request.' ), 400 ) );
		$this->assertSame( 'http_400', $e->getErrorCode() );
		$this->assertSame( 'Bad request.', $e->getMessage() );
	}

	/**
	 * A nested error object uses its code field.
	 */
	public function test_nested_error_object() {
		$error = array(
			'code'    => 'missing_required_parameter',
			'message' => 'Missing resource.',
		);
		$e     = $this->failure( Http_Mock::json( array( 'error' => $error ), 400 ) );
		$this->assertSame( 'missing_required_parameter', $e->getErrorCode() );
		$this->assertSame( 'Missing resource.', $e->getMessage() );
	}

	/**
	 * Non-JSON bodies, network errors and incomplete success bodies.
	 */
	public function test_other_failures() {
		$e = $this->failure(
			array(
				'response' => array( 'code' => 502 ),
				'headers'  => array(),
				'body'     => str_repeat( 'x', 400 ),
			)
		);
		$this->assertSame( 'http_502', $e->getErrorCode() );
		$this->assertSame( 'ChatGPT sign-in returned HTTP 502.', $e->getMessage() );
		$this->assertSame( 502, $e->getHttpStatus() );

		$network = $this->failure( new WP_Error( 'http_request_failed', 'cURL error 28' ) );
		$this->assertSame( 'network_error', $network->getErrorCode() );
		$this->assertSame( 0, $network->getHttpStatus() );

		$this->assertSame(
			'token_response_incomplete',
			$this->failure(
				Http_Mock::json(
					array(
						'access_token'  => 'at_1',
						'refresh_token' => 'rt_1',
					)
				)
			)->getErrorCode()
		);
		$this->assertSame(
			'http_200',
			$this->failure(
				array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => 'not json',
				)
			)->getErrorCode()
		);
	}

	/**
	 * A truncated success body never reaches the exception message.
	 */
	public function test_truncated_success_body_is_not_echoed() {
		$e = $this->failure(
			array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '{"access_token":"eyJhbGciOiJSUzI1NiJ9.secret-part',
			)
		);
		$this->assertSame( 'http_200', $e->getErrorCode() );
		$this->assertSame( 'ChatGPT returned a response that could not be read.', $e->getMessage() );
		$this->assertStringNotContainsString( 'eyJ', $e->getMessage() );
	}

	/**
	 * A non-JSON error body is not echoed either.
	 */
	public function test_error_body_without_fields_is_not_echoed() {
		$e = $this->failure(
			array(
				'response' => array( 'code' => 400 ),
				'headers'  => array(),
				'body'     => '<html>refresh_token=rt_secret</html>',
			)
		);
		$this->assertSame( 'http_400', $e->getErrorCode() );
		$this->assertStringNotContainsString( 'rt_secret', $e->getMessage() );
	}
}
