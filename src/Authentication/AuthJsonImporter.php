<?php
/**
 * AuthJsonImporter class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Authentication;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use Halawa\ChatGptAiProvider\OAuth\IdTokenClaims;

/**
 * Parses the JSON bundle Codex CLI writes to `~/.codex/auth.json` and stores
 * it via {@see TokenStore}.
 *
 * Expected shape (matches Codex's `auth.json`):
 *
 * ```json
 * {
 *   "tokens": {
 *     "id_token":     "<JWT>",
 *     "access_token": "<JWT>",
 *     "refresh_token":"<opaque>",
 *     "account_id":   "<uuid>"
 *   },
 *   "last_refresh":   "<ISO-8601>"
 * }
 * ```
 *
 * The top-level `OPENAI_API_KEY` field that Codex may include alongside the
 * `tokens` object is ignored — this plugin is explicitly the OAuth path.
 *
 * @since 0.1.0
 */
class AuthJsonImporter {

	/**
	 * Persistent token storage.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TokenStore $store Persistent token storage.
	 */
	public function __construct( TokenStore $store ) {
		$this->store = $store;
	}

	/**
	 * Validates the bundle and persists it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw_json The JSON contents pasted/uploaded by the admin.
	 * @return void
	 * @throws InvalidArgumentException When the bundle is malformed or missing required fields.
	 */
	public function import( string $raw_json ): void {
		$raw_json = trim( $raw_json );
		if ( '' === $raw_json ) {
			throw new InvalidArgumentException( 'No auth bundle provided.' );
		}
		$data = json_decode( $raw_json, true );
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'Auth bundle is not valid JSON.' );
		}
		if ( ! isset( $data['tokens'] ) || ! is_array( $data['tokens'] ) ) {
			throw new InvalidArgumentException( 'Auth bundle is missing the "tokens" object.' );
		}

		$tokens        = $data['tokens'];
		$id_token      = $this->require_string( $tokens, 'id_token' );
		$access_token  = $this->require_string( $tokens, 'access_token' );
		$refresh_token = $this->require_string( $tokens, 'refresh_token' );

		$account_id = isset( $tokens['account_id'] ) && is_string( $tokens['account_id'] ) && '' !== $tokens['account_id']
			? $tokens['account_id']
			: ( IdTokenClaims::account_id( $id_token ) ?? '' );
		if ( '' === $account_id ) {
			throw new InvalidArgumentException(
				'Could not determine the ChatGPT account_id (neither in the bundle nor in the id_token claims).'
			);
		}

		if ( IdTokenClaims::payload( $id_token ) === array() ) {
			throw new InvalidArgumentException( 'id_token is not a decodable JWT.' );
		}

		$this->store->save(
			array(
				'id_token'      => $id_token,
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'account_id'    => $account_id,
			)
		);

		// Do not eagerly refresh here. OpenAI rotates refresh tokens on every
		// successful exchange, which would burn the refresh_token still sitting
		// in the user's local ~/.codex/auth.json and break re-imports. The
		// authentication layer refreshes lazily when the access_token expires.
	}

	/**
	 * Returns a required string field from the tokens object.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $tokens The tokens object.
	 * @param string               $key    The key to read.
	 * @return string The non-empty string value.
	 * @throws InvalidArgumentException When the field is missing or not a non-empty string.
	 */
	private function require_string( array $tokens, string $key ): string {
		if ( ! isset( $tokens[ $key ] ) || ! is_string( $tokens[ $key ] ) || '' === $tokens[ $key ] ) {
			throw new InvalidArgumentException( sprintf( 'Auth bundle is missing required field "tokens.%s".', esc_html( $key ) ) );
		}
		return $tokens[ $key ];
	}
}
