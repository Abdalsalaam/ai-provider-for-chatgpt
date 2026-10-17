<?php
/**
 * Tests for ResponsesError.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Models\ResponsesError;

require_once __DIR__ . '/includes/class-api-test-helpers.php';

/**
 * Maps /responses and /models error bodies to messages.
 */
class Test_Responses_Error extends Test_Case {

	/**
	 * Detail body is prefixed.
	 */
	public function test_detail_body_is_prefixed() {
		$body = self::fixture( 'error-temperature.json' );
		$this->assertSame( 'ChatGPT: Unsupported parameter: temperature', ResponsesError::message( 400, $body, array() ) );
	}

	/**
	 * Request id is appended to every message.
	 */
	public function test_request_id_is_appended_to_every_message() {
		$headers = array( 'x-oai-request-id' => 'req_123' );
		$this->assertSame(
			'ChatGPT: System messages are not allowed (request id req_123)',
			ResponsesError::message( 400, self::fixture( 'error-system-item.json' ), $headers )
		);
		$this->assertSame( 'ChatGPT returned HTTP 500. (request id req_123)', ResponsesError::message( 500, '', $headers ) );
	}

	/**
	 * Usage limit points to the usage page.
	 */
	public function test_usage_limit_points_to_the_usage_page() {
		$body = wp_json_encode(
			array(
				'error' => array(
					'code'    => 'subscription_sharing_usage_limit_exceeded',
					'message' => 'x',
				),
			)
		);
		$this->assertSame(
			'Your ChatGPT plan usage limit was reached. Review it at https://chatgpt.com/settings/usage',
			ResponsesError::message( 429, $body, array() )
		);
	}

	/**
	 * Plan codes have fixed messages.
	 */
	public function test_plan_codes_have_fixed_messages() {
		$cases = array(
			'subscription_sharing_user_not_eligible' => 'This ChatGPT account is not eligible for plan usage.',
			'subscription_sharing_usage_unavailable' => 'ChatGPT could not check your usage, try again shortly.',
			'subscription_sharing_user_unavailable'  => 'ChatGPT could not check your usage, try again shortly.',
		);
		foreach ( $cases as $code => $expected ) {
			$body = wp_json_encode(
				array(
					'error' => array(
						'code'    => $code,
						'message' => 'server text',
					),
				)
			);
			$this->assertSame( $expected, ResponsesError::message( 403, $body, array() ), $code );
		}
	}

	/**
	 * Unsupported capability names the param.
	 */
	public function test_unsupported_capability_names_the_param() {
		$body = wp_json_encode(
			array(
				'error' => array(
					'code'    => 'subscription_sharing_unsupported_capability',
					'message' => 'Not supported',
					'param'   => 'tools[0].type',
				),
			)
		);
		$this->assertSame( 'ChatGPT rejected a request feature: tools[0].type', ResponsesError::message( 400, $body, array() ) );
	}

	/**
	 * Unknown code keeps message and code.
	 */
	public function test_unknown_code_keeps_message_and_code() {
		$body = wp_json_encode(
			array(
				'error' => array(
					'code'    => 'missing_required_parameter',
					'message' => "Missing required parameter: 'input[0].role'.",
				),
			)
		);
		$this->assertSame(
			"ChatGPT: Missing required parameter: 'input[0].role'. (missing_required_parameter)",
			ResponsesError::message( 400, $body, array() )
		);
		$this->assertSame( 'missing_required_parameter', ResponsesError::code( $body ) );
	}

	/**
	 * Non JSON body gives status only.
	 */
	public function test_non_json_body_gives_status_only() {
		$html = '<html><body><h1>502 Bad Gateway</h1></body></html>';
		$this->assertSame(
			'ChatGPT returned HTTP 502. (request id req_9)',
			ResponsesError::message( 502, $html, array( 'x-oai-request-id' => 'req_9' ) )
		);
		$this->assertSame( 'ChatGPT returned HTTP 502.', ResponsesError::message( 502, '', array() ) );
		$this->assertNull( ResponsesError::code( $html ) );
	}

	/**
	 * Lower headers flattens lists and case.
	 */
	public function test_lower_headers_flattens_lists_and_case() {
		$this->assertSame(
			array(
				'x-oai-request-id' => 'req_1',
				'content-type'     => 'application/json',
			),
			ResponsesError::lower_headers(
				array(
					'X-OAI-Request-Id' => array( 'req_1' ),
					'Content-Type'     => 'application/json',
				)
			)
		);
	}
}
