<?php
/**
 * Tests for what an AI Client caller sees after the ChatGPT session ended.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;
use WordPress\AiClient\AiClient;

require_once __DIR__ . '/includes/class-api-test-helpers.php';

/**
 * The session-ended message reaches callers that let the SDK pick the model.
 */
class Test_Session_Ended extends Test_Case {

	/**
	 * Starts each test with no cached model list.
	 */
	public function set_up() {
		parent::set_up();
		ChatGptProvider::modelMetadataDirectory()->invalidateCaches();
	}

	/**
	 * Leaves no cached model list for the next test.
	 */
	public function tear_down() {
		ChatGptProvider::modelMetadataDirectory()->invalidateCaches();
		parent::tear_down();
	}

	/**
	 * A prompt without a model gets the session-ended message and sends nothing.
	 */
	public function test_prompt_without_a_model_gets_the_session_ended_message() {
		Api_Test_Helpers::connected_store( array( 'needs_signin' => true ) );

		foreach ( array( AiClient::prompt( 'Hi' ), AiClient::prompt( 'Hi' )->usingProvider( 'chatgpt' ) ) as $builder ) {
			try {
				$builder->generateText();
				$this->fail( 'No exception' );
			} catch ( Throwable $e ) {
				$this->assertSame( ChatGptAccountAuthentication::SESSION_ENDED, $e->getMessage() );
			}
		}
		$this->assertSame( array(), Http_Mock::requests() );
	}
}
