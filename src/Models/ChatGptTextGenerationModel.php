<?php
/**
 * ChatGptTextGenerationModel class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;

/**
 * Text generation model for the Responses API at api.openai.com.
 *
 * Requests always stream, and the result is built from the stream events.
 *
 * @since 0.1.0
 */
class ChatGptTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/**
	 * Custom options the route rejects or that this plugin never sends.
	 *
	 * @var list<string>
	 */
	private const FORBIDDEN_OPTIONS = array( 'temperature', 'top_p', 'include', 'metadata', 'user', 'truncation', 'previous_response_id', 'background', 'conversation', 'max_tool_calls', 'moderation', 'multi_agent', 'prompt', 'prompt_cache_retention', 'safety_identifier', 'top_logprobs' );

	/**
	 * Body keys the plugin sets itself and custom options may not replace.
	 *
	 * @var list<string>
	 */
	private const RESERVED_OPTIONS = array( 'model', 'input', 'store', 'stream', 'instructions', 'tools', 'tool_choice', 'parallel_tool_calls', 'prompt_cache_key', 'text', 'max_output_tokens' );

	/**
	 * Account authentication used for every request.
	 *
	 * @var ChatGptAccountAuthentication|null
	 */
	private $account_authentication;

	/**
	 * Returns the account authentication, creating it on first use.
	 *
	 * @since 0.1.0
	 *
	 * @return RequestAuthenticationInterface The account authentication.
	 */
	public function getRequestAuthentication(): RequestAuthenticationInterface {
		if ( null === $this->account_authentication ) {
			$this->account_authentication = new ChatGptAccountAuthentication( new TokenStore() );
		}
		return $this->account_authentication;
	}

	/**
	 * Keeps an account authentication and ignores the registry's API key placeholder.
	 *
	 * @since 1.0.0
	 *
	 * @param RequestAuthenticationInterface $request_authentication The authentication.
	 * @return void
	 */
	public function setRequestAuthentication( RequestAuthenticationInterface $request_authentication ): void {
		if ( $request_authentication instanceof ChatGptAccountAuthentication ) {
			$this->account_authentication = $request_authentication;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt The prompt messages.
	 * @return GenerativeAiResult
	 * @throws RuntimeException When the API returns an error.
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		return $this->sendWithRefreshRetry( $prompt, true );
	}

	/**
	 * Sends the request, retrying once after a 401 and once without max_output_tokens.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt          The prompt messages.
	 * @param bool  $allow_retry     Whether a refresh-and-retry is still allowed.
	 * @param bool  $send_max_tokens Whether max_output_tokens may be sent.
	 * @return GenerativeAiResult The parsed result.
	 * @throws RuntimeException When the API returns an error.
	 */
	private function sendWithRefreshRetry( array $prompt, bool $allow_retry, bool $send_max_tokens = true ): GenerativeAiResult {
		$params = $this->prepareGenerateTextParams( $prompt );
		if ( ! $send_max_tokens ) {
			unset( $params['max_output_tokens'] );
		}
		$auth     = $this->getRequestAuthentication();
		$request  = $auth->authenticateRequest(
			new Request(
				HttpMethodEnum::POST(),
				ChatGptProvider::url( 'responses' ),
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'text/event-stream',
				),
				$params,
				$this->getRequestOptions()
			)
		);
		$response = $this->getHttpTransporter()->send( $request );
		$status   = $response->getStatusCode();

		// Retry once, and only when the refresh left a different token to send.
		if ( 401 === $status && $allow_retry && $auth instanceof ChatGptAccountAuthentication
			&& $auth->force_refresh( (string) preg_replace( '/^Bearer\s+/i', '', (string) $request->getHeaderAsString( 'Authorization' ) ) )
		) {
			return $this->sendWithRefreshRetry( $prompt, false, $send_max_tokens );
		}
		// OpenAI lists max_output_tokens as unsupported on this route, so drop it once if rejected.
		if ( 400 === $status && isset( $params['max_output_tokens'] )
			&& ResponsesError::rejects_param( (string) $response->getBody(), 'max_output_tokens' )
		) {
			return $this->sendWithRefreshRetry( $prompt, $allow_retry, false );
		}
		if ( $status < 200 || $status >= 300 ) {
			throw new RuntimeException(
				esc_html( ResponsesError::message( $status, (string) $response->getBody(), ResponsesError::lower_headers( $response->getHeaders() ) ) )
			);
		}
		return $this->parseStreamingResponseToGenerativeAiResult( $response );
	}

	/**
	 * Builds the result from a /responses event stream.
	 *
	 * The route sends `response.completed` with an empty `output`, so the parts
	 * come from the `response.output_item.done` events.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The HTTP response with an SSE body.
	 * @return GenerativeAiResult The parsed result.
	 * @throws RuntimeException When the stream fails, ends early, or produced no output.
	 */
	protected function parseStreamingResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		$final = null;
		$items = array();
		foreach ( self::parse_sse_events( (string) $response->getBody() ) as list( $name, $data ) ) {
			if ( 'response.output_item.done' === $name && isset( $data['item'] ) && is_array( $data['item'] ) ) {
				$items[] = $data['item'];
			} elseif ( ( 'response.completed' === $name || 'response.incomplete' === $name ) && isset( $data['response'] ) && is_array( $data['response'] ) ) {
				$final = $data['response'];
			} elseif ( 'response.failed' === $name || 'error' === $name ) {
				throw new RuntimeException( esc_html( self::failure_message( $data ) ) );
			}
		}
		if ( null === $final ) {
			throw new RuntimeException( 'ChatGPT stream ended before response.completed.' );
		}

		// A server that fills `output` on completion is used only when no item events came.
		if ( array() === $items && isset( $final['output'] ) && is_array( $final['output'] ) ) {
			$items = $final['output'];
		}
		$status    = isset( $final['status'] ) && is_string( $final['status'] ) ? $final['status'] : 'completed';
		$reason    = $final['incomplete_details']['reason'] ?? '';
		$candidate = $this->build_candidate( $items, $status, is_string( $reason ) ? $reason : '' );

		$usage       = isset( $final['usage'] ) && is_array( $final['usage'] ) ? $final['usage'] : array();
		$input       = (int) ( $usage['input_tokens'] ?? 0 );
		$output      = (int) ( $usage['output_tokens'] ?? 0 );
		$token_usage = new TokenUsage( $input, $output, (int) ( $usage['total_tokens'] ?? $input + $output ) );

		$additional = $final;
		unset( $additional['id'], $additional['output'], $additional['usage'] );

		return new GenerativeAiResult(
			isset( $final['id'] ) && is_string( $final['id'] ) ? $final['id'] : '',
			array( $candidate ),
			$token_usage,
			$this->providerMetadata(),
			$this->metadata(),
			$additional
		);
	}

	/**
	 * Splits an SSE body into event names and decoded data.
	 *
	 * @since 1.0.0
	 *
	 * @param string $body The SSE body.
	 * @return list<array{0: string, 1: array<string, mixed>}> Name and data per event.
	 */
	protected static function parse_sse_events( string $body ): array {
		$events = array();
		$blocks = preg_split( "/\r?\n\r?\n/", str_replace( "\r\n", "\n", $body ) );
		foreach ( false === $blocks ? array() : $blocks as $block ) {
			$name  = '';
			$lines = array();
			foreach ( explode( "\n", $block ) as $line ) {
				if ( 0 === strncmp( $line, 'event:', 6 ) ) {
					$name = trim( substr( $line, 6 ) );
				} elseif ( 0 === strncmp( $line, 'data:', 5 ) ) {
					$lines[] = ltrim( substr( $line, 5 ), ' ' );
				}
			}
			$data = json_decode( implode( "\n", $lines ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			// An event without an event line is named by its type field.
			if ( '' === $name && isset( $data['type'] ) && is_string( $data['type'] ) ) {
				$name = $data['type'];
			}
			$events[] = array( $name, $data );
		}
		return $events;
	}

	/**
	 * Builds one model candidate from the output items, in stream order.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $items  Output items.
	 * @param string            $status Response status.
	 * @param string            $reason Incomplete reason, empty when complete.
	 * @return Candidate
	 * @throws RuntimeException When an incomplete response produced no part.
	 * @throws ResponseException When a complete response produced no part.
	 */
	private function build_candidate( array $items, string $status, string $reason ): Candidate {
		$parts     = array();
		$has_calls = false;
		foreach ( $items as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = $item['type'] ?? '';
			if ( 'message' === $type && isset( $item['content'] ) && is_array( $item['content'] ) ) {
				foreach ( $item['content'] as $content_index => $content ) {
					try {
						$part = is_array( $content ) ? $this->parseOutputContentToPart( $content ) : null;
					} catch ( InvalidArgumentException $e ) {
						throw ResponseException::fromInvalidData(
							esc_html( $this->providerMetadata()->getName() ),
							esc_html( "output[{$index}].content[{$content_index}]" ),
							esc_html( $e->getMessage() )
						);
					}
					if ( null !== $part ) {
						$parts[]   = $part;
						$has_calls = $has_calls || $part->getType()->isFunctionCall();
					}
				}
			} elseif ( 'function_call' === $type ) {
				$parts[]   = $this->function_call_part( $item, (int) $index );
				$has_calls = true;
			}
		}
		if ( array() === $parts && 'incomplete' === $status ) {
			throw new RuntimeException( esc_html( sprintf( 'ChatGPT response was incomplete (%s).', '' !== $reason ? $reason : 'unknown' ) ) );
		}
		if ( array() === $parts ) {
			throw ResponseException::fromMissingData( esc_html( $this->providerMetadata()->getName() ), 'output' );
		}
		if ( 'incomplete' === $status ) {
			// Partial output is returned, flagged by why the server stopped.
			$finish = 'content_filter' === $reason ? FinishReasonEnum::contentFilter() : FinishReasonEnum::length();
		} else {
			$finish = $this->parseStatusToFinishReason( $status, $has_calls );
		}
		return new Candidate( new Message( MessageRoleEnum::model(), $parts ), $finish );
	}

	/**
	 * Builds a function call part from a `function_call` output item.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $item  The output item.
	 * @param int                  $index Its position in the output.
	 * @return MessagePart
	 * @throws ResponseException When the call id or name is missing.
	 */
	private function function_call_part( array $item, int $index ): MessagePart {
		if ( ! isset( $item['call_id'] ) || ! is_string( $item['call_id'] ) ) {
			throw ResponseException::fromMissingData( esc_html( $this->providerMetadata()->getName() ), esc_html( "output[{$index}].call_id" ) );
		}
		if ( ! isset( $item['name'] ) || ! is_string( $item['name'] ) ) {
			throw ResponseException::fromMissingData( esc_html( $this->providerMetadata()->getName() ), esc_html( "output[{$index}].name" ) );
		}
		$args = null;
		if ( isset( $item['arguments'] ) && is_string( $item['arguments'] ) ) {
			$decoded = json_decode( $item['arguments'], true );
			if ( is_array( $decoded ) && array() !== $decoded ) {
				$args = $decoded;
			}
		}
		return new MessagePart( new FunctionCall( $item['call_id'], $item['name'], $args ) );
	}

	/**
	 * Builds the message for a failed response or an error event.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data Event data.
	 * @return string
	 */
	private static function failure_message( array $data ): string {
		// A failed response nests the error; an error event carries it at the top.
		$error = $data['response']['error'] ?? $data;
		return ( is_array( $error ) ? ResponsesError::from_error( $error ) : null ) ?? 'ChatGPT response failed.';
	}

	/**
	 * Builds the /responses request body.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt The prompt messages.
	 * @return array<string, mixed> The request body.
	 * @throws InvalidArgumentException When a custom option is forbidden or reserved.
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$config       = $this->getConfig();
		$instructions = (string) ( $config->getSystemInstruction() ?? '' );
		$declarations = $config->getFunctionDeclarations() ?? array();
		$tool_names   = array();
		foreach ( $declarations as $declaration ) {
			$tool_names[] = $declaration->getName();
		}
		sort( $tool_names );

		$params = array(
			'model'            => $this->metadata()->getId(),
			'input'            => $this->prepareInputParam( $prompt ),
			'store'            => false,
			'stream'           => true,
			// A key stable for the same model, instructions and tools lets the prompt cache hit.
			'prompt_cache_key' => hash( 'sha256', $this->metadata()->getId() . '|' . $instructions . '|' . implode( ',', $tool_names ) ),
		);
		if ( '' !== $instructions ) {
			$params['instructions'] = $instructions;
		}
		if ( array() !== $declarations ) {
			$params['tools']               = $this->prepareToolsParam( $declarations );
			$params['tool_choice']         = 'auto';
			$params['parallel_tool_calls'] = false;
		}
		if ( null !== $config->getMaxTokens() ) {
			$params['max_output_tokens'] = $config->getMaxTokens();
		}
		if ( 'application/json' === $config->getOutputMimeType() && $config->getOutputSchema() ) {
			$params['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'response_schema',
					'schema' => $config->getOutputSchema(),
					'strict' => true,
				),
			);
		}

		foreach ( $config->getCustomOptions() as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, self::FORBIDDEN_OPTIONS, true ) ) {
				throw new InvalidArgumentException( sprintf( 'The custom option "%s" is not accepted by ChatGPT.', esc_html( $key ) ) );
			}
			if ( in_array( $key, self::RESERVED_OPTIONS, true ) ) {
				throw new InvalidArgumentException( sprintf( 'The custom option "%s" conflicts with an existing parameter.', esc_html( $key ) ) );
			}
			$params[ $key ] = $value;
		}
		return $params;
	}

	/**
	 * Converts message objects into Responses-API `input` items.
	 *
	 * @since 0.1.0
	 *
	 * @param array $messages The messages to convert.
	 * @return list<array<string, mixed>> The input array.
	 */
	protected function prepareInputParam( array $messages ): array {
		$this->validateMessages( $messages );

		$input = array();
		foreach ( $messages as $message ) {
			$item = $this->getMessageInputItem( $message );
			if ( null !== $item ) {
				$input[] = $item;
			}
		}
		return $input;
	}

	/**
	 * Ensures function-call and function-response parts are the only part in
	 * their message, as required by the Responses API.
	 *
	 * @since 0.1.0
	 *
	 * @param array $messages The messages to validate.
	 * @return void
	 * @throws InvalidArgumentException When a function-call part is not the sole part in its message.
	 */
	protected function validateMessages( array $messages ): void {
		foreach ( $messages as $message ) {
			$parts = $message->getParts();
			if ( count( $parts ) <= 1 ) {
				continue;
			}
			foreach ( $parts as $part ) {
				$type = $part->getType();
				if ( $type->isFunctionCall() || $type->isFunctionResponse() ) {
					throw new InvalidArgumentException(
						'Function call/response parts must be the only part in a message for the Responses API.'
					);
				}
			}
		}
	}

	/**
	 * Converts a single message into its Responses-API input item.
	 *
	 * @since 0.1.0
	 *
	 * @param Message $message The message to convert.
	 * @return array<string, mixed>|null The input item, or null when the message has no parts.
	 */
	protected function getMessageInputItem( Message $message ): ?array {
		$parts = $message->getParts();
		if ( empty( $parts ) ) {
			return null;
		}

		$role    = $message->getRole();
		$content = array();
		foreach ( $parts as $part ) {
			$part_data = $this->getMessagePartData( $part, $role );
			$part_type = $part_data['type'] ?? '';
			if ( 'function_call' === $part_type || 'function_call_output' === $part_type ) {
				return $part_data;
			}
			$content[] = $part_data;
		}
		return array(
			'role'    => MessageRoleEnum::model() === $role ? 'assistant' : 'user',
			'content' => $content,
		);
	}

	/**
	 * Converts a single message part into its Responses-API representation.
	 *
	 * @since 0.1.0
	 *
	 * @param MessagePart     $part The part to convert.
	 * @param MessageRoleEnum $role The role of the parent message.
	 * @return array<string, mixed> The part data.
	 * @throws RuntimeException When the part is missing required data (file, URL, etc.).
	 * @throws InvalidArgumentException When the part has an unsupported type.
	 */
	protected function getMessagePartData( MessagePart $part, MessageRoleEnum $role ): array {
		$type = $part->getType();
		if ( $type->isText() ) {
			return array(
				'type' => $role->isModel() ? 'output_text' : 'input_text',
				'text' => $part->getText(),
			);
		}
		if ( $type->isFile() ) {
			$file = $part->getFile();
			if ( ! $file ) {
				throw new RuntimeException( 'The file typed message part must contain a file.' );
			}
			if ( $file->isRemote() ) {
				$file_url = $file->getUrl();
				if ( ! $file_url ) {
					throw new RuntimeException( 'The remote file must contain a URL.' );
				}
				if ( $file->isImage() ) {
					return array(
						'type'      => 'input_image',
						'image_url' => $file_url,
					);
				}
				return array(
					'type'     => 'input_file',
					'file_url' => $file_url,
				);
			}
			$data_uri = $file->getDataUri();
			if ( ! $data_uri ) {
				throw new RuntimeException( 'The inline file must contain base64 data.' );
			}
			if ( $file->isImage() ) {
				return array(
					'type'      => 'input_image',
					'image_url' => $data_uri,
				);
			}
			return array(
				'type'      => 'input_file',
				'filename'  => 'file',
				'file_data' => $data_uri,
			);
		}
		if ( $type->isFunctionCall() ) {
			$function_call = $part->getFunctionCall();
			if ( ! $function_call ) {
				throw new RuntimeException( 'The function_call typed message part must contain a function call.' );
			}
			return array(
				'type'      => 'function_call',
				'call_id'   => $function_call->getId(),
				'name'      => $function_call->getName(),
				'arguments' => (string) wp_json_encode( $function_call->getArgs() ),
			);
		}
		if ( $type->isFunctionResponse() ) {
			$function_response = $part->getFunctionResponse();
			if ( ! $function_response ) {
				throw new RuntimeException( 'The function_response typed message part must contain a function response.' );
			}
			return array(
				'type'    => 'function_call_output',
				'call_id' => $function_response->getId(),
				'output'  => (string) wp_json_encode( $function_response->getResponse() ),
			);
		}
		throw new InvalidArgumentException( sprintf( 'Unsupported message part type "%s".', esc_html( (string) $type ) ) );
	}

	/**
	 * Builds the `tools` parameter from function declarations.
	 *
	 * @since 0.1.0
	 *
	 * @param array $function_declarations The declared functions.
	 * @return list<array<string, mixed>> The tools array.
	 */
	protected function prepareToolsParam( array $function_declarations ): array {
		$tools = array();
		foreach ( $function_declarations as $function_declaration ) {
			$tools[] = array(
				'type'        => 'function',
				'name'        => $function_declaration->getName(),
				'description' => $function_declaration->getDescription(),
				'parameters'  => $function_declaration->getParameters(),
			);
		}
		return $tools;
	}

	/**
	 * Converts a content item from a message-typed output into a {@see MessagePart}.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $content_item The content item.
	 * @return MessagePart|null The part, or null for unknown content types.
	 * @throws InvalidArgumentException When the content item has an invalid shape.
	 */
	protected function parseOutputContentToPart( array $content_item ): ?MessagePart {
		$type = $content_item['type'] ?? '';
		if ( 'output_text' === $type ) {
			if ( ! isset( $content_item['text'] ) || ! is_string( $content_item['text'] ) ) {
				throw new InvalidArgumentException( 'Content has an invalid output_text shape.' );
			}
			return new MessagePart( $content_item['text'] );
		}
		if ( 'function_call' === $type ) {
			if (
				! isset( $content_item['call_id'], $content_item['name'] )
				|| ! is_string( $content_item['call_id'] )
				|| ! is_string( $content_item['name'] )
			) {
				throw new InvalidArgumentException( 'Content has an invalid function_call shape.' );
			}
			$args = null;
			if ( isset( $content_item['arguments'] ) && is_string( $content_item['arguments'] ) ) {
				$decoded = json_decode( $content_item['arguments'], true );
				if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
					$args = $decoded;
				}
			}
			return new MessagePart( new FunctionCall( $content_item['call_id'], $content_item['name'], $args ) );
		}
		return null;
	}

	/**
	 * Maps a Responses-API status string to a {@see FinishReasonEnum}.
	 *
	 * @since 0.1.0
	 *
	 * @param string $status            The response status.
	 * @param bool   $has_function_calls  Whether the candidate contains a function call.
	 * @return FinishReasonEnum The mapped finish reason.
	 */
	protected function parseStatusToFinishReason( string $status, bool $has_function_calls ): FinishReasonEnum {
		switch ( $status ) {
			case 'completed':
				return $has_function_calls ? FinishReasonEnum::toolCalls() : FinishReasonEnum::stop();
			case 'incomplete':
				return FinishReasonEnum::length();
			case 'failed':
			case 'cancelled':
				return FinishReasonEnum::error();
			default:
				return FinishReasonEnum::stop();
		}
	}
}
