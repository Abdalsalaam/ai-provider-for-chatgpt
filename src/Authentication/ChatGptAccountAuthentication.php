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
use Halawa\ChatGptAiProvider\OAuth\IdTokenClaims;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

use function Halawa\ChatGptAiProvider\codex_client_version;

/**
 * Adds the OAuth bearer token and ChatGPT account headers to outgoing requests.
 *
 * Performs a proactive refresh when the stored tokens are older than the Codex
 * CLI refresh window (8 days) and exposes {@see forceRefresh()} so the model
 * layer can retry once on HTTP 401.
 *
 * @since 0.1.0
 */
class ChatGptAccountAuthentication implements RequestAuthenticationInterface {

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
	 * Memoized installation id for this request.
	 *
	 * @var string|null
	 */
	private static $installation_id_cache;

	/**
	 * Memoized thread id for this request.
	 *
	 * @var string|null
	 */
	private static $thread_id_cache;

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
	 * @throws RuntimeException When no valid access token or account ID is available.
	 */
	public function authenticateRequest( Request $request ): Request {
		$access_token = $this->store->get_access_token();
		if ( null === $access_token || '' === $access_token || IdTokenClaims::is_expired( $access_token ) || $this->refresher->is_due() ) {
			$this->refresher->refresh();
			$access_token = $this->store->get_access_token();
		}
		$account_id = $this->store->get_account_id();
		if ( null === $access_token || '' === $access_token || null === $account_id || '' === $account_id ) {
			throw new RuntimeException(
				'The ChatGPT provider is not connected. Visit Settings → ChatGPT to sign in.'
			);
		}

		// The Codex backend allowlists known originators; presenting as
		// codex_cli_rs is what Codex CLI itself sends and ensures the request
		// is accepted. session-id / thread-id / x-codex-installation-id are
		// required by /responses (codex-rs/codex-api/src/requests/headers.rs).
		if ( null === self::$installation_id_cache ) {
			$installation_id = get_option( 'halawa_chatgpt_installation_id', '' );
			if ( ! is_string( $installation_id ) || '' === $installation_id ) {
				$installation_id = wp_generate_uuid4();
				update_option( 'halawa_chatgpt_installation_id', $installation_id, false );
			}
			self::$installation_id_cache = $installation_id;
		}
		$installation_id = self::$installation_id_cache;

		// thread-id is the Codex backend's conversation-continuity key, so keep
		// it stable per installation; session-id rotates per request, which
		// matches Codex CLI behavior for one-shot completions.
		if ( null === self::$thread_id_cache ) {
			$thread_id = get_option( 'halawa_chatgpt_thread_id', '' );
			if ( ! is_string( $thread_id ) || '' === $thread_id ) {
				$thread_id = wp_generate_uuid4();
				update_option( 'halawa_chatgpt_thread_id', $thread_id, false );
			}
			self::$thread_id_cache = $thread_id;
		}
		$thread_id = self::$thread_id_cache;
		$authenticated = $request
			->withHeader( 'Authorization', 'Bearer ' . $access_token )
			->withHeader( 'ChatGPT-Account-ID', $account_id )
			->withHeader( 'originator', 'codex_cli_rs' )
			->withHeader( 'User-Agent', 'codex_cli_rs/' . codex_client_version() . ' (WordPress; ai-provider-for-chatgpt)' )
			->withHeader( 'session-id', wp_generate_uuid4() )
			->withHeader( 'thread-id', $thread_id )
			->withHeader( 'x-codex-installation-id', $installation_id );

		$data = $this->store->get();
		if ( null !== $data && IdTokenClaims::is_fedramp( $data['tokens']['id_token'] ) ) {
			$authenticated = $authenticated->withHeader( 'X-OpenAI-Fedramp', 'true' );
		}

		return $authenticated;
	}

	/**
	 * Triggers an immediate token refresh, ignoring the proactive window.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function force_refresh(): void {
		$this->refresher->refresh();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Tokens are stored out-of-band in the encrypted `TokenStore`, not via the
	 * SDK's credential schema, so this returns an empty object schema purely
	 * to satisfy the interface contract.
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
}
