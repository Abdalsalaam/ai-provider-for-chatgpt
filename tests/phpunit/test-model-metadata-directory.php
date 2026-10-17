<?php
/**
 * Tests for the models directory.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
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
	 * Disconnected site lists no models and sends nothing.
	 */
	public function test_disconnected_site_lists_no_models_and_sends_nothing() {
		foreach ( array( new TokenStore(), Api_Test_Helpers::connected_store( array( 'needs_signin' => true ) ) ) as $store ) {
			try {
				$this->directory( $store )->listModelMetadata();
				$this->fail( 'No exception' );
			} catch ( RuntimeException $e ) {
				$this->assertContains( $e->getMessage(), array( ChatGptAccountAuthentication::NOT_CONNECTED, ChatGptAccountAuthentication::SESSION_ENDED ) );
			}
			( new TokenStore() )->clear();
		}
		$this->assertSame( array(), Http_Mock::requests() );
	}
}
