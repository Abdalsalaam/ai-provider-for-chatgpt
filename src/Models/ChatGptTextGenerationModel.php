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
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
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
 * Text generation model targeting the Codex backend `/responses` endpoint.
 *
 * The request and response shapes match the standard OpenAI Responses API,
 * so the body of this class mirrors the OpenAI provider's text model with
 * three differences: the endpoint URL, the authenticator class, and a
 * single refresh-and-retry on HTTP 401.
 *
 * @since 0.1.0
 *
 * @phpstan-type OutputContentData array{
 *     type: string,
 *     text?: string,
 *     call_id?: string,
 *     name?: string,
 *     arguments?: string
 * }
 * @phpstan-type OutputItemData array{
 *     type: string,
 *     id?: string,
 *     role?: string,
 *     status?: string,
 *     content?: list<OutputContentData>,
 *     call_id?: string,
 *     name?: string,
 *     arguments?: string
 * }
 * @phpstan-type UsageData array{
 *     input_tokens?: int,
 *     output_tokens?: int,
 *     total_tokens?: int
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     status?: string,
 *     output?: list<OutputItemData>,
 *     usage?: UsageData
 * }
 */
class ChatGptTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/**
	 * Substitutes the OAuth-aware authenticator for the API-key placeholder
	 * the SDK auto-injects.
	 *
	 * @since 0.1.0
	 *
	 * @return RequestAuthenticationInterface The OAuth-aware authenticator.
	 */
	public function getRequestAuthentication(): RequestAuthenticationInterface {
		return new ChatGptAccountAuthentication( new TokenStore() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt The prompt messages.
	 * @return GenerativeAiResult
	 * @throws RuntimeException When the Codex backend returns an unrecoverable error.
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		return $this->sendWithRefreshRetry( $prompt, true );
	}

	/**
	 * Sends the generation request, refreshing the access token and retrying
	 * once if the server responds with HTTP 401.
	 *
	 * @since 0.1.0
	 *
	 * @param array $prompt      The prompt messages.
	 * @param bool  $allow_retry Whether a refresh-and-retry is still allowed.
	 * @return GenerativeAiResult The parsed result.
	 * @throws RuntimeException When the request fails after a refresh-and-retry.
	 */
	private function sendWithRefreshRetry( array $prompt, bool $allow_retry ): GenerativeAiResult {
		$http_transporter = $this->getHttpTransporter();
		$auth            = $this->getRequestAuthentication();

		$request  = new Request(
			HttpMethodEnum::POST(),
			ChatGptProvider::url( 'responses' ),
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'text/event-stream',
			),
			$this->prepareGenerateTextParams( $prompt ),
			$this->getRequestOptions()
		);
		$request  = $auth->authenticateRequest( $request );
		$response = $http_transporter->send( $request );

		if ( $allow_retry && $response->getStatusCode() === 401 && $auth instanceof ChatGptAccountAuthentication ) {
			$auth->force_refresh();
			return $this->sendWithRefreshRetry( $prompt, false );
		}

		$status = $response->getStatusCode();
		if ( $status < 200 || $status >= 300 ) {
			// Surface the server's actual error body — the SDK's generic
			// "Bad Request (400)" hides the diagnostic.
			$body = (string) $response->getBody();
			throw new RuntimeException(
				sprintf(
					'ChatGPT /responses returned HTTP %d. Body: %s',
					(int) $status,
					esc_html( substr( $body, 0, 800 ) )
				)
			);
		}
		ResponseUtil::throwIfNotSuccessful( $response );
		return $this->parseStreamingResponseToGenerativeAiResult( $response );
	}

	/**
	 * Parses a Codex SSE stream response body into a {@see GenerativeAiResult}.
	 *
	 * The Codex backend always streams; the final `response.completed` event
	 * carries the complete response object in the same shape as the standard
	 * non-streaming OpenAI Responses API, so we extract that and reuse the
	 * existing JSON parser.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The HTTP response with an SSE-encoded body.
	 * @return GenerativeAiResult The parsed result.
	 * @throws RuntimeException When no `response.completed` event is present.
	 */
	protected function parseStreamingResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		$body          = (string) $response->getBody();
		$final_response = null;

		// SSE events are separated by blank lines. Each event is one or more
		// "field: value" lines; we only care about "event:" and "data:".
		$events = preg_split( "/\r?\n\r?\n/", $body );
		if ( false === $events ) {
			$events = array();
		}
		foreach ( $events as $raw_event ) {
			$event_name = '';
			$data_lines = array();
			$lines      = preg_split( "/\r?\n/", $raw_event );
			if ( false === $lines ) {
				$lines = array();
			}
			foreach ( $lines as $line ) {
				if ( 0 === strncmp( $line, 'event:', 6 ) ) {
					$event_name = trim( substr( $line, 6 ) );
				} elseif ( 0 === strncmp( $line, 'data:', 5 ) ) {
					$data_lines[] = ltrim( substr( $line, 5 ), ' ' );
				}
			}
			if ( 'response.completed' !== $event_name || array() === $data_lines ) {
				continue;
			}
			$decoded = json_decode( implode( "\n", $data_lines ), true );
			if ( is_array( $decoded ) && isset( $decoded['response'] ) && is_array( $decoded['response'] ) ) {
				$final_response = $decoded['response'];
			}
		}

		if ( null === $final_response ) {
			throw new RuntimeException(
				'ChatGPT stream did not contain a response.completed event. Body head: '
				. esc_html( substr( $body, 0, 400 ) )
			);
		}

		// Re-wrap the extracted payload as a normal Response so the existing
		// JSON parser can consume it unchanged.
		$json_response = new Response(
			200,
			array( 'Content-Type' => array( 'application/json' ) ),
			(string) wp_json_encode( $final_response )
		);
		return $this->parseResponseToGenerativeAiResult( $json_response );
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
	 * Parses the API response into a {@see GenerativeAiResult}.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The raw response.
	 * @return GenerativeAiResult The parsed result.
	 * @throws ResponseException When the response is missing the `output` field.
	 */
	protected function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		/**
		 * Decoded response payload.
		 *
		 * @var ResponseData $response_data
		 */
		$response_data = $response->getData();

		if ( ! isset( $response_data['output'] ) || ! $response_data['output'] ) {
			throw ResponseException::fromMissingData( esc_html( $this->providerMetadata()->getName() ), 'output' );
		}
		if ( ! is_array( $response_data['output'] ) || ! array_is_list( $response_data['output'] ) ) {
			throw ResponseException::fromInvalidData(
				esc_html( $this->providerMetadata()->getName() ),
				'output',
				'The value must be an indexed array.'
			);
		}

		$candidates = array();
		foreach ( $response_data['output'] as $index => $output_item ) {
			if ( ! is_array( $output_item ) || array_is_list( $output_item ) ) {
				throw ResponseException::fromInvalidData(
					esc_html( $this->providerMetadata()->getName() ),
					esc_html( "output[{$index}]" ),
					'The value must be an associative array.'
				);
			}
			$candidate = $this->parseOutputItemToCandidate( $output_item, $index, $response_data['status'] ?? 'completed' );
			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}

		$id = isset( $response_data['id'] ) && is_string( $response_data['id'] ) ? $response_data['id'] : '';

		if ( isset( $response_data['usage'] ) && is_array( $response_data['usage'] ) ) {
			$usage      = $response_data['usage'];
			$token_usage = new TokenUsage(
				$usage['input_tokens'] ?? 0,
				$usage['output_tokens'] ?? 0,
				$usage['total_tokens'] ?? ( ( $usage['input_tokens'] ?? 0 ) + ( $usage['output_tokens'] ?? 0 ) )
			);
		} else {
			$token_usage = new TokenUsage( 0, 0, 0 );
		}

		$additional_data = $response_data;
		unset( $additional_data['id'], $additional_data['output'], $additional_data['usage'] );

		return new GenerativeAiResult(
			$id,
			$candidates,
			$token_usage,
			$this->providerMetadata(),
			$this->metadata(),
			$additional_data
		);
	}

	/**
	 * Converts one output item from the response to a {@see Candidate}.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $output_item     The output item.
	 * @param int                  $index          The index in the output array.
	 * @param string               $response_status The overall response status.
	 * @return Candidate|null The candidate, or null when the item should be skipped.
	 */
	protected function parseOutputItemToCandidate( array $output_item, int $index, string $response_status ): ?Candidate {
		$type = $output_item['type'] ?? '';
		if ( 'message' === $type ) {
			return $this->parseMessageOutputToCandidate( $output_item, $index, $response_status );
		}
		if ( 'function_call' === $type ) {
			return $this->parseFunctionCallOutputToCandidate( $output_item, $index );
		}
		return null;
	}

	/**
	 * Builds a candidate from a `message`-typed output item.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $output_item     The output item.
	 * @param int                  $index          The index in the output array.
	 * @param string               $response_status The overall response status.
	 * @return Candidate The candidate.
	 * @throws ResponseException When required response fields are missing.
	 */
	protected function parseMessageOutputToCandidate( array $output_item, int $index, string $response_status ): Candidate {
		$role = isset( $output_item['role'] ) && 'user' === $output_item['role']
			? MessageRoleEnum::user()
			: MessageRoleEnum::model();

		$parts            = array();
		$has_function_calls = false;

		if ( isset( $output_item['content'] ) && is_array( $output_item['content'] ) ) {
			foreach ( $output_item['content'] as $content_index => $content_item ) {
				try {
					$part = $this->parseOutputContentToPart( $content_item );
				} catch ( InvalidArgumentException $e ) {
					throw ResponseException::fromInvalidData(
						esc_html( $this->providerMetadata()->getName() ),
						esc_html( "output[{$index}].content[{$content_index}]" ),
						esc_html( $e->getMessage() )
					);
				}
				if ( null !== $part ) {
					$parts[] = $part;
					if ( $part->getType()->isFunctionCall() ) {
						$has_function_calls = true;
					}
				}
			}
		}

		return new Candidate(
			new Message( $role, $parts ),
			$this->parseStatusToFinishReason( $response_status, $has_function_calls )
		);
	}

	/**
	 * Builds a candidate from a top-level `function_call` output item.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $output_item The output item.
	 * @param int                  $index      The index in the output array.
	 * @return Candidate The candidate.
	 * @throws ResponseException When the output item is missing required call data.
	 */
	protected function parseFunctionCallOutputToCandidate( array $output_item, int $index ): Candidate {
		if ( ! isset( $output_item['call_id'] ) || ! is_string( $output_item['call_id'] ) ) {
			throw ResponseException::fromMissingData( esc_html( $this->providerMetadata()->getName() ), esc_html( "output[{$index}].call_id" ) );
		}
		if ( ! isset( $output_item['name'] ) || ! is_string( $output_item['name'] ) ) {
			throw ResponseException::fromMissingData( esc_html( $this->providerMetadata()->getName() ), esc_html( "output[{$index}].name" ) );
		}

		$args = null;
		if ( isset( $output_item['arguments'] ) && is_string( $output_item['arguments'] ) ) {
			$decoded = json_decode( $output_item['arguments'], true );
			if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
				$args = $decoded;
			}
		}

		$part = new MessagePart( new FunctionCall( $output_item['call_id'], $output_item['name'], $args ) );
		return new Candidate(
			new Message( MessageRoleEnum::model(), array( $part ) ),
			FinishReasonEnum::toolCalls()
		);
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
