<?php
/**
 * Tests for the AI plugin Connector Approval hint.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Admin\ConnectorApproval;
use WordPress\AI\Connector_Approval\Approvals_Store;
use WordPress\AI\Connector_Approval\Http_Guard;

require_once __DIR__ . '/includes/class-api-test-helpers.php';
require_once __DIR__ . '/includes/ai-plugin/class-http-guard.php';
require_once __DIR__ . '/includes/ai-plugin/class-approvals-store.php';

/**
 * The settings payload names the plugins the AI plugin blocks from this connector.
 */
class Test_Connector_Approval extends Test_Case {

	/**
	 * Guard instance hooked for the test.
	 *
	 * @var Http_Guard|null
	 */
	private $guard;

	/**
	 * Resets the stand-in store.
	 */
	public function set_up() {
		parent::set_up();
		Approvals_Store::$pending  = array(
			'ai/ai.php::chatgpt'     => array(
				'caller_basename' => 'ai/ai.php',
				'caller_name'     => 'AI',
				'connector_id'    => 'chatgpt',
			),
			'ai/ai.php::openai'      => array(
				'caller_basename' => 'ai/ai.php',
				'caller_name'     => 'AI',
				'connector_id'    => 'openai',
			),
			'seo/seo.php::chatgpt'   => array(
				'caller_basename' => 'seo/seo.php',
				'caller_name'     => '',
				'connector_id'    => 'chatgpt',
			),
			'done/done.php::chatgpt' => array(
				'caller_basename' => 'done/done.php',
				'caller_name'     => 'Done',
				'connector_id'    => 'chatgpt',
			),
		);
		Approvals_Store::$approved = array( 'done/done.php' => array( 'chatgpt' => true ) );
	}

	/**
	 * Unhooks the guard.
	 */
	public function tear_down() {
		if ( $this->guard ) {
			remove_filter( 'pre_http_request', array( $this->guard, 'maybe_block_request' ), 5 );
		}
		parent::tear_down();
	}

	/**
	 * Nothing is reported while the experiment's guard is not hooked.
	 */
	public function test_no_hint_without_the_guard() {
		$this->assertNull( ConnectorApproval::pending() );
	}

	/**
	 * Unapproved callers of this connector are listed with the review page.
	 */
	public function test_lists_unapproved_callers_of_this_connector() {
		$this->guard = new Http_Guard();
		add_filter( 'pre_http_request', array( $this->guard, 'maybe_block_request' ), 5 );

		$this->assertSame(
			array(
				'callers' => array( 'AI', 'seo/seo.php' ),
				'url'     => admin_url( 'tools.php?page=ai-connector-approval' ),
			),
			ConnectorApproval::pending()
		);
	}

	/**
	 * No hint once every caller is approved.
	 */
	public function test_no_hint_when_all_approved() {
		$this->guard = new Http_Guard();
		add_filter( 'pre_http_request', array( $this->guard, 'maybe_block_request' ), 5 );
		Approvals_Store::$approved['ai/ai.php']   = array( 'chatgpt' => true );
		Approvals_Store::$approved['seo/seo.php'] = array( 'chatgpt' => true );

		$this->assertNull( ConnectorApproval::pending() );
	}

	/**
	 * The settings route returns the hint.
	 */
	public function test_settings_route_includes_the_hint() {
		$this->guard = new Http_Guard();
		add_filter( 'pre_http_request', array( $this->guard, 'maybe_block_request' ), 5 );
		Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/ai-provider-for-chatgpt/v1/settings' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'AI', 'seo/seo.php' ), $response->get_data()['approval']['callers'] );
	}
}
