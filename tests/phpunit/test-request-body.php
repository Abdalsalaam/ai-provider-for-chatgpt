<?php
/**
 * Tests for the /responses request body.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

require_once __DIR__ . '/includes/class-api-test-helpers.php';

/**
 * Pins the body the route accepts.
 */
class Test_Request_Body extends Test_Case {

	/**
	 * Sends $messages with $config and returns the decoded body that went out.
	 *
	 * @param ModelConfig $config   Config.
	 * @param array       $messages Messages.
	 * @return array<string, mixed>
	 */
	private function body_for( ModelConfig $config, array $messages = array() ): array {
		Http_Mock::queue( Http_Mock::sse( self::fixture( 'stream-plain.sse' ) ) );
		$model = Api_Test_Helpers::model( $config, new ChatGptAccountAuthentication( Api_Test_Helpers::connected_store() ) );
		$model->generateTextResult( array() !== $messages ? $messages : array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );
		return Api_Test_Helpers::request_json( Api_Test_Helpers::last_request() );
	}

	/**
	 * Minimal body has only the required keys.
	 */
	public function test_minimal_body_has_only_the_required_keys() {
		$body = $this->body_for( new ModelConfig() );
		$this->assertSame( array( 'model', 'input', 'store', 'stream', 'prompt_cache_key' ), array_keys( $body ) );
		$this->assertSame( 'gpt-6-luna', $body['model'] );
		$this->assertFalse( $body['store'] );
		$this->assertTrue( $body['stream'] );
		$this->assertSame( hash( 'sha256', 'gpt-6-luna||' ), $body['prompt_cache_key'] );
		$this->assertSame(
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => 'Hi',
						),
					),
				),
			),
			$body['input']
		);
	}

	/**
	 * System instruction goes to instructions never to an item.
	 */
	public function test_system_instruction_goes_to_instructions_never_to_an_item() {
		$config = new ModelConfig();
		$config->setSystemInstruction( 'Be brief.' );
		$body = $this->body_for(
			$config,
			array(
				new UserMessage( array( new MessagePart( 'Hi' ) ) ),
				new ModelMessage( array( new MessagePart( 'Hello' ) ) ),
				new UserMessage( array( new MessagePart( 'Again' ) ) ),
			)
		);
		$this->assertSame( 'Be brief.', $body['instructions'] );
		$this->assertSame( array( 'user', 'assistant', 'user' ), array_column( $body['input'], 'role' ) );
		$this->assertSame( 'output_text', $body['input'][1]['content'][0]['type'] );
	}

	/**
	 * Tools are top level functions with auto choice and no parallel calls.
	 */
	public function test_tools_are_top_level_functions_with_auto_choice_and_no_parallel_calls() {
		$config = new ModelConfig();
		$config->setFunctionDeclarations(
			array(
				new FunctionDeclaration(
					'get_weather',
					'Weather for a city',
					array(
						'type'       => 'object',
						'properties' => array( 'city' => array( 'type' => 'string' ) ),
					)
				),
				new FunctionDeclaration( 'add', 'Adds', null ),
			)
		);
		$body = $this->body_for( $config );
		$this->assertSame( 'auto', $body['tool_choice'] );
		$this->assertFalse( $body['parallel_tool_calls'] );
		$this->assertSame( array( 'function', 'function' ), array_column( $body['tools'], 'type' ) );
		$this->assertSame( array( 'get_weather', 'add' ), array_column( $body['tools'], 'name' ) );
		$this->assertArrayNotHasKey( 'strict', $body['tools'][0] );
		$this->assertSame( hash( 'sha256', 'gpt-6-luna||add,get_weather' ), $body['prompt_cache_key'] );
	}

	/**
	 * Function round trip items.
	 */
	public function test_function_round_trip_items() {
		$body = $this->body_for(
			new ModelConfig(),
			array(
				new UserMessage( array( new MessagePart( 'Weather in Paris?' ) ) ),
				new ModelMessage( array( new MessagePart( new FunctionCall( 'call_1', 'get_weather', array( 'city' => 'Paris' ) ) ) ) ),
				new UserMessage( array( new MessagePart( new FunctionResponse( 'call_1', 'get_weather', array( 'temp' => 18 ) ) ) ) ),
			)
		);
		$this->assertSame(
			array(
				'type'      => 'function_call',
				'call_id'   => 'call_1',
				'name'      => 'get_weather',
				'arguments' => '{"city":"Paris"}',
			),
			$body['input'][1]
		);
		$this->assertSame(
			array(
				'type'    => 'function_call_output',
				'call_id' => 'call_1',
				'output'  => '{"temp":18}',
			),
			$body['input'][2]
		);
	}

	/**
	 * JSON schema format and max output tokens.
	 */
	public function test_json_schema_format_and_max_output_tokens() {
		$config = new ModelConfig();
		$config->setOutputMimeType( 'application/json' );
		$config->setOutputSchema(
			array(
				'type'                 => 'object',
				'properties'           => array( 'name' => array( 'type' => 'string' ) ),
				'required'             => array( 'name' ),
				'additionalProperties' => false,
			)
		);
		$config->setMaxTokens( 64 );
		$body = $this->body_for( $config );
		$this->assertSame( 'json_schema', $body['text']['format']['type'] );
		$this->assertTrue( $body['text']['format']['strict'] );
		$this->assertSame( 'response_schema', $body['text']['format']['name'] );
		$this->assertSame( 64, $body['max_output_tokens'] );
	}

	/**
	 * Image part becomes input image.
	 */
	public function test_image_part_becomes_input_image() {
		$png  = 'data:image/png;base64,' . base64_encode( (string) hex2bin( '89504e470d0a1a0a0000000d4948445200000001000000010806000000' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds a test data URL.
		$body = $this->body_for(
			new ModelConfig(),
			array( new UserMessage( array( new MessagePart( 'Colour?' ), new MessagePart( new File( $png, 'image/png' ) ) ) ) )
		);
		$this->assertSame(
			array(
				'type'      => 'input_image',
				'image_url' => $png,
			),
			$body['input'][0]['content'][1]
		);
	}

	/**
	 * Temperature and top p from config are never sent.
	 */
	public function test_temperature_and_top_p_from_config_are_never_sent() {
		$config = new ModelConfig();
		$config->setTemperature( 0.9 );
		$config->setTopP( 0.5 );
		$config->setCandidateCount( 2 );
		$config->setStopSequences( array( 'END' ) );
		$body = $this->body_for( $config );
		foreach ( array( 'temperature', 'top_p', 'n', 'stop', 'candidate_count', 'stop_sequences' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $body );
		}
	}

	/**
	 * No input item has a system or developer role.
	 */
	public function test_no_input_item_has_a_system_or_developer_role() {
		$config = new ModelConfig();
		$config->setSystemInstruction( 'Be brief.' );
		$body  = $this->body_for( $config );
		$roles = array_filter( array_column( $body['input'], 'role' ) );
		$this->assertSame( array(), array_intersect( $roles, array( 'system', 'developer' ) ) );
	}

	/**
	 * Reasoning custom option passes through.
	 */
	public function test_reasoning_custom_option_passes_through() {
		$config = new ModelConfig();
		$config->setCustomOption( 'reasoning', array( 'effort' => 'low' ) );
		$this->assertSame( array( 'effort' => 'low' ), $this->body_for( $config )['reasoning'] );
	}

	/**
	 * Forbidden custom options are rejected before any request.
	 */
	public function test_forbidden_custom_options_are_rejected_before_any_request() {
		foreach ( array( 'temperature', 'top_p', 'include', 'metadata', 'user', 'truncation', 'previous_response_id' ) as $key ) {
			$config = new ModelConfig();
			$config->setCustomOption( $key, 'x' );
			try {
				$this->body_for( $config );
				$this->fail( $key . ' was accepted' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( $key, $e->getMessage() );
			}
		}
		$this->assertSame( array(), Http_Mock::requests() );
	}

	/**
	 * Custom options cannot override reserved keys.
	 */
	public function test_custom_options_cannot_override_reserved_keys() {
		foreach ( array( 'store', 'stream', 'model', 'input', 'instructions', 'tools', 'tool_choice', 'parallel_tool_calls', 'prompt_cache_key', 'text', 'max_output_tokens' ) as $key ) {
			$config = new ModelConfig();
			$config->setCustomOption( $key, 'x' );
			try {
				$this->body_for( $config );
				$this->fail( $key . ' was accepted' );
			} catch ( InvalidArgumentException $e ) {
				$this->assertStringContainsString( $key, $e->getMessage() );
			}
		}
	}
}
