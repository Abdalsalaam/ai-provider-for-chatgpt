<?php
/**
 * Tests for the default model and reasoning effort settings.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Cache\ModelCache;
use Halawa\ChatGptAiProvider\Metadata\ChatGptModelMetadataDirectory;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;
use Halawa\ChatGptAiProvider\Settings\ModelSettings;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

use const Halawa\ChatGptAiProvider\API_BASE_URL;
use const Halawa\ChatGptAiProvider\TOKEN_ENDPOINT;

require_once __DIR__ . '/includes/class-api-test-helpers.php';
require_once __DIR__ . '/includes/class-oauth-test-helpers.php';

/**
 * The site's default model is picked first and its reasoning effort is sent unless the caller sets one.
 */
class Test_Model_Settings extends Test_Case {

	/**
	 * Administrator id.
	 *
	 * @var int
	 */
	private static $admin;

	/**
	 * Creates the administrator.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Starts each test with no settings read and a clean model list.
	 */
	public function set_up() {
		parent::set_up();
		ModelSettings::reset();
		ChatGptProvider::modelMetadataDirectory()->invalidateCaches();
	}

	/**
	 * Leaves no cached model list for the next test.
	 */
	public function tear_down() {
		ChatGptProvider::modelMetadataDirectory()->invalidateCaches();
		ModelSettings::reset();
		parent::tear_down();
	}

	/**
	 * Sends one prompt and returns the decoded body of each request.
	 *
	 * @param ModelConfig|null $config Config.
	 * @return list<array<string, mixed>>
	 */
	private function send( ?ModelConfig $config = null ): array {
		Api_Test_Helpers::model( $config ?? new ModelConfig(), new ChatGptAccountAuthentication( Api_Test_Helpers::connected_store() ) )
			->generateTextResult( array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );
		return array_map( array( Api_Test_Helpers::class, 'request_json' ), Http_Mock::requests() );
	}

	/**
	 * Builds an error body on $param with $code.
	 *
	 * @param string $param Rejected parameter.
	 * @param string $code  Error code.
	 * @return string
	 */
	private static function rejection( string $param, string $code ): string {
		return (string) wp_json_encode(
			array(
				'error' => array(
					'code'    => $code,
					'message' => 'Rejected',
					'param'   => $param,
				),
			)
		);
	}

	/**
	 * Lists models through a fresh directory for $store.
	 *
	 * @param TokenStore $store Store.
	 * @return list<string>
	 */
	private function listed_ids( TokenStore $store ): array {
		$directory = new ChatGptModelMetadataDirectory();
		$directory->setHttpTransporter( HttpTransporterFactory::createTransporter() );
		$directory->setRequestAuthentication( new ChatGptAccountAuthentication( $store ) );
		return array_map( static fn ( $m ) => $m->getId(), $directory->listModelMetadata() );
	}

	/**
	 * Without a saved effort the body has no reasoning key.
	 */
	public function test_no_effort_sends_no_reasoning() {
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
		$this->assertArrayNotHasKey( 'reasoning', $this->send()[0] );
	}

	/**
	 * A saved effort is sent when the model's efforts are unknown.
	 */
	public function test_saved_effort_is_sent() {
		ModelSettings::save( '', 'low' );
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
		$this->assertSame( array( 'effort' => 'low' ), $this->send()[0]['reasoning'] );
	}

	/**
	 * An effort the listed model does not support is not sent.
	 */
	public function test_effort_the_model_does_not_list_is_not_sent() {
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		$this->listed_ids( Api_Test_Helpers::connected_store() );
		ModelSettings::save( '', 'ultra' );

		Http_Mock::reset();
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
		$this->assertArrayNotHasKey( 'reasoning', $this->send()[0] );
		$this->assertSame( array( 'low', 'medium', 'high', 'xhigh', 'max' ), ModelSettings::model_efforts()['gpt-6-luna']['efforts'] );
		$this->assertSame( 'medium', ModelSettings::model_efforts()['gpt-6-luna']['default'] );
	}

