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
use Halawa\ChatGptAiProvider\Authentication\AuthJsonImporter;
use Halawa\ChatGptAiProvider\Authentication\PairingTokenStore;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\IdTokenClaims;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

use function Halawa\ChatGptAiProvider\oauth_client_id;

/**
 * REST controller backing the React Settings → ChatGPT screen.
 *
 * Exposes the connection state and the three mutating actions the UI offers:
 * import, refresh, and disconnect.
 *
 * @since 0.1.0
 */
class ConnectionController extends WP_REST_Controller {

	/**
	 * Persistent token storage.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Short-lived pairing tokens issued for the companion CLI.
	 *
	 * @var PairingTokenStore
	 */
	private $pairings;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TokenStore             $store    Persistent token storage.
	 * @param PairingTokenStore|null $pairings Optional pairing-token store (testing seam).
	 */
	public function __construct( TokenStore $store, ?PairingTokenStore $pairings = null ) {
		$this->store     = $store;
		$this->pairings  = $pairings ?? new PairingTokenStore();
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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'bundle' => array(
							'type'              => 'string',
							'required'          => true,
							// The bundle is a raw JSON document; structure and field
							// validation are enforced downstream by AuthJsonImporter
							// (json_decode + per-field type checks). We only un-slash
							// the magic-quoted POST body here and reject non-strings.
							'sanitize_callback' => static function ( $value ) {
								return is_string( $value ) ? wp_unslash( $value ) : '';
							},
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && '' !== trim( $value );
							},
						),
					),
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
			'/' . $this->rest_base . '/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pairing',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'issue_pairing' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);

		/*
		 * Intentionally public endpoint (permission_callback => __return_true).
		 *
		 * The companion CLI that posts here holds no WordPress login cookie and
		 * cannot pass current_user_can(). The security boundary is instead the
		 * one-time, single-use 256-bit pairing token in the request body: it is
		 * format-validated by the `token` validate_callback (64 hex chars) and
		 * consumed atomically in redeem_pairing() before any credential is
		 * stored. As a secondary, non-authorization safeguard, redeem_pairing()
		 * also applies a per-IP rate limit to blunt DoS / brute-force attempts.
		 */
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/pair',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'redeem_pairing' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'token'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => static function ( $value ) {
								return is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
							},
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && preg_match( '/^[a-f0-9]{64}$/', $value ) === 1;
							},
						),
						'bundle' => array(
							'type'              => 'string',
							'required'          => true,
							// Same rationale as /connection POST: AuthJsonImporter parses
							// and validates the JSON; we only un-slash.
							'sanitize_callback' => static function ( $value ) {
								return is_string( $value ) ? wp_unslash( $value ) : '';
							},
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && '' !== trim( $value );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Capability check for every route in this controller.
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
	 * Per-IP rate limiter for the public pairing-redeem endpoint.
	 *
	 * This is a secondary DoS / brute-force safeguard, NOT the authorization
	 * gate — the security boundary is the single-use token validated and
	 * consumed in redeem_pairing(). It is invoked from that callback (the route
	 * itself is intentionally public) rather than wired as a permission_callback,
	 * because a rate limiter does not express authorization intent.
	 *
	 * A simple sliding-window counter backed by a transient. The cap is
	 * intentionally generous so the legitimate CLI retry path is never affected.
	 *
	 * @since 0.1.0
	 *
	 * @return bool|WP_Error True when the request may proceed, WP_Error otherwise.
	 */
	private function pair_rate_limit() {
		$ip = $this->client_ip();
		if ( '' === $ip ) {
			return true;
		}
		/**
		 * Filters the per-IP attempt cap for redeeming pairing tokens.
		 *
		 * @since 0.1.0
		 *
		 * @param int $max_attempts Maximum redemption attempts per minute. Default 10.
		 */
		$max_attempts = (int) apply_filters( 'halawa_chatgpt_pair_rate_limit', 10 );
		if ( $max_attempts <= 0 ) {
			return true;
		}
		$key     = 'halawa_chatgpt_pair_rl_' . hash( 'sha256', $ip );
		$current = (int) get_transient( $key );
		if ( $current >= $max_attempts ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many pairing attempts. Please wait a minute and try again.', 'ai-provider-for-chatgpt' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Returns the requesting IP, preferring the immediate REMOTE_ADDR.
	 *
	 * Proxy headers (X-Forwarded-For, etc.) are intentionally ignored to avoid
	 * trivial spoofing of the rate-limit bucket.
	 *
	 * @since 0.1.0
	 *
	 * @return string The IP, or the empty string when unavailable.
	 */
	private function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$remote = filter_var( $remote, FILTER_VALIDATE_IP );
		return false === $remote ? '' : (string) $remote;
	}

	/**
	 * GET /connection — returns the current connection state.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		return new WP_REST_Response( $this->build_status_payload(), 200 );
	}

	/**
	 * POST /connection — imports a Codex auth.json bundle.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$bundle = (string) $request->get_param( 'bundle' );
		try {
			( new AuthJsonImporter( $this->store ) )->import( $bundle );
		} catch ( Throwable $e ) {
			return new WP_Error(
				'import_failed',
				$e->getMessage(),
				array( 'status' => 400 )
			);
		}
		return new WP_REST_Response( $this->build_status_payload(), 200 );
	}

	/**
	 * DELETE /connection — clears the stored token bundle.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function disconnect(): WP_REST_Response {
		$this->store->clear();
		return new WP_REST_Response( $this->build_status_payload(), 200 );
	}

	/**
	 * POST /connection/refresh — exchanges the refresh token for a new bundle.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh() {
		try {
			( new TokenRefresher( $this->store ) )->refresh();
		} catch ( Throwable $e ) {
			return new WP_Error(
				'refresh_failed',
				$e->getMessage(),
				array( 'status' => 502 )
			);
		}
		return new WP_REST_Response(
			array_merge(
				$this->build_status_payload(),
				array( 'refreshed_at' => $this->store->get_last_refresh_timestamp() )
			),
			200
		);
	}

	/**
	 * POST /connection/pairing — issues a one-time pairing token for the CLI.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue_pairing() {
		try {
			$issued = $this->pairings->issue();
		} catch ( Throwable $e ) {
			return new WP_Error(
				'pairing_issue_failed',
				__( 'Could not generate a pairing token. The system CSPRNG may be unavailable.', 'ai-provider-for-chatgpt' ),
				array( 'status' => 500 )
			);
		}
		return new WP_REST_Response(
			array(
				'token'      => $issued['token'],
				'expires_at' => $issued['expires_at'],
				'site_url'   => home_url(),
				'rest_url'   => rest_url( $this->namespace . '/' . $this->rest_base . '/pair' ),
			),
			200
		);
	}

	/**
	 * POST /connection/pair — redeems a pairing token by storing the OAuth bundle.
	 *
	 * Public route; the security boundary is the unguessable single-use token.
	 * A per-IP rate limit is applied first as a secondary DoS / brute-force
	 * safeguard before the token is consumed.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function redeem_pairing( WP_REST_Request $request ) {
		$rate_limit = $this->pair_rate_limit();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$token  = (string) $request->get_param( 'token' );
		$bundle = (string) $request->get_param( 'bundle' );

		if ( ! $this->pairings->consume( $token ) ) {
			return new WP_Error(
				'invalid_pairing_token',
				__( 'The pairing token is invalid, expired, or already used.', 'ai-provider-for-chatgpt' ),
				array( 'status' => 403 )
			);
		}

		try {
			( new AuthJsonImporter( $this->store ) )->import( $bundle );
		} catch ( Throwable $e ) {
			// The token has already been spent; surface a generic error rather
			// than echoing parser internals (which could include slices of the
			// caller's bundle) to an unauthenticated client.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic under WP_DEBUG only.
				error_log( 'ai-provider-for-chatgpt: pairing import failed: ' . $e->getMessage() );
			}
			return new WP_Error(
				'pairing_import_failed',
				__( 'The pairing bundle could not be imported.', 'ai-provider-for-chatgpt' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Fires after a successful CLI pairing redemption.
		 *
		 * Sites can hook this to write an audit-log entry, send a notification,
		 * or invalidate any session that should not survive a re-pair.
		 *
		 * @since 0.1.0
		 */
		do_action( 'halawa_chatgpt_paired' );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic under WP_DEBUG only.
			error_log( 'ai-provider-for-chatgpt: pairing succeeded.' );
		}

		return new WP_REST_Response( array( 'paired' => true ), 200 );
	}

	/**
	 * Builds the shared status payload used by every route in this controller.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	private function build_status_payload(): array {
		$data       = $this->store->get();
		$connected  = false;
		$email      = null;
		$plan       = null;
		$account_id = null;
		$is_fedramp = false;
		$id_expired = false;

		if ( null !== $data ) {
			$id_token   = $data['tokens']['id_token'];
			$connected  = '' !== ( $data['tokens']['access_token'] ?? '' );
			$email      = IdTokenClaims::email( $id_token );
			$plan       = IdTokenClaims::plan_type( $id_token );
			$account_id = $data['tokens']['account_id'];
			$is_fedramp = IdTokenClaims::is_fedramp( $id_token );
			$id_expired = IdTokenClaims::is_expired( $id_token );
		}

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
			'connected'        => $connected,
			'email'            => $email,
			'account_id'       => $account_id,
			'plan_type'        => $plan,
			'is_fedramp'       => $is_fedramp,
			'id_token_expired' => $id_expired,
			'last_refresh'     => 0 !== $this->store->get_last_refresh_timestamp()
				? $this->store->get_last_refresh_timestamp()
				: null,
			'oauth_client_id'  => oauth_client_id(),
			'provider'         => array(
				'registered' => $has_registered,
				'configured' => $is_configured,
			),
		);
	}
}
