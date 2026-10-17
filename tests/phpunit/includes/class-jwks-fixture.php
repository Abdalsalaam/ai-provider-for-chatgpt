<?php
/**
 * RSA key pair and JWT signer for ID token tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

/**
 * Generates one RSA key pair per process and signs RS256 JWTs with it.
 */
final class Jwks_Fixture {

	public const KID = 'test-key-1';

	/**
	 * Private key, created on first use.
	 *
	 * @var resource|OpenSSLAsymmetricKey|null
	 */
	private static $key = null;

	/**
	 * Returns the JWKS document for the fixture key.
	 *
	 * @return string
	 */
	public static function jwks(): string {
		$details = openssl_pkey_get_details( self::key() );
		return (string) wp_json_encode(
			array(
				'keys' => array(
					array(
						'kty' => 'RSA',
						'kid' => self::KID,
						'use' => 'sig',
						'alg' => 'RS256',
						'n'   => self::b64url( $details['rsa']['n'] ),
						'e'   => self::b64url( $details['rsa']['e'] ),
					),
				),
			)
		);
	}

	/**
	 * Signs claims as an RS256 JWT.
	 *
	 * @param array $claims Payload claims.
	 * @param array $header Header fields that replace the defaults.
	 * @return string
	 */
	public static function sign( array $claims, array $header = array() ): string {
		$header    = array_merge(
			array(
				'alg' => 'RS256',
				'typ' => 'JWT',
				'kid' => self::KID,
			),
			$header
		);
		$signing   = self::b64url( (string) wp_json_encode( $header ) ) . '.' . self::b64url( (string) wp_json_encode( $claims ) );
		$signature = '';
		openssl_sign( $signing, $signature, self::key(), OPENSSL_ALGO_SHA256 );
		return $signing . '.' . self::b64url( $signature );
	}

	/**
	 * Returns the public key as PEM.
	 *
	 * @return string
	 */
	public static function public_pem(): string {
		return openssl_pkey_get_details( self::key() )['key'];
	}

	/**
	 * Creates the key pair once per process.
	 *
	 * @return resource|OpenSSLAsymmetricKey
	 */
	private static function key() {
		if ( null === self::$key ) {
			self::$key = openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
				)
			);
		}
		return self::$key;
	}

	/**
	 * Encodes base64url without padding.
	 *
	 * @param string $value Raw bytes.
	 * @return string
	 */
	private static function b64url( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding.
	}
}
