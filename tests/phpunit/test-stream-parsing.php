<?php
/**
 * Tests for parsing /responses streams.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

require_once __DIR__ . '/includes/class-api-test-helpers.php';

/**
 * Builds results from output_item.done events and the completed event.
 */
class Test_Stream_Parsing extends Test_Case {

	/**
	 * Sends one prompt through a model whose transport returns $sse.
	 *
	 * @param string $sse Stream body.
	 * @return GenerativeAiResult
	 */
	private function generate( string $sse ): GenerativeAiResult {
		Http_Mock::queue( Http_Mock::sse( $sse ) );
		$model = Api_Test_Helpers::model( null, new ChatGptAccountAuthentication( Api_Test_Helpers::connected_store() ) );
		return $model->generateTextResult( array( new UserMessage( array( new MessagePart( 'Hi' ) ) ) ) );
	}

	/**
	 * Plain stream gives text usage and stop.
	 */
	public function test_plain_stream_gives_text_usage_and_stop() {
		$result    = $this->generate( self::fixture( 'stream-plain.sse' ) );
		$candidate = $result->getCandidates()[0];
		$this->assertCount( 1, $result->getCandidates() );
		$this->assertSame( 'Hello, world!', $candidate->getMessage()->getParts()[0]->getText() );
		$this->assertTrue( $candidate->getFinishReason()->isStop() );
		$this->assertSame( 20, $result->getTokenUsage()->getPromptTokens() );
		$this->assertSame( 8, $result->getTokenUsage()->getCompletionTokens() );
		$this->assertSame( 28, $result->getTokenUsage()->getTotalTokens() );
		$this->assertStringStartsWith( 'resp_0c2b397c', $result->getId() );
	}

	/**
	 * Tool call stream gives a function call.
	 */
	public function test_tool_call_stream_gives_a_function_call() {
		$result = $this->generate( self::fixture( 'stream-tool-call.sse' ) );
		$parts  = $result->getCandidates()[0]->getMessage()->getParts();
		$call   = $parts[0]->getFunctionCall();
		$this->assertCount( 1, $parts );
		$this->assertSame( 'get_weather', $call->getName() );
		$this->assertSame( 'call_M3d23yU5T7TFHPFzL1ujtzvL', $call->getId() );
		$this->assertSame( array( 'city' => 'Paris' ), $call->getArgs() );
		$this->assertTrue( $result->getCandidates()[0]->getFinishReason()->isToolCalls() );
	}

	/**
	 * Round trip and JSON schema streams give text.
	 */
	public function test_round_trip_and_json_schema_streams_give_text() {
		$round_trip = $this->generate( self::fixture( 'stream-tool-round-trip.sse' ) );
		$this->assertStringStartsWith( 'Paris is 18', $round_trip->getCandidates()[0]->getMessage()->getParts()[0]->getText() );
		$schema = $this->generate( self::fixture( 'stream-json-schema.sse' ) );
		$this->assertSame( '{"name":"Ada","age":36}', $schema->getCandidates()[0]->getMessage()->getParts()[0]->getText() );
	}

	/**
	 * Reasoning item is ignored and message and call form one candidate.
	 */
	public function test_reasoning_item_is_ignored_and_message_and_call_form_one_candidate() {
		$extra = Api_Test_Helpers::sse_event(
			array(
				'type' => 'response.output_item.done',
				'item' => array(
					'type'    => 'reasoning',
					'id'      => 'rs_1',
					'summary' => array(
						array(
							'type' => 'summary_text',
							'text' => 'Thinking about weather',
						),
					),
					'content' => array(
						array(
							'type' => 'reasoning_text',
							'text' => 'secret chain',
						),
					),
				),
			)
		) . Api_Test_Helpers::sse_event(
			array(
				'type' => 'response.output_item.done',
				'item' => array(
					'type'    => 'message',
					'id'      => 'msg_1',
					'role'    => 'assistant',
					'status'  => 'completed',
					'content' => array(
						array(
							'type' => 'output_text',
							'text' => 'Checking the weather.',
						),
					),
				),
			)
		);
		$count  = 0;
		$sse    = str_replace( "event: response.output_item.done\n", $extra . "event: response.output_item.done\n", self::fixture( 'stream-tool-call.sse' ), $count );
		$this->assertSame( 1, $count );

		$result = $this->generate( $sse );
		$this->assertCount( 1, $result->getCandidates() );
		$parts = $result->getCandidates()[0]->getMessage()->getParts();
		$this->assertCount( 2, $parts );
		$this->assertSame( 'Checking the weather.', $parts[0]->getText() );
		$this->assertSame( 'get_weather', $parts[1]->getFunctionCall()->getName() );
		$this->assertTrue( $result->getCandidates()[0]->getFinishReason()->isToolCalls() );
		foreach ( $parts as $part ) {
			$this->assertStringNotContainsString( 'secret chain', (string) $part->getText() );
			$this->assertStringNotContainsString( 'Thinking about weather', (string) $part->getText() );
		}
	}

	/**
	 * Failed fixture raises the plan usage message.
	 */
	public function test_failed_fixture_raises_the_plan_usage_message() {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Your ChatGPT plan usage limit was reached. Review it at https://chatgpt.com/settings/usage' );
		$this->generate( self::fixture( 'stream-failed.sse' ) );
	}

