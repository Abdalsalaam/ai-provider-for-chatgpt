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
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Metadata\ChatGptModelMetadataDirectory;
use Halawa\ChatGptAiProvider\Models\ResponsesError;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;

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
	 * GET /diagnostics/remote-models: direct probe of GET /v1/models.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function remote_models(): WP_REST_Response {
		$result = array(
			'http_status'   => 0,
			'model_ids'     => array(),
			'text_only_ids' => array(),
			'raw_models'    => array(),
			'error'         => null,
		);

		try {
			$request = ( new ChatGptAccountAuthentication( $this->store ) )->authenticateRequest(
				new Request( HttpMethodEnum::GET(), ChatGptProvider::url( 'models' ) )
			);
		} catch ( Throwable $e ) {
			$result['error'] = $e->getMessage();
			return new WP_REST_Response( $result, 200 );
		}

		$response = wp_safe_remote_get(
			$request->getUri(),
			array(
				'headers' => ResponsesError::lower_headers( $request->getHeaders() ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			return new WP_REST_Response( $result, 200 );
		}

		$status                = (int) wp_remote_retrieve_response_code( $response );
		$body                  = (string) wp_remote_retrieve_body( $response );
		$result['http_status'] = $status;
		if ( $status < 200 || $status >= 300 ) {
			$request_id      = (string) wp_remote_retrieve_header( $response, 'x-oai-request-id' );
			$result['error'] = ResponsesError::message( $status, $body, '' !== $request_id ? array( 'x-oai-request-id' => $request_id ) : array() );
			return new WP_REST_Response( $result, 200 );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			$result['error'] = 'Response was not valid JSON.';
			return new WP_REST_Response( $result, 200 );
		}
		foreach ( ChatGptModelMetadataDirectory::normalise_entries( $decoded ) as $entry ) {
			$result['model_ids'][] = $entry['id'];
			if ( $entry['listed'] ) {
				$result['text_only_ids'][] = $entry['id'];
			}
			$result['raw_models'][] = array(
				'id'                        => $entry['id'],
				'slug'                      => $entry['id'],
				'name'                      => $entry['name'],
				'model_family_display_name' => null,
				'passes_text_filter'        => $entry['listed'],
			);
		}
		return new WP_REST_Response( $result, 200 );
	}
}
