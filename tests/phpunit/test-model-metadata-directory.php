<?php
/**
 * Tests for the models directory.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Cache\TransientCache;
use Halawa\ChatGptAiProvider\Metadata\ChatGptModelMetadataDirectory;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

require_once __DIR__ . '/includes/class-api-test-helpers.php';

/**
 * Normalises /v1/models and falls back only for connected sites.
 */
class Test_Model_Metadata_Directory extends Test_Case {

	/**
	 * Clears the cached model list after each test.
	 */
	public function tear_down() {
		Http_Mock::reset();
		( new ChatGptModelMetadataDirectory() )->invalidateCaches();
		parent::tear_down();
	}

	/**
	 * Builds a directory for $store with a fresh cache.
	 *
	 * @param TokenStore $store Store.
	 * @return ChatGptModelMetadataDirectory
	 */
	private function directory( TokenStore $store ): ChatGptModelMetadataDirectory {
		$directory = new ChatGptModelMetadataDirectory();
		$directory->invalidateCaches();
		$directory->setHttpTransporter( HttpTransporterFactory::createTransporter() );
		$directory->setRequestAuthentication( new ChatGptAccountAuthentication( $store ) );
		return $directory;
	}

	/**
	 * Returns the input modality sets a model advertises.
	 *
	 * @param ModelMetadata $model Model.
	 * @return list<list<string>>
	 */
	private function input_modalities( ModelMetadata $model ): array {
		foreach ( $model->getSupportedOptions() as $option ) {
			if ( $option->getName()->equals( OptionEnum::inputModalities() ) ) {
				return array_map(
					static function ( array $set ): array {
						return array_map( static fn ( ModalityEnum $m ): string => $m->value, $set );
					},
					$option->getSupportedValues()
				);
			}
		}
		return array();
	}

	/**
	 * Directory requests v1 models without query.
	 */
	public function test_directory_requests_v1_models_without_query() {
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		$this->directory( Api_Test_Helpers::connected_store() )->listModelMetadata();
		$request = Api_Test_Helpers::last_request();
		$this->assertSame( 'https://api.openai.com/v1/models', $request['url'] );
		$this->assertSame( 'Bearer access-1', Api_Test_Helpers::request_headers( $request )['authorization'] );
	}

	/**
	 * Only listed models in server order with display names.
	 */
	public function test_only_listed_models_in_server_order_with_display_names() {
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		$models = $this->directory( Api_Test_Helpers::connected_store() )->listModelMetadata();
		$this->assertSame( array( 'gpt-6-luna', 'gpt-5.6-terra', 'gpt-5.6-luna' ), array_map( static fn ( $m ) => $m->getId(), $models ) );
		$this->assertSame( 'GPT-6-Luna', $models[0]->getName() );
	}

	/**
	 * Image input follows input modalities and missing visibility is listed.
	 */
	public function test_image_input_follows_input_modalities_and_missing_visibility_is_listed() {
		$body = wp_json_encode(
			array(
				'models' => array(
					array(
						'slug'             => 'text-only',
						'input_modalities' => array( 'text' ),
					),
					array(
						'slug'             => 'with-image',
						'display_name'     => '',
						'visibility'       => 'list',
						'input_modalities' => array( 'text', 'image' ),
						'model_messages'   => array( 'instructions_template' => str_repeat( 'x', 5000 ) ),
					),
				),
			)
		);
		Http_Mock::queue( Api_Test_Helpers::response( 200, $body ) );
		$models = $this->directory( Api_Test_Helpers::connected_store() )->listModelMetadata();
		$this->assertSame( array( array( 'text' ) ), $this->input_modalities( $models[0] ) );
		$this->assertSame( array( array( 'text' ), array( 'text', 'image' ) ), $this->input_modalities( $models[1] ) );
		$this->assertSame( 'with-image', $models[1]->getName() );
		$this->assertStringNotContainsString( 'instructions_template', (string) wp_json_encode( $models[1]->toArray() ) );
	}

	/**
	 * Supported options follow the spec.
	 */
	public function test_supported_options_follow_the_spec() {
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		$model = $this->directory( Api_Test_Helpers::connected_store() )->listModelMetadata()[0];
		$names = array_map( static fn ( $o ) => $o->getName()->value, $model->getSupportedOptions() );
		$expected = array( 'systemInstruction', 'maxTokens', 'outputMimeType', 'outputSchema', 'functionDeclarations', 'customOptions', 'inputModalities', 'outputModalities', 'candidateCount', 'temperature', 'topP', 'stopSequences' );
		sort( $expected );
		sort( $names );
		$this->assertSame( $expected, $names );
	}

	/**
	 * Failed request on a connected site uses the bundled list.
	 */
	public function test_failed_request_on_a_connected_site_uses_the_bundled_list() {
		Http_Mock::queue( Api_Test_Helpers::response( 503, '{"detail":"Direct routing unavailable"}' ) );
		$models = $this->directory( Api_Test_Helpers::connected_store() )->listModelMetadata();
		$this->assertSame( ChatGptModelMetadataDirectory::BUNDLED_MODELS, array_map( static fn ( $m ) => $m->getId(), $models ) );
	}