	/**
	 * Failed event with another code keeps message and code.
	 */
	public function test_failed_event_with_another_code_keeps_message_and_code() {
		$sse = Api_Test_Helpers::sse_event(
			array(
				'type'     => 'response.failed',
				'response' => array(
					'status' => 'failed',
					'error'  => array(
						'code'    => 'server_error',
						'message' => 'The model failed.',
					),
				),
			)
		);
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'ChatGPT: The model failed. (server_error)' );
		$this->generate( $sse );
	}

	/**
	 * Builds a stream with one text item and an incomplete event.
	 *
	 * @param string $text   Text of the message item, empty for none.
	 * @param string $reason Incomplete reason.
	 * @return string
	 */
	private function incomplete_stream( string $text, string $reason ): string {
		$sse = '';
		if ( '' !== $text ) {
			$sse .= Api_Test_Helpers::sse_event(
				array(
					'type' => 'response.output_item.done',
					'item' => array(
						'type'    => 'message',
						'role'    => 'assistant',
						'content' => array(
							array(
								'type' => 'output_text',
								'text' => $text,
							),
						),
					),
				)
			);
		}
		return $sse . Api_Test_Helpers::sse_event(
			array(
				'type'     => 'response.incomplete',
				'response' => array(
					'id'                 => 'resp_inc',
					'status'             => 'incomplete',
					'incomplete_details' => array( 'reason' => $reason ),
					'output'             => array(),
					'usage'              => array(
						'input_tokens'  => 5,
						'output_tokens' => 16,
						'total_tokens'  => 21,
					),
				),
			)
		);
	}

	/**
	 * Incomplete with partial text returns it with length.
	 */
	public function test_incomplete_with_partial_text_returns_it_with_length() {
		$result    = $this->generate( $this->incomplete_stream( 'Partial ans', 'max_output_tokens' ) );
		$candidate = $result->getCandidates()[0];
		$this->assertSame( 'Partial ans', $candidate->getMessage()->getParts()[0]->getText() );
		$this->assertTrue( $candidate->getFinishReason()->isLength() );
		$this->assertSame( 16, $result->getTokenUsage()->getCompletionTokens() );
	}

	/**
	 * Incomplete for content filter reports content filter.
	 */
	public function test_incomplete_for_content_filter_reports_content_filter() {
		$candidate = $this->generate( $this->incomplete_stream( 'Partial', 'content_filter' ) )->getCandidates()[0];
		$this->assertTrue( $candidate->getFinishReason()->isContentFilter() );
	}

	/**
	 * Incomplete without output raises with the reason.
	 */
	public function test_incomplete_without_output_raises_with_the_reason() {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'ChatGPT response was incomplete (max_output_tokens).' );
		$this->generate( $this->incomplete_stream( '', 'max_output_tokens' ) );
	}

	/**
	 * Error event raises.
	 */
	public function test_error_event_raises() {
		$sse = Api_Test_Helpers::sse_event(
			array(
				'type'    => 'error',
				'code'    => 'rate_limit_exceeded',
				'message' => 'Slow down.',
			)
		);
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'ChatGPT: Slow down. (rate_limit_exceeded)' );
		$this->generate( $sse );
	}

	/**
	 * Stream without completed raises.
	 */
	public function test_stream_without_completed_raises() {
		$body = self::fixture( 'stream-plain.sse' );
		$cut  = substr( $body, 0, (int) strpos( $body, 'event: response.completed' ) );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'ChatGPT stream ended before response.completed.' );
		$this->generate( $cut );
	}

	/**
	 * Completed without any output raises.
	 */
	public function test_completed_without_any_output_raises() {
		$sse = Api_Test_Helpers::sse_event(
			array(
				'type'     => 'response.completed',
				'response' => array(
					'id'     => 'resp_x',
					'status' => 'completed',
					'output' => array(),
				),
			)
		);
		$this->expectException( RuntimeException::class );
		$this->generate( $sse );
	}

	/**
	 * Parser accepts CRLF split data and missing trailer.
	 */
	public function test_parser_accepts_crlf_split_data_and_missing_trailer() {
		$item = wp_json_encode(
			array(
				'type' => 'response.output_item.done',
				'item' => array(
					'type'    => 'message',
					'role'    => 'assistant',
					'content' => array(
						array(
							'type' => 'output_text',
							'text' => 'Split',
						),
					),
				),
			)
		);
		// SSE joins data lines with a newline, so split between two JSON members.
		$half      = (int) strpos( $item, ',"item"' ) + 1;
		$completed = wp_json_encode(
			array(
				'type'     => 'response.completed',
				'response' => array(
					'id'     => 'resp_crlf',
					'status' => 'completed',
					'output' => array(),
					'usage'  => array(
						'input_tokens'  => 1,
						'output_tokens' => 1,
						'total_tokens'  => 2,
					),
				),
			)
		);
		$sse       = 'data: ' . substr( $item, 0, $half ) . "\r\n" . 'data: ' . substr( $item, $half ) . "\r\n\r\n"
			. "event: response.completed\r\ndata: " . $completed . "\r\n\r\n"
			. 'data: [DONE]';
		$this->assertSame( 'Split', $this->generate( $sse )->getCandidates()[0]->getMessage()->getParts()[0]->getText() );
	}
}
