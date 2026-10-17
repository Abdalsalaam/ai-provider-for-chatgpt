<?php
/**
 * Tests for JwksVerifier.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\OAuth\JwksVerifier;
use Halawa\ChatGptAiProvider\OAuth\OAuthException;

/**
 * JwksVerifier tests.
 */
class Test_Jwks_Verifier extends Test_Case {

	/**
	 * Claims of a valid ID token for client oaiapp_x and nonce n_1.
	 *
	 * @param array $overrides Claim overrides.
	 * @return array
	 */
	private function claims( array $overrides = array() ) {
		return array_merge(
			array(
				'iss'   => 'https://auth.openai.com',
				'sub'   => 'user_1',
				'aud'   => array( 'oaiapp_x' ),
				'nonce' => 'n_1',
				'email' => 'ada@example.com',
				'exp'   => time() + 3600,
				'iat'   => time(),
			),
			$overrides
		);
	}

	/**
	 * Queues the fixture JWKS once.
	 */
	private function queue_jwks() {
		Http_Mock::queue( Http_Mock::json( json_decode( Jwks_Fixture::jwks(), true ) ) );
	}

	/**
	 * Asserts verify() fails with the given code.
	 *
	 * @param string $code  Expected error code.
	 * @param string $token Token to verify.
	 */
	private function assert_rejected( $code, $token ) {
		try {
			( new JwksVerifier() )->verify( $token, 'oaiapp_x', 'n_1' );
			$this->fail( 'Expected ' . $code );
		} catch ( OAuthException $e ) {
			$this->assertSame( $code, $e->getErrorCode() );
		}
	}

	/**
	 * A valid token with an array aud verifies and returns its claims.
	 */
	public function test_valid_token_with_array_aud() {
		$this->queue_jwks();
		$claims = ( new JwksVerifier() )->verify( Jwks_Fixture::sign( $this->claims() ), 'oaiapp_x', 'n_1' );
		$this->assertSame( 'user_1', $claims['sub'] );
		$this->assertSame( 'https://auth.openai.com/.well-known/jwks.json', Http_Mock::requests()[0]['url'] );
	}

	/**
	 * A string aud verifies too.
	 */
	public function test_valid_token_with_string_aud() {
		$this->queue_jwks();
		$claims = ( new JwksVerifier() )->verify( Jwks_Fixture::sign( $this->claims( array( 'aud' => 'oaiapp_x' ) ) ), 'oaiapp_x', 'n_1' );
		$this->assertSame( 'oaiapp_x', $claims['aud'] );
	}

	/**
	 * An aud for another client fails, as array and as string.
	 */
	public function test_wrong_audience() {
		$this->queue_jwks();
		$this->assert_rejected( 'id_token_bad_audience', Jwks_Fixture::sign( $this->claims( array( 'aud' => array( 'oaiapp_other' ) ) ) ) );
		$this->assert_rejected( 'id_token_bad_audience', Jwks_Fixture::sign( $this->claims( array( 'aud' => 'oaiapp_other' ) ) ) );
		$this->assert_rejected( 'id_token_bad_audience', Jwks_Fixture::sign( $this->claims( array( 'aud' => null ) ) ) );
	}

	/**
	 * The JWKS is cached after the first fetch.
	 */
	public function test_jwks_is_cached() {
		$this->queue_jwks();
		( new JwksVerifier() )->verify( Jwks_Fixture::sign( $this->claims() ), 'oaiapp_x', 'n_1' );
		( new JwksVerifier() )->verify( Jwks_Fixture::sign( $this->claims() ), 'oaiapp_x', 'n_1' );
		$this->assertCount( 1, Http_Mock::requests() );
		$this->assertIsArray( get_transient( JwksVerifier::CACHE_KEY ) );
	}

	/**
	 * Wrong issuer, expiry beyond leeway and wrong nonce each fail.
	 */
	public function test_claim_checks() {
		$this->queue_jwks();
		$this->assert_rejected( 'id_token_bad_issuer', Jwks_Fixture::sign( $this->claims( array( 'iss' => 'https://auth.openai.com/' ) ) ) );
		$this->assert_rejected( 'id_token_expired', Jwks_Fixture::sign( $this->claims( array( 'exp' => time() - 61 ) ) ) );
		$this->assert_rejected( 'id_token_expired', Jwks_Fixture::sign( $this->claims( array( 'exp' => 'soon' ) ) ) );
		$this->assert_rejected( 'id_token_bad_nonce', Jwks_Fixture::sign( $this->claims( array( 'nonce' => 'n_2' ) ) ) );
		$this->assert_rejected( 'id_token_bad_nonce', Jwks_Fixture::sign( $this->claims( array( 'nonce' => null ) ) ) );
	}