	/**
	 * No listed model uses the filtered bundled list.
	 */
	public function test_no_listed_model_uses_the_filtered_bundled_list() {
		add_filter(
			'halawa_chatgpt_bundled_models',
			static function () {
				return array(
					'gpt-x',
					array(
						'id'                        => 'gpt-y',
						'model_family_display_name' => 'GPT Y',
					),
				);
			}
		);
		Http_Mock::queue( Api_Test_Helpers::response( 200, '{"models":[{"slug":"gpt-hidden","visibility":"hide"}]}' ) );
		$models = $this->directory( Api_Test_Helpers::connected_store() )->listModelMetadata();
		$this->assertSame( array( 'gpt-x', 'gpt-y' ), array_map( static fn ( $m ) => $m->getId(), $models ) );
		$this->assertSame( 'GPT Y', $models[1]->getName() );
	}

	/**
	 * A site with no record lists no models and sends nothing.
	 */
	public function test_disconnected_site_lists_no_models_and_sends_nothing() {
		try {
			$this->directory( new TokenStore() )->listModelMetadata();
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( ChatGptAccountAuthentication::NOT_CONNECTED, $e->getMessage() );
		}
		$this->assertSame( array(), Http_Mock::requests() );
	}

	/**
	 * A signed-out registration lists no models and sends nothing, like no record.
	 */
	public function test_signed_out_registration_lists_no_models_and_sends_nothing() {
		$store = new TokenStore();
		$store->save(
			array(
				'client_id'     => 'oaiapp_test',
				'subject'       => 'user_test',
				'access_token'  => 'fake-access',
				'refresh_token' => 'fake-refresh',
				'expires_at'    => time() + 3600,
			)
		);
		$store->sign_out();
		try {
			$this->directory( $store )->listModelMetadata();
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( ChatGptAccountAuthentication::NOT_CONNECTED, $e->getMessage() );
		}
		$this->assertSame( array(), Http_Mock::requests() );
	}

	/**
	 * A record that needs a new sign-in still lists models, without calling OpenAI.
	 */
	public function test_session_ended_lists_the_bundled_models_without_a_request() {
		$models = $this->directory( Api_Test_Helpers::connected_store( array( 'needs_signin' => true ) ) )->listModelMetadata();
		$this->assertSame( ChatGptModelMetadataDirectory::BUNDLED_MODELS, array_map( static fn ( $m ) => $m->getId(), $models ) );
		$this->assertSame( array(), Http_Mock::requests() );
	}

	/**
	 * Returns the ids of the models a fresh directory lists for $store.
	 *
	 * @param TokenStore $store Store.
	 * @return list<string>
	 */
	private function listed_ids( TokenStore $store ): array {
		return array_map( static fn ( $m ) => $m->getId(), $this->directory( $store )->listModelMetadata() );
	}

	/**
	 * Two reads within the hour make one /v1/models request.
	 */
	public function test_model_list_is_cached_for_an_hour() {
		$store = Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );

		$first  = $this->listed_ids( $store );
		$second = $this->listed_ids( $store );

		$this->assertSame( array( 'gpt-6-luna', 'gpt-5.6-terra', 'gpt-5.6-luna' ), $first );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, Http_Mock::requests() );
		$timeout = (int) get_option( '_transient_timeout_' . ChatGptModelMetadataDirectory::CACHE_KEY );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS - 60, $timeout );
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, $timeout );
	}

	/**
	 * Clearing the model cache, as sign-in and disconnect do, makes the next read fetch again.
	 */
	public function test_clearing_the_model_cache_fetches_again() {
		$store = Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		Http_Mock::queue( Api_Test_Helpers::response( 200, '{"models":[{"slug":"gpt-new","visibility":"list"}]}' ) );

		$this->listed_ids( $store );
		( new TransientCache() )->clear();

		$this->assertSame( array( 'gpt-new' ), $this->listed_ids( $store ) );
		$this->assertCount( 2, Http_Mock::requests() );
	}

	/**
	 * The Developer tools cache action clears the model list too.
	 */
	public function test_developer_tools_cache_action_clears_the_model_list() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$store = Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );
		Http_Mock::queue( Api_Test_Helpers::response( 200, '{"models":[{"slug":"gpt-new","visibility":"list"}]}' ) );

		$this->listed_ids( $store );
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'DELETE', '/ai-provider-for-chatgpt/v1/cache' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'gpt-new' ), $this->listed_ids( $store ) );
		$this->assertCount( 2, Http_Mock::requests() );
	}

	/**
	 * The bundled fallback after a failed request is not cached.
	 */
	public function test_bundled_fallback_is_not_cached() {
		$store = Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 503, '{"detail":"Direct routing unavailable"}' ) );
		Http_Mock::queue( Api_Test_Helpers::response( 200, self::fixture( 'models.json' ) ) );

		$this->assertSame( ChatGptModelMetadataDirectory::BUNDLED_MODELS, $this->listed_ids( $store ) );
		$this->assertSame( array( 'gpt-6-luna', 'gpt-5.6-terra', 'gpt-5.6-luna' ), $this->listed_ids( $store ) );
		$this->assertCount( 2, Http_Mock::requests() );
	}

	/**
	 * A record that needs a new sign-in lists the last known models without a request.
	 */
	public function test_session_ended_lists_the_last_known_models() {
		$store = Api_Test_Helpers::connected_store();
		Http_Mock::queue( Api_Test_Helpers::response( 200, '{"models":[{"slug":"gpt-known","visibility":"list"}]}' ) );
		$this->listed_ids( $store );
		$store->mark_needs_signin();

		$this->assertSame( array( 'gpt-known' ), $this->listed_ids( $store ) );
		$this->assertCount( 1, Http_Mock::requests() );
	}
}
