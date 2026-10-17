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
	 * @since 0.2.0
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
	 * Sends the request and retries once with a refreshed token after HTTP 401.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt      The prompt messages.
	 * @param bool  $allow_retry Whether a refresh-and-retry is still allowed.
	 * @return GenerativeAiResult The parsed result.
	 * @throws RuntimeException When the API returns an error.
	 */
	private function sendWithRefreshRetry( array $prompt, bool $allow_retry ): GenerativeAiResult {
		$auth     = $this->getRequestAuthentication();
		$request  = $auth->authenticateRequest(
			new Request(
				HttpMethodEnum::POST(),
				ChatGptProvider::url( 'responses' ),
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'text/event-stream',
				),
				$this->prepareGenerateTextParams( $prompt ),
				$this->getRequestOptions()
			)
		);
		$response = $this->getHttpTransporter()->send( $request );
		$status   = $response->getStatusCode();

		if ( 401 === $status && $allow_retry && $auth instanceof ChatGptAccountAuthentication ) {
			$auth->force_refresh( (string) preg_replace( '/^Bearer\s+/i', '', (string) $request->getHeaderAsString( 'Authorization' ) ) );
			return $this->sendWithRefreshRetry( $prompt, false );
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
	 * @since 0.2.0
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
	 * @since 0.2.0
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
	 * @since 0.2.0
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
	 * @since 0.2.0
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
	 * Builds the request body for the Responses API.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt The prompt messages.
	 * @return array<string, mixed> The request body.
	 * @throws InvalidArgumentException When a message has an unsupported shape.
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$config = $this->getConfig();

		// The Codex backend rejects requests that are missing fields the
		// Codex CLI always sends. Defaults below mirror
		// codex-rs/core/src/client.rs build_responses_request().
		$instructions = $config->getSystemInstruction() ?? '';
		$function_declarations = $config->getFunctionDeclarations();
		$tool_names = array();
		if ( is_array( $function_declarations ) ) {
			foreach ( $function_declarations as $fn ) {
				$tool_names[] = $fn->getName();
			}
			sort( $tool_names );
		}
		// prompt_cache_key must be stable across requests with the same prefix
		// so the Codex backend can hit its prompt cache; derive it from
		// model + instructions + tool names rather than a fresh UUID.
		$cache_key = hash(
			'sha256',
			$this->metadata()->getId() . '|' . $instructions . '|' . implode( ',', $tool_names )
		);

		$params = array(
			'model'               => $this->metadata()->getId(),
			'instructions'        => $instructions,
			'input'               => $this->prepareInputParam( $prompt ),
			'tools'               => array(),
			'tool_choice'         => 'auto',
			'parallel_tool_calls' => false,
			'store'               => false,
			'stream'              => true, // Codex backend requires SSE streaming.
			'include'             => array(),
			'prompt_cache_key'    => $cache_key,
		);
		// System instruction was already merged into $params above; remove it
		// if empty so the Codex backend doesn't reject an empty-string value.
		if ( '' === $params['instructions'] ) {
			unset( $params['instructions'] );
		}

		// Codex rejects `temperature` and `top_p`. `max_output_tokens` is
		// accepted (Codex CLI uses it via model defaults; the backend tolerates
		// explicit values).
		if ( $config->getMaxTokens() !== null ) {
			$params['max_output_tokens'] = $config->getMaxTokens();
		}

		if ( $config->getOutputMimeType() === 'application/json' && $config->getOutputSchema() ) {
			$params['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'response_schema',
					'schema' => $config->getOutputSchema(),
					'strict' => true,
				),
			);
		}

		if ( is_array( $function_declarations ) && array() !== $function_declarations ) {
			$params['tools'] = $this->prepareToolsParam( $function_declarations );
		}
		// Cast to object so json_encode produces "[]" / "{}" as needed.
		if ( array() === $params['tools'] ) {
			$params['tools'] = array();
		}

		foreach ( $config->getCustomOptions() as $key => $value ) {
			if ( isset( $params[ $key ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'The custom option "%s" conflicts with an existing parameter.', esc_html( (string) $key ) )
				);
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
