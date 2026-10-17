<?php
/**
 * Tests for retrying a request without max_output_tokens.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

require_once __DIR__ . '/includes/class-api-test-helpers.php';

/**
 * The route lists max_output_tokens as unsupported; a rejection of it retries once without it.
 */
class Test_Max_Output_Tokens_Retry extends Test_Case {

	/**
	 * Sends one prompt, with maxTokens set unless $max_tokens is null.
	 *
	 * @param int|null $max_tokens Max tokens, or null for none.
	 * @return string The generated text.
	 */
	private function send( ?int $max_tokens = 64 ): string {
		$config = new ModelConfig();
		if ( null !== $max_tokens ) {
			$config->setMaxTokens( $max_tokens );
		}
		$model  = Api_Test_Helpers::model( $config, new ChatGptAccountAuthentication( Api_Test_Helpers::connected_store() ) );
		$result = $model->generateTextResult( array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );
		return (string) $result->getCandidates()[0]->getMessage()->getParts()[0]->getText();
	}

	/**
	 * Returns the decoded body of each recorded request.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function bodies(): array {
		return array_map( array( Api_Test_Helpers::class, 'request_json' ), Http_Mock::requests() );
	}

	/**
	 * Builds an unsupported_capability error body for $param.
	 *
	 * @param string $param The rejected parameter.
	 * @return string
	 */
	private static function unsupported( string $param ): string {
		return (string) wp_json_encode(
			array(
				'error' => array(
					'code'    => 'subscription_sharing_unsupported_capability',
					'message' => 'Unsupported capability',
					'param'   => $param,
				),
			)
		);
	}

	/**
	 * An unsupported_capability error naming max_output_tokens retries without it.
	 */
	public function test_unsupported_capability_on_max_output_tokens_retries_without_it() {
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::unsupported( 'max_output_tokens' ) ) );
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );

		$this->assertSame( 'Hello, world!', $this->send() );

		$bodies = self::bodies();
		$this->assertCount( 2, $bodies );
		$this->assertSame( 64, $bodies[0]['max_output_tokens'] );
		$this->assertArrayNotHasKey( 'max_output_tokens', $bodies[1] );
		unset( $bodies[0]['max_output_tokens'] );
		$this->assertSame( $bodies[0], $bodies[1] );
	}

	/**
	 * A detail body naming max_output_tokens retries without it.
	 */
	public function test_detail_naming_max_output_tokens_retries_without_it() {
		Http_Mock::queue( Api_Test_Helpers::response( 400, '{"detail":"Unsupported parameter: max_output_tokens"}' ) );
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );

		$this->assertSame( 'Hello, world!', $this->send() );

		$bodies = self::bodies();
		$this->assertCount( 2, $bodies );
		$this->assertArrayNotHasKey( 'max_output_tokens', $bodies[1] );
	}

	/**
	 * A 400 naming another parameter is not retried.
	 */
	public function test_rejection_of_another_param_is_not_retried() {
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::unsupported( 'tools[0].type' ) ) );

		try {
			$this->send();
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'ChatGPT rejected a request feature: tools[0].type', $e->getMessage() );
		}
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * Without maxTokens there is nothing to drop, so the 400 is not retried.
	 */
	public function test_rejection_without_max_tokens_set_is_not_retried() {
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::unsupported( 'max_output_tokens' ) ) );

		try {
			$this->send( null );
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'ChatGPT rejected a request feature: max_output_tokens', $e->getMessage() );
		}
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * A second rejection after the retry surfaces the error instead of looping.
	 */
	public function test_second_rejection_surfaces_the_error() {
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::unsupported( 'max_output_tokens' ) ) );
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::unsupported( 'max_output_tokens' ) ) );

		try {
			$this->send();
			$this->fail( 'No exception' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'ChatGPT rejected a request feature: max_output_tokens', $e->getMessage() );
		}
		$this->assertCount( 2, Http_Mock::requests() );
	}

	/**
	 * A 401 before the rejection still refreshes, and the retry keeps the new token.
	 */
	public function test_retry_without_max_output_tokens_keeps_the_refreshed_token() {
		$store                 = Api_Test_Helpers::connected_store();
		$refresher             = new Api_Test_Refresher( $store );
		$refresher->on_refresh = static function () use ( $store ) {
			$store->update( array( 'access_token' => 'access-2' ) );
		};
		Http_Mock::queue( Api_Test_Helpers::response( 401, '{"detail":"Unauthorized"}' ) );
		Http_Mock::queue( Api_Test_Helpers::response( 400, self::unsupported( 'max_output_tokens' ) ) );
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
		$config = new ModelConfig();
		$config->setMaxTokens( 64 );

		Api_Test_Helpers::model( $config, new ChatGptAccountAuthentication( $store, $refresher ) )
			->generateTextResult( array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );

		$requests = Http_Mock::requests();
		$this->assertCount( 3, $requests );
		$this->assertSame( 'Bearer access-2', Api_Test_Helpers::request_headers( $requests[2] )['authorization'] );
		$this->assertArrayHasKey( 'max_output_tokens', Api_Test_Helpers::request_json( $requests[1] ) );
		$this->assertArrayNotHasKey( 'max_output_tokens', Api_Test_Helpers::request_json( $requests[2] ) );
	}
}
