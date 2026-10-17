<?php
/**
 * Tests for the diagnostics probe.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;

require_once __DIR__ . '/includes/class-api-test-helpers.php';

/**
 * Pins the /diagnostics/remote-models route.
 */
class Test_Diagnostics_Controller extends Test_Case {

	/**
	 * Runs each test as an administrator.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Dispatches the probe route.
	 *
	 * @return WP_REST_Response
	 */
	private function probe(): WP_REST_Response {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/ai-provider-for-chatgpt/v1/diagnostics/remote-models' ) );
	}

	/**
	 * Subscriber is refused.
	 */
	public function test_subscriber_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->probe()->get_status() );
	}

	/**
	 * Probe hits v1 models and returns the agreed shape.
	 */
	public function test_probe_hits_v1_models_and_returns_the_agreed_shape() {
		Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		$data = $this->probe()->get_data();

		$request = Api_Test_Helpers::last_request();
		$this->assertSame( 'https://api.openai.com/v1/models', $request['url'] );
		$this->assertSame( 'Bearer access-1', Api_Test_Helpers::request_headers( $request )['authorization'] );

		$this->assertSame( array( 'http_status', 'model_ids', 'text_only_ids', 'raw_models', 'error' ), array_keys( $data ) );
		$this->assertSame( 200, $data['http_status'] );
		$this->assertSame( array( 'gpt-6-luna', 'gpt-reserve', 'gpt-5.6-terra', 'gpt-5.6-luna', 'codex-auto-review' ), $data['model_ids'] );
		$this->assertSame( array( 'gpt-6-luna', 'gpt-5.6-terra', 'gpt-5.6-luna' ), $data['text_only_ids'] );
		$this->assertSame(
			array(
				'id'                        => 'gpt-reserve',
				'slug'                      => 'gpt-reserve',
				'name'                      => 'GPT-Reserve',
				'model_family_display_name' => null,
				'passes_text_filter'        => false,
			),
			$data['raw_models'][1]
		);
		$this->assertNull( $data['error'] );
	}

	/**
	 * Not connected reports without a request.
	 */
	public function test_not_connected_reports_without_a_request() {
		$data = $this->probe()->get_data();
		$this->assertSame( 0, $data['http_status'] );
		$this->assertSame( ChatGptAccountAuthentication::NOT_CONNECTED, $data['error'] );
		$this->assertSame( array(), Http_Mock::requests() );
	}

	/**
	 * Error status uses the error mapper.
	 */
	public function test_error_status_uses_the_error_mapper() {
		Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 403, '{"detail":"Serving region not supported"}', array( 'x-oai-request-id' => 'req_5' ) ) );
		$data = $this->probe()->get_data();
		$this->assertSame( 403, $data['http_status'] );
		$this->assertSame( 'ChatGPT: Serving region not supported (request id req_5)', $data['error'] );
		$this->assertSame( array(), $data['model_ids'] );
	}

	/**
	 * Network failure reports the transport error.
	 */
	public function test_network_failure_reports_the_transport_error() {
		Api_Test_Helpers::connected_store();
		Http_Mock::queue(
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
			}
		);
		$data = $this->probe()->get_data();
		$this->assertSame( 0, $data['http_status'] );
		$this->assertSame( 'cURL error 6: Could not resolve host', $data['error'] );
	}
}
