<?php
/**
 * SignInFlow class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

use InvalidArgumentException;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;

use function Halawa\ChatGptAiProvider\configured_client_id;
use function Halawa\ChatGptAiProvider\host_id;
use function Halawa\ChatGptAiProvider\redirect_uri;

use const Halawa\ChatGptAiProvider\AGENT_NAME;
use const Halawa\ChatGptAiProvider\AUTHORIZE_ENDPOINT;
use const Halawa\ChatGptAiProvider\DYNAMIC_CLIENT_ID;
use const Halawa\ChatGptAiProvider\PLAN_SCOPE;
use const Halawa\ChatGptAiProvider\RESOURCE;
use const Halawa\ChatGptAiProvider\SCOPES;

/**
 * Starts and completes Sign in with ChatGPT around a pending transient.
 *
 * @since 0.2.0
 */
final class SignInFlow {

	public const PENDING_TTL         = 600;
	public const FIRST_NOTICE_OPTION = 'halawa_chatgpt_first_signin_notice';

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Token endpoint client.
	 *
	 * @var TokenEndpoint
	 */
	private $endpoint;

	/**
	 * ID token verifier.
	 *
	 * @var JwksVerifier
	 */
	private $verifier;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param TokenStore         $store    Token storage.
	 * @param TokenEndpoint|null $endpoint Token endpoint client.
	 * @param JwksVerifier|null  $verifier ID token verifier.
	 */
	public function __construct( TokenStore $store, ?TokenEndpoint $endpoint = null, ?JwksVerifier $verifier = null ) {
		$this->store    = $store;
		$this->endpoint = $endpoint ?? new TokenEndpoint();
		$this->verifier = $verifier ?? new JwksVerifier();
	}

	/**
	 * Returns the pending transient key for a user.
	 *
	 * @since 0.2.0
	 *
	 * @param int $user_id User id.
	 * @return string Transient key.
	 */
	public static function pending_key( int $user_id ): string {
		return 'halawa_chatgpt_pending_signin_' . $user_id;
	}

	/**
	 * Creates a pending attempt and returns the authorize URL.
	 *
	 * @since 0.2.0
	 *
	 * @param int $user_id Current user id.
	 * @return array{authorize_url:string, redirect_uri:string, expires_at:int}
	 */
	public function start( int $user_id ): array {
		$record    = $this->store->get();
		$client_id = $this->store->get_client_id() ?? configured_client_id() ?? DYNAMIC_CLIENT_ID;
		$pending   = array(
			'state'        => Pkce::random(),
			'nonce'        => Pkce::random(),
			'verifier'     => Pkce::random( 48 ),
			'client_id'    => $client_id,
			'redirect_uri' => redirect_uri(),
			'created'      => time(),
		);

		$params = array(
			'client_id'             => $client_id,
			'ext_agent_host_id'     => host_id(),
			'response_type'         => 'code',
			'redirect_uri'          => $pending['redirect_uri'],
			'scope'                 => SCOPES,
			'resource'              => RESOURCE,
			'state'                 => $pending['state'],
			'nonce'                 => $pending['nonce'],
			'code_challenge_method' => 'S256',
			'code_challenge'        => Pkce::challenge( $pending['verifier'] ),
		);
		if ( DYNAMIC_CLIENT_ID === $client_id ) {
			$params['agent_name_hint'] = AGENT_NAME;
		}
		if ( null !== $record ) {
			$id_token = $this->store->get_id_token();
			$email    = $this->store->get_email();
			if ( null !== $id_token ) {
				$params['id_token_hint'] = $id_token;
			}
			if ( null !== $email ) {
				$params['login_hint'] = $email;
			}
		}

		set_transient( self::pending_key( $user_id ), $pending, self::PENDING_TTL );

		return array(
			'authorize_url' => AUTHORIZE_ENDPOINT . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
			'redirect_uri'  => $pending['redirect_uri'],
			'expires_at'    => $pending['created'] + self::PENDING_TTL,
		);
	}

