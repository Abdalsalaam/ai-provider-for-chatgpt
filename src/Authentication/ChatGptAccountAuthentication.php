<?php
/**
 * ChatGptAccountAuthentication class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Authentication;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use Halawa\ChatGptAiProvider\OAuth\OAuthException;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

use function Halawa\ChatGptAiProvider\user_agent;

/**
 * Adds the bearer token and user agent to API requests and refreshes when due.
 *
 * @since 0.1.0
 */
class ChatGptAccountAuthentication implements RequestAuthenticationInterface {

	/**
	 * Message when the stored record needs a new sign-in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SESSION_ENDED = 'ChatGPT session ended. Sign in again under Settings > ChatGPT.';

	/**
	 * Message when no record is stored.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_CONNECTED = 'The ChatGPT provider is not connected. Visit Settings > ChatGPT to sign in.';

	/**
	 * Persistent token storage.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Token refresher used to renew access tokens on demand.
	 *
	 * @var TokenRefresher
	 */
	private $refresher;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TokenStore          $store     Persistent token storage.
	 * @param TokenRefresher|null $refresher Optional refresher (testing seam).
	 */
	public function __construct( TokenStore $store, ?TokenRefresher $refresher = null ) {
		$this->store     = $store;
		$this->refresher = $refresher ?? new TokenRefresher( $store );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Request $request The outgoing request to authenticate.
	 * @return Request
	 * @throws RuntimeException When not connected or the session ended.
	 * @throws OAuthException When a due refresh fails and the stored token cannot be used.
	 */
	public function authenticateRequest( Request $request ): Request {
		if ( $this->store->needs_signin() ) {
			throw new RuntimeException( self::SESSION_ENDED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text constant, escaped where shown.
		}
		if ( ! $this->has_token() ) {
			throw new RuntimeException( self::NOT_CONNECTED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text constant, escaped where shown.
		}
		if ( $this->refresher->is_due() ) {
			try {
				$this->refresher->refresh( $this->store->get_refresh_token() );
			} catch ( OAuthException $e ) {
				if ( ! self::is_transient( $e ) || $this->refresher->is_expired() ) {
					throw $e;
				}
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic under WP_DEBUG only.
					error_log( 'ai-provider-for-chatgpt: refresh failed, using the current token: ' . $e->getErrorCode() );
				}
			}
		}
		if ( ! $this->has_token() ) {
			throw new RuntimeException( self::NOT_CONNECTED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text constant, escaped where shown.
		}
		return $request
			->withHeader( 'Authorization', 'Bearer ' . $this->store->get_access_token() )
			->withHeader( 'User-Agent', user_agent() );
	}

	/**
	 * Refreshes after a 401 unless another process already replaced the token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $failed_access_token The access token the API rejected.
	 * @return void
	 */
	public function force_refresh( string $failed_access_token ): void {
		$current = (string) $this->store->get_access_token();
		if ( '' !== $current && ! hash_equals( $current, $failed_access_token ) ) {
			return;
		}
		$this->refresher->refresh( $this->store->get_refresh_token() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Tokens live in the encrypted TokenStore, not in the SDK's credential
	 * schema, so this returns an empty object schema for the interface.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The JSON schema.
	 */
	public static function getJsonSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Whether the store holds a non-empty access token.
	 *
	 * @return bool
	 */
	private function has_token(): bool {
		$token = $this->store->get_access_token();
		return null !== $token && '' !== $token;
	}

	/**
	 * Whether a refresh error is a network, server or lock-wait failure.
	 *
	 * @param OAuthException $e The refresh error.
	 * @return bool
	 */
	private static function is_transient( OAuthException $e ): bool {
		$code = $e->getErrorCode();
		return 'network_error' === $code || 'refresh_in_progress' === $code || $e->getHttpStatus() >= 500;
	}
}
