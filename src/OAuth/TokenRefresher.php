<?php
/**
 * TokenRefresher class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

use WordPress\AiClient\Common\Exception\RuntimeException;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;

use function Halawa\ChatGptAiProvider\oauth_client_id;

/**
 * Exchanges the stored refresh token for a fresh access/refresh/id token set.
 *
 * Mirrors OpenAI Codex CLI's behavior: an 8-day proactive refresh window
 * plus reactive refresh on HTTP 401 (handled at the model layer).
 *
 * @since 0.1.0
 */
class TokenRefresher {

	public const TOKEN_ENDPOINT           = 'https://auth.openai.com/oauth/token';
	public const REFRESH_INTERVAL_SECONDS = 8 * DAY_IN_SECONDS;

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
	 * Returns true when the stored tokens are older than the refresh window.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a proactive refresh is due.
	 */
	public function is_due(): bool {
		$last = $this->store->get_last_refresh_timestamp();
		// Unknown/corrupted last_refresh — refresh proactively rather than
		// waiting for a 401 to force it.
		if ( 0 === $last ) {
			return true;
		}
		return ( time() - $last ) >= self::REFRESH_INTERVAL_SECONDS;
	}

	/**
	 * Performs the refresh exchange and persists the new tokens.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 * @throws RuntimeException When no refresh token is stored or the exchange fails.
	 */
	public function refresh(): void {
		$refresh_token = $this->store->get_refresh_token();
		if ( null === $refresh_token ) {
			throw new RuntimeException( 'Cannot refresh: no refresh token stored.' );
		}
		$client_id = oauth_client_id();

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => (string) wp_json_encode(
					array(
						'client_id'     => $client_id,
						'grant_type'    => 'refresh_token',
						'refresh_token' => $refresh_token,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'OAuth refresh request failed: ' . esc_html( $response->get_error_message() ) );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$detail = '';
			$parsed = json_decode( $raw_body, true );
			if ( is_array( $parsed ) ) {
				$code   = isset( $parsed['error'] ) && is_string( $parsed['error'] ) ? $parsed['error'] : '';
				$desc   = isset( $parsed['error_description'] ) && is_string( $parsed['error_description'] )
					? $parsed['error_description']
					: '';
				$detail = trim( $code . ': ' . $desc, ': ' );
			}
			if ( '' === $detail ) {
				$detail = substr( $raw_body, 0, 300 );
			}
			throw new RuntimeException( sprintf( 'OAuth refresh returned HTTP %d. %s', (int) $status, esc_html( $detail ) ) );
		}
		$body = json_decode( $raw_body, true );
		if ( ! is_array( $body ) ) {
			throw new RuntimeException( 'OAuth refresh response was not valid JSON.' );
		}

		$access_token = isset( $body['access_token'] ) && is_string( $body['access_token'] ) ? $body['access_token'] : null;
		$id_token     = isset( $body['id_token'] ) && is_string( $body['id_token'] ) ? $body['id_token'] : null;
		$new_refresh  = isset( $body['refresh_token'] ) && is_string( $body['refresh_token'] )
			? $body['refresh_token']
			: $refresh_token;
		if ( null === $access_token || null === $id_token ) {
			throw new RuntimeException( 'OAuth refresh response missing access_token or id_token.' );
		}

		$account_id           = $this->store->get_account_id() ?? '';
		$account_id_from_claims = IdTokenClaims::account_id( $id_token );
		if ( null !== $account_id_from_claims ) {
			$account_id = $account_id_from_claims;
		}

		$this->store->save(
			array(
				'id_token'      => $id_token,
				'access_token'  => $access_token,
				'refresh_token' => $new_refresh,
				'account_id'    => $account_id,
			)
		);
	}
}