	/**
	 * Validates the pasted callback, exchanges the code and saves the record.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $user_id      Current user id.
	 * @param string $callback_url Pasted callback address.
	 * @return array<string, mixed> The saved record.
	 * @throws OAuthException With one of the documented codes.
	 */
	public function complete( int $user_id, string $callback_url ): array {
		$key     = self::pending_key( $user_id );
		$pending = get_transient( $key );
		if ( ! is_array( $pending ) || ! isset( $pending['state'], $pending['created'] ) || (int) $pending['created'] + self::PENDING_TTL < time() ) {
			throw new OAuthException( 'no_pending', 'No sign-in in progress. Start again.' );
		}

		try {
			$callback = CallbackUrl::parse( $callback_url, (string) $pending['redirect_uri'] );
		} catch ( InvalidArgumentException $e ) {
			throw new OAuthException( 'bad_callback', esc_html( $e->getMessage() ) );
		}
		if ( ! hash_equals( (string) $pending['state'], $callback['state'] ) ) {
			throw new OAuthException( 'state_mismatch', 'That address belongs to a different sign-in attempt. Paste the address from the latest attempt, or start again.' );
		}

		// The attempt is consumed from here on, so a code is never exchanged twice.
		delete_transient( $key );

		if ( null !== $callback['error'] ) {
			$message = 'access_denied' === $callback['error']
				? 'Sign-in was not completed: ChatGPT plan use was not allowed.'
				: 'Sign-in was not completed (' . $callback['error'] . ').';
			throw new OAuthException( 'access_denied', esc_html( $message ) );
		}

		$client_id = $this->issued_client_id( (string) $pending['client_id'], $callback['client_id'] );

		try {
			$tokens = $this->endpoint->exchange( $client_id, $callback['code'], (string) $pending['verifier'], (string) $pending['redirect_uri'] );
		} catch ( OAuthException $e ) {
			$message = 'invalid_grant' === $e->getErrorCode()
				? 'The sign-in link was already used or expired. Start again.'
				: 'ChatGPT did not accept the sign-in: ' . $e->getMessage();
			throw new OAuthException( 'exchange_failed', esc_html( $message ), $e->getHttpStatus(), $e->getRequestId() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status and request id are not output.
		}

		try {
			$claims = $this->verifier->verify( $tokens['id_token'], $client_id, (string) $pending['nonce'] );
		} catch ( OAuthException $e ) {
			throw new OAuthException( 'id_token_invalid', esc_html( $e->getMessage() ) );
		}

		$scopes = array_values( array_filter( explode( ' ', $tokens['scope'] ) ) );
		sort( $scopes );
		if ( ! in_array( PLAN_SCOPE, $scopes, true ) ) {
			throw new OAuthException( 'plan_not_enabled', 'Your ChatGPT account did not grant plan usage. Plan usage needs a ChatGPT Plus, Pro or Go account. Nothing was saved.' );
		}

		$subject = is_string( $claims['sub'] ?? null ) ? $claims['sub'] : '';
		$stored  = $this->store->get_subject();
		if ( null !== $stored && ! hash_equals( $stored, $subject ) ) {
			throw new OAuthException( 'account_mismatch', 'That is a different ChatGPT account. Disconnect first.' );
		}

		$record = array(
			'client_id'           => $client_id,
			'ext_agent_host_id'   => host_id(),
			'subject'             => $subject,
			'email'               => is_string( $claims['email'] ?? null ) ? $claims['email'] : null,
			'name'                => is_string( $claims['name'] ?? null ) ? $claims['name'] : null,
			'scopes'              => $scopes,
			'access_token'        => $tokens['access_token'],
			'refresh_token'       => $tokens['refresh_token'],
			'id_token'            => $tokens['id_token'],
			'expires_at'          => time() + $tokens['expires_in'],
			'earliest_refresh_at' => $tokens['earliest_refresh_at'] ?? 0,
			'needs_signin'        => false,
		);
		$this->store->save( $record );
		update_option( self::FIRST_NOTICE_OPTION, 1, false );

		return (array) $this->store->get();
	}

	/**
	 * Returns the client id to exchange with, checking the callback value.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $pending_client_id Client id sent on authorize.
	 * @param string|null $callback_client_id Client id in the callback.
	 * @return string The client id.
	 * @throws OAuthException When registration did not issue a usable client id.
	 */
	private function issued_client_id( string $pending_client_id, ?string $callback_client_id ): string {
		if ( DYNAMIC_CLIENT_ID === $pending_client_id ) {
			if ( null === $callback_client_id || DYNAMIC_CLIENT_ID === $callback_client_id ) {
				throw new OAuthException( 'registration_incomplete', 'Registration incomplete, start again.' );
			}
			return $callback_client_id;
		}
		if ( null !== $callback_client_id && $callback_client_id !== $pending_client_id ) {
			throw new OAuthException( 'registration_incomplete', 'Registration incomplete, start again.' );
		}
		return $pending_client_id;
	}
}
