<?php
/**
 * DiagnosticsController class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Rest;

use Throwable;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;
use WordPress\AiClient\AiClient;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;

use function Halawa\ChatGptAiProvider\codex_client_version;

/**
 * REST controller for the React diagnostics panel.
 *
 * Split into a fast read (registry + SDK model list) and a slow "remote probe"
 * route the UI calls only when the user clicks the button, so the panel does
 * not block for up to 15 seconds on page load.
 *
 * @since 0.1.0
 */
class DiagnosticsController extends WP_REST_Controller {

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
		$this->store     = $store;
		$this->namespace = 'ai-provider-for-chatgpt/v1';
		$this->rest_base = 'diagnostics';
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
					'callback'            => array( $this, 'fast_checks' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/remote-models',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'remote_models' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
			)
		);
	}

	/**
	 * Capability check.
	 *
	 * @since 0.1.0
	 *
	 * @return bool|WP_Error
	 */
	public function permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view diagnostics.', 'ai-provider-for-chatgpt' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * GET /diagnostics — fast registry + SDK model list checks.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function fast_checks(): WP_REST_Response {
		$has_provider     = false;
		$is_configured    = false;
		$registry_error   = null;
		try {
			$registry      = AiClient::defaultRegistry();
			$has_provider  = $registry->hasProvider( 'chatgpt' );
			$is_configured = $has_provider && $registry->isProviderConfigured( 'chatgpt' );
		} catch ( Throwable $e ) {
			$registry_error = $e->getMessage();
		}

		$model_count = 0;
		$model_ids   = array();
		$sdk_error   = null;
		try {
			$models      = ChatGptProvider::modelMetadataDirectory()->listModelMetadata();
			$model_count = count( $models );
			$model_ids   = array_slice(
				array_map( static fn ( $m ) => $m->getId(), $models ),
				0,
				5
			);
		} catch ( Throwable $e ) {
			$sdk_error = $e->getMessage();
		}

		return new WP_REST_Response(
			array(
				'registry'   => array(
					'has'        => $has_provider,
					'configured' => $is_configured,
					'error'      => $registry_error,
				),
				'sdk_models' => array(
					'count'  => $model_count,
					'sample' => $model_ids,
					'error'  => $sdk_error,
				),
			),
			200
		);
	}

	/**
	 * GET /diagnostics/remote-models — slow direct probe of the Codex backend.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function remote_models(): WP_REST_Response {
		$access_token = (string) $this->store->get_access_token();
		$account_id   = (string) $this->store->get_account_id();

		if ( '' === $access_token || '' === $account_id ) {
			return new WP_REST_Response(
				array(
					'http_status' => 0,
					'model_ids'   => array(),
					'raw_keys'    => array(),
					'error'       => __( 'Not connected — import an auth bundle first.', 'ai-provider-for-chatgpt' ),
				),
				200
			);
		}

		$client_version = codex_client_version();
		$response       = wp_remote_get(
			'https://chatgpt.com/backend-api/codex/models?client_version=' . rawurlencode( $client_version ),
			array(
				'headers' => array(
					'Authorization'      => 'Bearer ' . $access_token,
					'ChatGPT-Account-ID' => $account_id,
					'originator'         => 'codex_cli_rs',
					'User-Agent'         => 'codex_cli_rs/' . $client_version . ' (WordPress; ai-provider-for-chatgpt)',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_REST_Response(
				array(
					'http_status' => 0,
					'model_ids'   => array(),
					'raw_keys'    => array(),
					'error'       => $response->get_error_message(),
				),
				200
			);
		}

		$status         = (int) wp_remote_retrieve_response_code( $response );
		$body           = (string) wp_remote_retrieve_body( $response );
		$decoded        = json_decode( $body, true );
		$model_ids      = array();
		$text_only_ids  = array();
		$raw_models     = array();
		$raw_keys       = array();
		$error          = null;

		if ( ! is_array( $decoded ) ) {
			$error = 'Response was not valid JSON: ' . substr( $body, 0, 200 );
		} else {
			$raw_keys = array_keys( $decoded );
			$entries  = array();
			if ( isset( $decoded['models'] ) && is_array( $decoded['models'] ) ) {
				$entries = $decoded['models'];
			} elseif ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				$entries = $decoded['data'];
			}

			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				// Match the directory's id resolution: id → slug → name.
				$id = $entry['id'] ?? ( $entry['slug'] ?? ( $entry['name'] ?? null ) );
				if ( ! is_string( $id ) || '' === $id ) {
					continue;
				}
				$model_ids[]  = $id;
				$raw_models[] = array(
					'id'                        => $id,
					'slug'                      => is_string( $entry['slug'] ?? null ) ? $entry['slug'] : null,
					'name'                      => is_string( $entry['name'] ?? null ) ? $entry['name'] : null,
					'model_family_display_name' => is_string( $entry['model_family_display_name'] ?? null )
						? $entry['model_family_display_name']
						: null,
					'passes_text_filter'        => self::passes_text_filter( $id ),
				);
				if ( self::passes_text_filter( $id ) ) {
					$text_only_ids[] = $id;
				}
			}
		}

		return new WP_REST_Response(
			array(
				'http_status'   => $status,
				'model_ids'     => $model_ids,
				'text_only_ids' => $text_only_ids,
				'raw_keys'      => $raw_keys,
				'raw_models'    => $raw_models,
				'error'         => $error,
			),
			200
		);
	}

	/**
	 * Mirrors ChatGptModelMetadataDirectory::isTextModel so the diagnostics
	 * panel can show which entries the SDK would expose.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_id The model id.
	 * @return bool True if the model passes the text-generation filter.
	 */
	private static function passes_text_filter( string $model_id ): bool {
		$is_candidate = str_starts_with( $model_id, 'gpt-' )
			|| str_starts_with( $model_id, 'o1' )
			|| str_starts_with( $model_id, 'o3' )
			|| str_starts_with( $model_id, 'o4' )
			|| 'codex-mini-latest' === $model_id;
		if ( ! $is_candidate ) {
			return false;
		}
		return ! str_contains( $model_id, '-instruct' )
			&& ! str_contains( $model_id, '-realtime' )
			&& ! str_contains( $model_id, '-transcribe' )
			&& ! str_contains( $model_id, '-audio' )
			&& ! str_contains( $model_id, '-tts' );
	}
}
