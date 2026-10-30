<?php
/**
 * SettingsController class file.
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
use Halawa\ChatGptAiProvider\Admin\ConnectorApproval;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;
use Halawa\ChatGptAiProvider\Settings\ModelSettings;

/**
 * REST controller for the default model and reasoning effort settings.
 *
 * @since 1.1.0
 */
class SettingsController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 */
	public function __construct() {
		$this->namespace = 'ai-provider-for-chatgpt/v1';
		$this->rest_base = 'settings';
	}

	/**
	 * Registers the REST routes.
	 *
	 * @since 1.1.0
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
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permissions' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'permissions' ),
					'args'                => array(
						'default_model'    => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && ( '' === $value || '' !== ModelSettings::sanitize_model( $value ) );
							},
						),
						'reasoning_effort' => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => static function ( $value ) {
								return is_string( $value ) && ( '' === $value || '' !== ModelSettings::sanitize_effort( $value ) );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Capability check.
	 *
	 * @since 1.1.0
	 *
	 * @return bool|WP_Error
	 */
	public function permissions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage the ChatGPT settings.', 'ai-provider-for-chatgpt' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * GET /settings: the saved values and the models to choose from.
	 *
	 * @since 1.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return new WP_REST_Response( $this->payload(), 200 );
	}

	/**
	 * POST /settings: saves both values.
	 *
	 * A model that is not listed now is still saved; it is ignored until it is listed.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		ModelSettings::save( (string) $request->get_param( 'default_model' ), (string) $request->get_param( 'reasoning_effort' ) );
		return new WP_REST_Response( $this->payload(), 200 );
	}

	/**
	 * Builds the response body.
	 *
	 * @since 1.1.0
	 *
	 * @return array{default_model: string, reasoning_effort: string, models: list<array{id: string, name: string, efforts: list<string>|null, default_effort: string|null}>, approval: array{callers: list<string>, url: string}|null}
	 */
	private function payload(): array {
		// Listing first stores the efforts of a fresh model list.
		$listed  = $this->listed_models();
		$efforts = ModelSettings::model_efforts();
		$models  = array();
		foreach ( $listed as $id => $name ) {
			$models[] = array(
				'id'             => $id,
				'name'           => $name,
				'efforts'        => $efforts[ $id ]['efforts'] ?? null,
				'default_effort' => $efforts[ $id ]['default'] ?? null,
			);
		}
		return array(
			'default_model'    => ModelSettings::default_model(),
			'reasoning_effort' => ModelSettings::reasoning_effort(),
			'models'           => $models,
			'approval'         => ConnectorApproval::pending(),
		);
	}

	/**
	 * Returns the listed model names keyed by id, empty when the site is not connected.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string>
	 */
	private function listed_models(): array {
		if ( ! class_exists( AiClient::class ) ) {
			return array();
		}
		try {
			$listed = ChatGptProvider::modelMetadataDirectory()->listModelMetadata();
		} catch ( Throwable $e ) {
			return array();
		}
		$names = array();
		foreach ( $listed as $model ) {
			$names[ $model->getId() ] = $model->getName();
		}
		return $names;
	}
}