	/**
	 * An expiry inside the leeway still verifies.
	 */
	public function test_expiry_within_leeway() {
		$this->queue_jwks();
		$claims = ( new JwksVerifier() )->verify( Jwks_Fixture::sign( $this->claims( array( 'exp' => time() - 30 ) ) ), 'oaiapp_x', 'n_1' );
		$this->assertSame( 'user_1', $claims['sub'] );
	}

	/**
	 * A tampered payload fails the signature check.
	 */
	public function test_bad_signature() {
		$this->queue_jwks();
		$parts = explode( '.', Jwks_Fixture::sign( $this->claims() ) );
		$other = explode( '.', Jwks_Fixture::sign( $this->claims( array( 'sub' => 'user_2' ) ) ) );
		$this->assert_rejected( 'id_token_bad_signature', $parts[0] . '.' . $other[1] . '.' . $parts[2] );
	}

	/**
	 * A non-RS256 header or a broken token is malformed.
	 */
	public function test_malformed_tokens() {
		$this->assert_rejected( 'id_token_malformed', Jwks_Fixture::sign( $this->claims(), array( 'alg' => 'HS256' ) ) );
		$this->assert_rejected( 'id_token_malformed', Jwks_Fixture::sign( $this->claims(), array( 'alg' => 'none' ) ) );
		$this->assert_rejected( 'id_token_malformed', 'not-a-jwt' );
		$this->assert_rejected( 'id_token_malformed', 'a.b.c' );
		$this->assertCount( 0, Http_Mock::requests() );
	}

	/**
	 * A kid missing from the cache triggers one refetch that finds it.
	 */
	public function test_unknown_kid_refetches_once() {
		$old_key = array(
			'kty' => 'RSA',
			'kid' => 'old-key',
			'n'   => 'AQAB',
			'e'   => 'AQAB',
		);
		set_transient( JwksVerifier::CACHE_KEY, array( $old_key ), DAY_IN_SECONDS );
		$this->queue_jwks();
		$claims = ( new JwksVerifier() )->verify( Jwks_Fixture::sign( $this->claims() ), 'oaiapp_x', 'n_1' );
		$this->assertSame( 'user_1', $claims['sub'] );
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * A kid missing after the refetch fails closed without a third fetch.
	 */
	public function test_unknown_kid_after_refetch() {
		set_transient( JwksVerifier::CACHE_KEY, json_decode( Jwks_Fixture::jwks(), true )['keys'], DAY_IN_SECONDS );
		$this->queue_jwks();
		$this->assert_rejected( 'id_token_unknown_kid', Jwks_Fixture::sign( $this->claims(), array( 'kid' => 'rotated-key' ) ) );
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * A kid missing from a fresh fetch fails without a second fetch.
	 */
	public function test_unknown_kid_on_fresh_fetch_does_not_refetch() {
		$this->queue_jwks();
		$this->assert_rejected( 'id_token_unknown_kid', Jwks_Fixture::sign( $this->claims(), array( 'kid' => 'rotated-key' ) ) );
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * A failing JWKS endpoint fails closed.
	 */
	public function test_jwks_unavailable() {
		Http_Mock::queue( Http_Mock::json( array( 'error' => 'down' ), 503 ) );
		$this->assert_rejected( 'jwks_unavailable', Jwks_Fixture::sign( $this->claims() ) );
		Http_Mock::queue( Http_Mock::json( array( 'keys' => 'nope' ) ) );
		$this->assert_rejected( 'jwks_unavailable', Jwks_Fixture::sign( $this->claims() ) );
		$this->assertFalse( get_transient( JwksVerifier::CACHE_KEY ) );
	}

	/**
	 * The DER builder turns the fixture JWK into the fixture public key PEM.
	 */
	public function test_jwk_to_pem_matches_fixture() {
		$jwk    = json_decode( Jwks_Fixture::jwks(), true )['keys'][0];
		$method = new ReflectionMethod( JwksVerifier::class, 'jwk_to_pem' );
		$method->setAccessible( true );
		$pem = $method->invoke( new JwksVerifier(), $jwk['n'], $jwk['e'] );
		$this->assertSame( trim( Jwks_Fixture::public_pem() ), trim( $pem ) );
	}

	/**
	 * The OAuthException keeps its code, status and request id.
	 */
	public function test_oauth_exception_accessors() {
		$e = new OAuthException( 'invalid_grant', 'Expired.', 400, 'req_1' );
		$this->assertSame( 'invalid_grant', $e->getErrorCode() );
		$this->assertSame( 'Expired.', $e->getMessage() );
		$this->assertSame( 400, $e->getHttpStatus() );
		$this->assertSame( 'req_1', $e->getRequestId() );
	}
}
