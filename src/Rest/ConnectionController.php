<?php
/**
 * ConnectionController class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Rest;

use Throwable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AiClient\AiClient;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Cache\TransientCache;
use Halawa\ChatGptAiProvider\OAuth\CallbackUrl;
use Halawa\ChatGptAiProvider\OAuth\OAuthException;
use Halawa\ChatGptAiProvider\OAuth\SignInFlow;
use Halawa\ChatGptAiProvider\OAuth\TokenEndpoint;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

use function Halawa\ChatGptAiProvider\redirect_uri;

use const Halawa\ChatGptAiProvider\USAGE_URL;

/**
 * REST controller backing the Settings > ChatGPT screen.
 *
 * @since 0.1.0
 */
class ConnectionController extends WP_REST_Controller {

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Sign-in flow.
	 *
	 * @var SignInFlow
	 */
	private $flow;

	/**
	 * Token endpoint client, used for revocation.
	 *
	 * @var TokenEndpoint
	 */
	private $endpoint;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TokenStore         $store    Token storage.
	 * @param SignInFlow|null    $flow     Sign-in flow.
	 * @param TokenEndpoint|null $endpoint Token endpoint client.
	 */
	public function __construct( TokenStore $store, ?SignInFlow $flow = null, ?TokenEndpoint $endpoint = null ) {
		$this->store     = $store;
		$this->endpoint  = $endpoint ?? new TokenEndpoint();
		$this->flow      = $flow ?? new SignInFlow( $store, $this->endpoint );
		$this->namespace = 'ai-provider-for-chatgpt/v1';
		$this->rest_base = 'connection';
	}

	/**
	 * Registers the REST routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'disconnect' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sign-in',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sign_in' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/callback',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'callback' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'callback_url' => array(
							'type'              => 'string',
							'required'          => true,
							// Structure is checked in CallbackUrl; here only trim and un-slash.
							'sanitize_callback' => static function ( $value ) {
								return is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
							},
							// Only size and emptiness here, so CallbackUrl can explain a wrong paste.
							'validate_callback' => static function ( $value ) {
								return is_string( $value )
									&& strlen( $value ) <= CallbackUrl::MAX_LENGTH
									&& '' !== trim( $value );
							},
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
	}

	/**
	 * Capability check for every route.
	 *
	 * @since 0.1.0
	 *
	 * @return bool|WP_Error
	 */
	public function permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage this plugin.', 'ai-provider-for-chatgpt' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * GET /connection: returns the status and consumes the first sign-in notice.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		$show_notice = (bool) get_option( SignInFlow::FIRST_NOTICE_OPTION, false );
		if ( $show_notice ) {
			delete_option( SignInFlow::FIRST_NOTICE_OPTION );
		}
		return new WP_REST_Response( $this->status( $show_notice ), 200 );
	}

	/**
	 * POST /connection/sign-in: starts a sign-in attempt.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function sign_in() {
		try {
			return new WP_REST_Response( $this->flow->start( get_current_user_id() ), 200 );
		} catch ( Throwable $e ) {
			return $this->error( $e );
		}
	}

	/**
	 * POST /connection/callback: completes the sign-in from the pasted address.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function callback( WP_REST_Request $request ) {
		try {
			$this->flow->complete( get_current_user_id(), (string) $request->get_param( 'callback_url' ) );
		} catch ( Throwable $e ) {
			return $this->error( $e );
		}
		$this->clear_model_cache();
		return new WP_REST_Response( $this->status( true ), 200 );
	}

	/**
	 * POST /connection/refresh: rotates the tokens now.
	 *
	 * Answers 409 refresh_in_progress when another request holds the refresh lock.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh() {
		try {
			$result = ( new TokenRefresher( $this->store, $this->endpoint ) )->refresh();
		} catch ( Throwable $e ) {
			return $this->error( $e );
		}
		if ( TokenRefresher::BUSY === $result ) {
			return $this->error( new OAuthException( 'refresh_in_progress', 'ChatGPT tokens are being refreshed by another request. Try again in a moment.' ) );
		}
		wp_cache_delete( TokenStore::OPTION_NAME, 'options' );
		return new WP_REST_Response(
			array_merge( $this->status( false ), array( 'refreshed_at' => $this->store->get_last_refresh_timestamp() ) ),
			200
		);
	}

	/**
	 * DELETE /connection: revokes the refresh token and clears the connection.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function disconnect(): WP_REST_Response {
		$revoked       = false;
		$refresh_token = $this->store->get_refresh_token();
		$client_id     = $this->store->get_client_id();
		if ( null !== $refresh_token && null !== $client_id ) {
			$revoked = $this->endpoint->revoke( $client_id, $refresh_token );
		}

		$this->store->clear();
		delete_option( SignInFlow::FIRST_NOTICE_OPTION );
		delete_transient( SignInFlow::pending_key( get_current_user_id() ) );
		$this->clear_model_cache();

		return new WP_REST_Response( array_merge( $this->status( false ), array( 'revoked' => $revoked ) ), 200 );
	}

	/**
	 * Maps an exception to a WP_Error.
	 *
	 * @since 1.0.0
	 *
	 * @param Throwable $e The exception.
	 * @return WP_Error
	 */
	private function error( Throwable $e ): WP_Error {
		if ( $e instanceof OAuthException ) {
			$statuses = array(
				'no_pending'          => 409,
				'refresh_in_progress' => 409,
				'plan_not_enabled'    => 403,
			);
			return new WP_Error( $e->getErrorCode(), $e->getMessage(), array( 'status' => $statuses[ $e->getErrorCode() ] ?? 400 ) );
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic under WP_DEBUG only.
			error_log( 'ai-provider-for-chatgpt: connection route failed: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
		return new WP_Error(
			'unexpected_error',
			__( 'Unexpected error, check the debug log', 'ai-provider-for-chatgpt' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Clears the SDK model cache so the model list follows the connection.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function clear_model_cache(): void {
		( new TransientCache() )->clear();
	}

	/**
	 * Builds the status payload shared by every route.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $show_notice Value of show_first_signin_notice.
	 * @return array<string, mixed>
	 */
	private function status( bool $show_notice ): array {
		$record       = $this->store->get();
		$needs_signin = null !== $record && $this->store->needs_signin();
		$expires_at   = $this->store->get_expires_at();
		$last_refresh = $this->store->get_last_refresh_timestamp();

		$has_registered = false;
		$is_configured  = false;
		try {
			$registry       = AiClient::defaultRegistry();
			$has_registered = $registry->hasProvider( 'chatgpt' );
			$is_configured  = $has_registered && $registry->isProviderConfigured( 'chatgpt' );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic under WP_DEBUG only.
				error_log( 'ai-provider-for-chatgpt: registry check failed: ' . $e->getMessage() );
			}
		}

		return array(
			'connected'                => null !== $record && ! $needs_signin,
			'needs_signin'             => $needs_signin,
			'email'                    => $this->store->get_email(),
			'name'                     => $this->store->get_name(),
			'subject'                  => $this->store->get_subject(),
			'client_id'                => $this->store->get_client_id(),
			'scopes'                   => $this->store->get_scopes(),
			'plan_usage_enabled'       => $this->store->has_plan_scope(),
			'expires_at'               => 0 !== $expires_at ? $expires_at : null,
			'last_refresh'             => 0 !== $last_refresh ? $last_refresh : null,
			'show_first_signin_notice' => $show_notice,
			'redirect_uri'             => redirect_uri(),
			'usage_url'                => USAGE_URL,
			'provider'                 => array(
				'registered' => $has_registered,
				'configured' => $is_configured,
			),
		);
	}
}
