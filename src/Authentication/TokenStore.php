<?php
/**
 * TokenStore class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Authentication;

use WordPress\AiClient\Common\Exception\RuntimeException;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

use const Halawa\ChatGptAiProvider\PLAN_SCOPE;

/**
 * Persists the Sign in with ChatGPT record in one option, encrypted at rest.
 *
 * Encryption uses libsodium with a 32-byte key derived from WordPress's
 * `AUTH_KEY` + `LOGGED_IN_KEY` salts, so a SQL dump alone is insufficient
 * to recover the tokens. The plaintext is the version 2 record: client_id,
 * ext_agent_host_id, subject, email, name, scopes, the three tokens,
 * expires_at, earliest_refresh_at, last_refresh and needs_signin.
 *
 * @since 0.1.0
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

	public const RECORD_VERSION = 2;

	/**
	 * Returns the version 2 record, or null when absent or not version 2.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>|null The record.
	 */
	public function get(): ?array {
		$data = $this->decode();
		if ( null === $data || self::RECORD_VERSION !== ( $data['version'] ?? null ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Returns true when the option holds something that is not a v2 record.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True for a 0.1.x record or an unreadable one.
	 */
	public function has_legacy_record(): bool {
		$raw = get_option( self::OPTION_NAME, '' );
		return is_string( $raw ) && '' !== $raw && null === $this->get();
	}

	/**
	 * Saves a full record, stamping the version and last_refresh.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $record The record.
	 * @return void
	 */
	public function save( array $record ): void {
		$record['version']      = self::RECORD_VERSION;
		$record['last_refresh'] = gmdate( 'c' );
		$this->write( $record );
		// Flag the connector as configured for WP core's Settings > Connectors UI.
		update_option( self::CORE_API_KEY_OPTION, self::CORE_API_KEY_PLACEHOLDER, false );
		TokenRefresher::schedule();
	}

	/**
	 * Merges fields into the stored record and saves it.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $fields Fields to replace.
	 * @return void
	 */
	public function update( array $fields ): void {
		$record = $this->get();
		if ( null === $record ) {
			return;
		}
		$this->save( array_merge( $record, $fields ) );
	}

	/**
	 * Marks the record as needing a new sign-in, keeping identity fields.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function mark_needs_signin(): void {
		$record = $this->get();
		if ( null === $record ) {
			return;
		}
		$record['needs_signin'] = true;
		$this->write( $record );
		TokenRefresher::unschedule();
	}

	/**
	 * Removes the record and the core placeholder.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION_NAME );
		delete_option( self::CORE_API_KEY_OPTION );
		TokenRefresher::unschedule();
	}

	/**
	 * Returns the access token.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The token.
	 */
	public function get_access_token(): ?string {
		return $this->string_field( 'access_token' );
	}

	/**
	 * Returns the refresh token.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The token.
	 */
	public function get_refresh_token(): ?string {
		return $this->string_field( 'refresh_token' );
	}

	/**
	 * Returns the ID token.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The token.
	 */
	public function get_id_token(): ?string {
		return $this->string_field( 'id_token' );
	}

	/**
	 * Returns the issued client id.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The client id.
	 */
	public function get_client_id(): ?string {
		return $this->string_field( 'client_id' );
	}

	/**
	 * Returns the ChatGPT account subject.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The sub claim.
	 */
	public function get_subject(): ?string {
		return $this->string_field( 'subject' );
	}

	/**
	 * Returns the account email.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The email.
	 */
	public function get_email(): ?string {
		return $this->string_field( 'email' );
	}

	/**
	 * Returns the account display name.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The name.
	 */
	public function get_name(): ?string {
		return $this->string_field( 'name' );
	}

	/**
	 * Returns the ext_agent_host_id used at sign-in.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The host id.
	 */
	public function get_host_id(): ?string {
		return $this->string_field( 'ext_agent_host_id' );
	}

	/**
	 * Returns the granted scopes.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The scopes.
	 */
	public function get_scopes(): array {
		$scopes = $this->get()['scopes'] ?? array();
		return is_array( $scopes ) ? array_values( array_filter( $scopes, 'is_string' ) ) : array();
	}

	/**
	 * Returns the access token expiry, 0 when unknown.
	 *
	 * @since 0.2.0
	 *
	 * @return int UNIX timestamp.
	 */
	public function get_expires_at(): int {
		return $this->int_field( 'expires_at' );
	}

	/**
	 * Returns the advisory earliest refresh time, 0 when unknown.
	 *
	 * @since 0.2.0
	 *
	 * @return int UNIX timestamp.
	 */
	public function get_earliest_refresh_at(): int {
		return $this->int_field( 'earliest_refresh_at' );
	}

	/**
	 * Returns true when the record must be renewed by a new sign-in.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when marked.
	 */
	public function needs_signin(): bool {
		return true === ( $this->get()['needs_signin'] ?? false );
	}

	/**
	 * Returns true when the plan usage scope was granted.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when granted.
	 */
	public function has_plan_scope(): bool {
		return in_array( PLAN_SCOPE, $this->get_scopes(), true );
	}

	/**
	 * Returns the time of the last save, 0 when no record is stored.
	 *
	 * @since 0.1.0
	 *
	 * @return int UNIX timestamp.
	 */
	public function get_last_refresh_timestamp(): int {
		$value = $this->get()['last_refresh'] ?? null;
		$ts    = is_string( $value ) ? strtotime( $value ) : false;
		return false === $ts ? 0 : $ts;
	}

	/**
	 * Decrypts the option into an array, whatever its shape.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed>|null The decoded payload, or null.
	 */
	private function decode(): ?array {
		$raw = get_option( self::OPTION_NAME, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the ciphertext envelope written by write(); not obfuscation.
		$decoded = base64_decode( $raw, true );
		if ( false === $decoded || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1 ) {
			return null;
		}
		$nonce = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->key() );
		if ( false === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Encrypts and stores a record as is.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $record The record.
	 * @return void
	 */
	private function write( array $record ): void {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( (string) wp_json_encode( $record ), $nonce, $this->key() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding nonce+ciphertext as text for option storage; not obfuscation.
		update_option( self::OPTION_NAME, base64_encode( $nonce . $cipher ), false );
	}

	/**
	 * Returns a non-empty string field of the record.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key Field name.
	 * @return string|null The value, or null.
	 */
	private function string_field( string $key ): ?string {
		$value = $this->get()[ $key ] ?? null;
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Returns an integer field of the record, 0 when missing.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key Field name.
	 * @return int The value.
	 */
	private function int_field( string $key ): int {
		$value = $this->get()[ $key ] ?? 0;
		return is_int( $value ) ? $value : 0;
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
