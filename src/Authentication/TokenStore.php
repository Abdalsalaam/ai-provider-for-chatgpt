<?php
/**
 * TokenStore class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Authentication;

use WordPress\AiClient\Common\Exception\RuntimeException;

/**
 * Persists OAuth tokens in a single WordPress option, encrypted at rest.
 *
 * Encryption uses libsodium with a 32-byte key derived from WordPress's
 * `AUTH_KEY` + `LOGGED_IN_KEY` salts, so a SQL dump alone is insufficient
 * to recover the access/refresh tokens.
 *
 * Schema (after decryption):
 *
 * ```php
 * [
 *     'tokens' => [
 *         'id_token'      => string,
 *         'access_token'  => string,
 *         'refresh_token' => string,
 *         'account_id'    => string,
 *     ],
 *     'last_refresh' => string, // ISO-8601
 * ]
 * ```
 *
 * @since 0.1.0
 *
 * @phpstan-type TokenSet array{
 *     id_token: string,
 *     access_token: string,
 *     refresh_token: string,
 *     account_id: string
 * }
 * @phpstan-type StoredData array{
 *     tokens: TokenSet,
 *     last_refresh: string
 * }
 */
class TokenStore {

	public const OPTION_NAME = 'halawa_chatgpt_tokens';

	/**
	 * Option name dictated by the official WordPress "AI" plugin
	 * (https://github.com/WordPress/ai), NOT by this plugin. That plugin reads
	 * `connectors_ai_{provider_id}_api_key` for each registered AI connector and
	 * surfaces the provider as "configured" in its Settings → Connectors UI when
	 * the option holds a non-empty string. The convention is fixed there — see
	 * its `connectors_ai_openai_api_key` / `connectors_ai_google_api_key` /
	 * `connectors_ai_anthropic_api_key` map — so this constant must match it
	 * verbatim and cannot carry our own prefix. Our provider id is `chatgpt`.
	 */
	public const CORE_API_KEY_OPTION = 'connectors_ai_chatgpt_api_key';

	/**
	 * Placeholder written to the WP-core API-key option so the connector is
	 * flagged as configured. Our model classes ignore it and use OAuth tokens.
	 */
	public const CORE_API_KEY_PLACEHOLDER = 'chatgpt-account-managed';

	/**
	 * Returns the decrypted token bundle, or null when none is stored.
	 *
	 * @since 0.1.0
	 *
	 * @return StoredData|null The token bundle, or null.
	 */
	public function get(): ?array {
		$raw = get_option( self::OPTION_NAME, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the ciphertext envelope we encoded with base64_encode() in save(); not obfuscation.
		$decoded = base64_decode( $raw, true );
		if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1 ) {
			return null;
		}
		$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key() );
		if ( false === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		if ( ! is_array( $data ) || ! isset( $data['tokens'] ) || ! is_array( $data['tokens'] ) ) {
			return null;
		}
		/**
		 * Decoded stored token bundle.
		 *
		 * @var StoredData $data
		 */
		return $data;
	}

	/**
	 * Encrypts and persists the given token bundle.
	 *
	 * @since 0.1.0
	 *
	 * @param array $tokens The token set returned by the OAuth token endpoint.
	 * @phpstan-param TokenSet $tokens
	 * @return void
	 */
	public function save( array $tokens ): void {
		$payload = array(
			'tokens'       => $tokens,
			'last_refresh' => gmdate( 'c' ),
		);
		$json    = (string) wp_json_encode( $payload );
		$nonce   = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher  = sodium_crypto_secretbox( $json, $nonce, $this->key() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding nonce+ciphertext as text for option storage; not obfuscation.
		update_option( self::OPTION_NAME, base64_encode( $nonce . $cipher ), false );
		// Flag the connector as configured for WP core's Settings → Connectors UI.
		update_option( self::CORE_API_KEY_OPTION, self::CORE_API_KEY_PLACEHOLDER, false );
	}

	/**
	 * Removes any stored token bundle.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION_NAME );
		delete_option( self::CORE_API_KEY_OPTION );
	}

	/**
	 * Returns the access token, or null when not connected.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The access token.
	 */
	public function get_access_token(): ?string {
		$data = $this->get();
		return $data['tokens']['access_token'] ?? null;
	}

	/**
	 * Returns the refresh token, or null when not connected.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The refresh token.
	 */
	public function get_refresh_token(): ?string {
		$data = $this->get();
		return $data['tokens']['refresh_token'] ?? null;
	}

	/**
	 * Returns the ChatGPT workspace account id, or null when not connected.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The account id.
	 */
	public function get_account_id(): ?string {
		$data = $this->get();
		return $data['tokens']['account_id'] ?? null;
	}

	/**
	 * Returns the UNIX timestamp of the last successful refresh, or 0 when
	 * no tokens are stored.
	 *
	 * @since 0.1.0
	 *
	 * @return int The timestamp, or 0.
	 */
	public function get_last_refresh_timestamp(): int {
		$data = $this->get();
		if ( null === $data ) {
			return 0;
		}
		$ts = strtotime( $data['last_refresh'] );
		return false === $ts ? 0 : $ts;
	}

	/**
	 * Derives the 32-byte symmetric encryption key from WordPress salts.
	 *
	 * Fails closed when AUTH_KEY/LOGGED_IN_KEY are not defined or still hold
	 * the wp-config.php placeholders — a deterministic key derived only from
	 * the plugin's namespace string would be trivially reproducible and would
	 * give a SQL-dump-only attacker full token recovery.
	 *
	 * @since 0.1.0
	 *
	 * @return string Raw 32-byte key.
	 * @throws RuntimeException When WP salts are missing or unchanged from defaults.
	 */
	private function key(): string {
		$auth      = defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '';
		$logged_in = defined( 'LOGGED_IN_KEY' ) ? (string) LOGGED_IN_KEY : '';
		if ( '' === $auth || '' === $logged_in
			|| 'put your unique phrase here' === $auth
			|| 'put your unique phrase here' === $logged_in
			|| strlen( $auth ) < 32 || strlen( $logged_in ) < 32
		) {
			throw new RuntimeException(
				'ai-provider-for-chatgpt: AUTH_KEY and LOGGED_IN_KEY must be defined with strong, unique values in wp-config.php to encrypt OAuth tokens. Regenerate the "Authentication Unique Keys and Salts" block in your wp-config.php and try again.'
			);
		}
		$material = $auth . '|' . $logged_in . '|ai-provider-for-chatgpt';
		return substr( hash( 'sha256', $material, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
