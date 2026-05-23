<?php
/**
 * CacheController class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Rest;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for clearing the AI client's cached model list.
 *
 * @since 0.1.0
 */
class CacheController extends WP_REST_Controller {

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		$this->namespace = 'ai-provider-for-chatgpt/v1';
		$this->rest_base = 'cache';
	}

	/**
	 * Registers the REST route.
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
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear' ),
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
				__( 'You do not have permission to clear the cache.', 'ai-provider-for-chatgpt' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * DELETE /cache — clears the AI client transients.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response
	 */
	public function clear(): WP_REST_Response {
		global $wpdb;
		// Bulk transient cleanup: there is no WP API for "delete every transient
		// matching a prefix", and looping delete_transient() would require first
		// enumerating keys (which has no public API either). Direct LIKE on
		// wp_options is the established WordPress pattern for this case.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prefix-matched bulk delete; no caching equivalent.
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ai_client_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ai_client_' ) . '%'
			)
		);
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush_group( 'transient' );
		}
		return new WP_REST_Response( array( 'deleted' => $deleted ), 200 );
	}
}
