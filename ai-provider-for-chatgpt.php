<?php
/**
 * Plugin Name: AI Provider for ChatGPT
 * Plugin URI: https://github.com/Abdalsalaam/ai-provider-for-chatgpt
 * Description: Registers OpenAI as a WordPress AI provider authenticated with a ChatGPT account (Free/Plus/Pro) instead of an API key. Usage is billed against the connected ChatGPT subscription.
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 0.1.4
 * Author: Abdalsalaam Halawa
 * Author URI: https://halawa.io
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-chatgpt
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use Halawa\ChatGptAiProvider\Admin\Assets;
use Halawa\ChatGptAiProvider\Admin\SettingsPage;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Cache\TransientCache;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;
use Halawa\ChatGptAiProvider\Rest\CacheController;
use Halawa\ChatGptAiProvider\Rest\ConnectionController;
use Halawa\ChatGptAiProvider\Rest\DiagnosticsController;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Plugin version, also used in the User-Agent header.
 *
 * @since 0.2.0
 */
const VERSION = '0.2.0';

/**
 * Sign in with ChatGPT endpoints and identifiers (OpenAI open-source app flow).
 *
 * @since 0.2.0
 */
const AUTHORIZE_ENDPOINT   = 'https://auth.openai.com/api/accounts/authorize';
const TOKEN_ENDPOINT       = 'https://auth.openai.com/api/accounts/oauth/token';
const REVOKE_ENDPOINT      = 'https://auth.openai.com/api/accounts/oauth/revoke';
const JWKS_URI             = 'https://auth.openai.com/.well-known/jwks.json';
const ISSUER               = 'https://auth.openai.com';
const RESOURCE             = 'https://api.openai.com/v1';
const API_BASE_URL         = 'https://api.openai.com/v1';
const SCOPES               = 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct';
const PLAN_SCOPE           = 'chatgpt.tokens.use.direct';
const AGENT_NAME           = 'AI Provider for ChatGPT';
const DYNAMIC_CLIENT_ID    = 'dynamic_agent_client';
const DEFAULT_REDIRECT_URI = 'http://127.0.0.1:1455/auth/callback';
const USAGE_URL            = 'https://chatgpt.com/settings/usage';

/**
 * Returns the redirect URI sent on authorize and code exchange.
 *
 * @since 0.2.0
 *
 * @return string The redirect URI.
 */
function redirect_uri(): string {
	/**
	 * Filters the OAuth redirect URI, for a registered https client.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uri Default loopback redirect URI.
	 */
	$value = apply_filters( 'halawa_chatgpt_redirect_uri', DEFAULT_REDIRECT_URI );
	return is_string( $value ) && '' !== $value ? $value : DEFAULT_REDIRECT_URI;
}

/**
 * Returns the client id from the CHATGPT_OAUTH_CLIENT_ID constant, if set.
 *
 * @since 0.2.0
 *
 * @return string|null The configured client id, or null.
 */
function configured_client_id(): ?string {
	if ( ! defined( 'CHATGPT_OAUTH_CLIENT_ID' ) ) {
		return null;
	}
	$value = constant( 'CHATGPT_OAUTH_CLIENT_ID' );
	return is_string( $value ) && '' !== $value ? $value : null;
}

/**
 * Returns the stable ext_agent_host_id for this site.
 *
 * @since 0.2.0
 *
 * @return string The host id, "urn:uuid:" plus the installation uuid.
 */
function host_id(): string {
	$id = get_option( 'halawa_chatgpt_installation_id', '' );
	if ( ! is_string( $id ) || '' === $id ) {
		// add_option() keeps an id another request wrote first.
		add_option( 'halawa_chatgpt_installation_id', wp_generate_uuid4(), '', false );
		$id = (string) get_option( 'halawa_chatgpt_installation_id', '' );
	}
	return 'urn:uuid:' . $id;
}

/**
 * Returns the User-Agent sent to OpenAI.
 *
 * @since 0.2.0
 *
 * @return string The User-Agent header value.
 */
function user_agent(): string {
	return 'ai-provider-for-chatgpt/' . VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')';
}

/**
 * Codex CLI client version reported on every Codex backend request.
 *
 * The backend gates which model set, capabilities, and tool types a client
 * is allowed to see by inspecting this value (both as the `client_version`
 * query string on `/models` and as the User-Agent prefix on `/responses`).
 * Bumping it surfaces newer models (e.g. gpt-5.5) that older client versions
 * are not entitled to. Override via the
 * `halawa_chatgpt_codex_client_version` filter.
 *
 * @since 0.1.0
 */
const DEFAULT_CODEX_CLIENT_VERSION = '0.133.0';

/**
 * Returns the Codex CLI client version the plugin should impersonate.
 *
 * @since 0.1.0
 *
 * @return string The client version (e.g. "0.65.0").
 */
function codex_client_version(): string {
	/**
	 * Filters the Codex CLI client version the plugin impersonates.
	 *
	 * The Codex backend gates the visible model set, capability flags, and
	 * tool types behind this value. Override it on a site to opt into newer
	 * models without waiting for a plugin release.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version Default client version (e.g. "0.65.0").
	 */
	$value = apply_filters( 'halawa_chatgpt_codex_client_version', DEFAULT_CODEX_CLIENT_VERSION );
	return is_string( $value ) && '' !== $value ? $value : DEFAULT_CODEX_CLIENT_VERSION;
}

/**
 * Registers the ChatGPT provider with the WordPress AI Client.
 *
 * Registration happens unconditionally so the connector is discoverable in
 * the AI picker even before the admin imports an auth bundle. When no tokens
 * are stored, the availability check will mark the provider as unavailable
 * and the SettingsPage tells the admin how to connect.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	// Wire a transient-backed PSR-16 cache so the SDK's `/models` lookup is
	// persisted across requests. Without this the SDK falls back to a
	// per-request in-memory array, which means the Codex /models endpoint
	// is hit on every admin page load. Idempotent if called more than once.
	if ( null === AiClient::getCache() ) {
		AiClient::setCache( new TransientCache() );
	}

	$registry = AiClient::defaultRegistry();
	if ( ! $registry->hasProvider( ChatGptProvider::class ) ) {
		$registry->registerProvider( ChatGptProvider::class );
	}

	/*
	 * Plant a placeholder ApiKeyRequestAuthentication in the registry when a
	 * token bundle is present. The SDK uses this to flip the provider's
	 * "configured" flag for WP core's AI picker. The placeholder is never
	 * actually used to authenticate requests — our model classes override
	 * getRequestAuthentication() to return ChatGptAccountAuthentication.
	 */
	if ( ( new TokenStore() )->get_access_token() !== null ) {
		try {
			$registry->setProviderRequestAuthentication(
				ChatGptProvider::class,
				new ApiKeyRequestAuthentication( TokenStore::CORE_API_KEY_PLACEHOLDER )
			);
		} catch ( \Throwable $e ) {
			// Registry not ready or auth-type mismatch; provider stays usable.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic under WP_DEBUG only.
				error_log( 'ai-provider-for-chatgpt: registry auth wiring failed: ' . $e->getMessage() );
			}
		}
	}
}

add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );

/**
 * Registers the REST routes that back the React settings UI.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_rest_controllers(): void {
	$store = new TokenStore();
	( new ConnectionController( $store ) )->register_routes();
	( new DiagnosticsController( $store ) )->register_routes();
	( new CacheController() )->register_routes();
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_controllers' );

if ( is_admin() ) {
	( new SettingsPage() )->register();
	( new Assets() )->register();
}