	/**
	 * A caller's effort wins, and a caller's reasoning object without one gets the site's.
	 */
	public function test_caller_reasoning_takes_precedence() {
		ModelSettings::save( '', 'low' );
		$config = new ModelConfig();
		$config->setCustomOption( 'reasoning', array( 'effort' => 'high' ) );
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
		$this->assertSame( array( 'effort' => 'high' ), $this->send( $config )[0]['reasoning'] );

		Http_Mock::reset();
		$config = new ModelConfig();
		$config->setCustomOption( 'reasoning', array( 'summary' => 'auto' ) );
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
		$this->assertSame(
			array(
				'summary' => 'auto',
				'effort'  => 'low',
			),
			$this->send( $config )[0]['reasoning']
		);
	}

	/**
	 * A rejected effort is retried once without it and skipped on later prompts.
	 */
	public function test_rejected_effort_is_retried_without_it_and_remembered() {
		ModelSettings::save( '', 'low' );
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::rejection( 'reasoning.effort', 'unsupported_value' ) ) );
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );

		$bodies = $this->send();
		$this->assertCount( 2, $bodies );
		$this->assertSame( array( 'effort' => 'low' ), $bodies[0]['reasoning'] );
		$this->assertArrayNotHasKey( 'reasoning', $bodies[1] );

		Http_Mock::reset();
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
		$this->assertArrayNotHasKey( 'reasoning', $this->send()[0] );
	}

	/**
	 * A rejection of a caller's own reasoning is not retried.
	 */
	public function test_rejection_of_caller_reasoning_is_not_retried() {
		$config = new ModelConfig();
		$config->setCustomOption( 'reasoning', array( 'effort' => 'none' ) );
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::rejection( 'reasoning.effort', 'unsupported_value' ) ) );
		try {
			$this->send( $config );
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertCount( 1, Http_Mock::requests() );
		}
	}

	/**
	 * A 401 then a rejection refreshes, drops the effort, and does not add it back.
	 */
	public function test_401_then_rejection_keeps_both_retries() {
		ModelSettings::save( '', 'low' );
		Http_Mock::queue( Http_Mock::json( array( 'detail' => 'Unauthorized' ), 401 ) );
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response() ) );
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::rejection( 'reasoning.effort', 'invalid_value' ) ) );
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );

		Api_Test_Helpers::model( new ModelConfig(), new ChatGptAccountAuthentication( Api_Test_Helpers::connected_store() ) )
			->generateTextResult( array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );

		$requests = Http_Mock::requests();
		$this->assertSame(
			array( API_BASE_URL . '/responses', TOKEN_ENDPOINT, API_BASE_URL . '/responses', API_BASE_URL . '/responses' ),
			array_column( $requests, 'url' )
		);
		$this->assertArrayHasKey( 'reasoning', Api_Test_Helpers::request_json( $requests[2] ) );
		$this->assertArrayNotHasKey( 'reasoning', Api_Test_Helpers::request_json( $requests[3] ) );
		$this->assertSame( 'Bearer at_2', Api_Test_Helpers::request_headers( $requests[3] )['authorization'] );
	}

	/**
	 * A 400 on another parameter keeps the effort and is not retried.
	 */
	public function test_other_rejection_is_not_retried() {
		ModelSettings::save( '', 'low' );
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::rejection( 'tools[0].type', 'invalid_value' ) ) );
		try {
			$this->send();
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertCount( 1, Http_Mock::requests() );
		}
	}

	/**
	 * The default model is listed first on the listed, cached, bundled and session-ended paths.
	 */
	public function test_default_model_is_listed_first_on_every_path() {
		$store = Api_Test_Helpers::connected_store();
		ModelSettings::save( 'gpt-5.6-luna', '' );

		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		$this->assertSame( array( 'gpt-5.6-luna', 'gpt-6-luna', 'gpt-5.6-terra' ), $this->listed_ids( $store ) );

		// Cached transient, after the AI Client's own copy is dropped.
		ChatGptProvider::modelMetadataDirectory()->invalidateCaches();
		$this->assertSame( array( 'gpt-5.6-luna', 'gpt-6-luna', 'gpt-5.6-terra' ), $this->listed_ids( $store ) );

		// Bundled list after a failed request.
		ModelCache::clear();
		Http_Mock::queue( Api_Test_Helpers::response( 503, '{"detail":"Unavailable"}' ) );
		$this->assertSame( 'gpt-5.6-luna', $this->listed_ids( $store )[0] );

		// Session ended: bundled list without a request.
		ModelCache::clear();
		$this->assertSame( 'gpt-5.6-luna', $this->listed_ids( Api_Test_Helpers::connected_store( array( 'needs_signin' => true ) ) )[0] );
	}

	/**
	 * Saving a new default drops the AI Client's cached list, and an unlisted default is ignored.
	 */
	public function test_saving_the_default_reorders_without_a_cache_clear() {
		$store = Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		$this->assertSame( 'gpt-6-luna', $this->listed_ids( $store )[0] );

		ModelSettings::save( 'gpt-5.6-terra', '' );
		$this->assertSame( 'gpt-5.6-terra', $this->listed_ids( $store )[0] );

		ModelSettings::save( 'gpt-gone', '' );
		$this->assertSame( array( 'gpt-6-luna', 'gpt-5.6-terra', 'gpt-5.6-luna' ), $this->listed_ids( $store ) );

		ModelSettings::save( '', '' );
		$this->assertFalse( get_option( ModelSettings::DEFAULT_MODEL_OPTION ) );
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * Dispatches a settings request.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $params Body params.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, array $params = array() ): WP_REST_Response {
		global $wp_rest_server;
		$wp_rest_server = null;
		$request        = new WP_REST_Request( $method, '/ai-provider-for-chatgpt/v1/settings' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The REST route lists models with efforts, saves valid values and refuses others.
	 */
	public function test_rest_route() {
		Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->dispatch( 'GET' )->get_status() );

		wp_set_current_user( self::$admin );
		$data = $this->dispatch( 'GET' )->get_data();
		$this->assertSame( '', $data['default_model'] );
		$this->assertSame( '', $data['reasoning_effort'] );
		$this->assertSame( array( 'gpt-6-luna', 'gpt-5.6-terra', 'gpt-5.6-luna' ), array_column( $data['models'], 'id' ) );
		$this->assertSame( 'ultra', end( $data['models'][1]['efforts'] ) );

		$saved = $this->dispatch(
			'POST',
			array(
				'default_model'    => 'gpt-5.6-luna',
				'reasoning_effort' => 'low',
			)
		);
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'gpt-5.6-luna', $saved->get_data()['default_model'] );
		$this->assertSame( 'low', get_option( ModelSettings::EFFORT_OPTION ) );

		$bad = $this->dispatch(
			'POST',
			array(
				'default_model'    => '<script>',
				'reasoning_effort' => 'Low!',
			)
		);
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'low', get_option( ModelSettings::EFFORT_OPTION ) );
	}

	/**
	 * Uninstall removes the settings.
	 */
	public function test_uninstall_removes_the_settings() {
		ModelSettings::save( 'gpt-6-luna', 'low' );
		update_option( ModelSettings::MODEL_EFFORTS_OPTION, array( 'x' => array() ), false );
		ModelSettings::mark_rejected( 'gpt-6-luna' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ai-provider-for-chatgpt/ai-provider-for-chatgpt.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( ModelSettings::DEFAULT_MODEL_OPTION ) );
		$this->assertFalse( get_option( ModelSettings::EFFORT_OPTION ) );
		$this->assertFalse( get_option( ModelSettings::MODEL_EFFORTS_OPTION ) );
	}
}
