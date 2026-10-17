<?php
/**
 * Tests for Pkce.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\OAuth\Pkce;

/**
 * Pkce tests.
 */
class Test_Pkce extends Test_Case {

	/**
	 * Random values are base64url without padding and have the expected length.
	 */
	public function test_random_lengths_and_alphabet() {
		$this->assertSame( 43, strlen( Pkce::random() ) );
		$this->assertSame( 64, strlen( Pkce::random( 48 ) ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', Pkce::random( 48 ) );
		$this->assertNotSame( Pkce::random(), Pkce::random() );
	}

	/**
	 * The S256 challenge matches the RFC 7636 appendix B vector.
	 */
	public function test_challenge_rfc_vector() {
		$this->assertSame(
			'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
			Pkce::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' )
		);
	}
}
