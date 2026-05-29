<?php
/**
 * ChatGptModelMetadataDirectory class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;

use function Halawa\ChatGptAiProvider\codex_client_version;

/**
 * Model metadata directory backed by the Codex backend's `/models` endpoint.
 *
 * The Codex backend returns `{ "models": [...] }`, not the standard OpenAI
 * `{ "data": [...] }` shape — see codex-rs/codex-api/src/endpoint/models.rs
 * (`ModelsResponse { models }`). This directory normalizes both shapes.
 *
 * @since 0.1.0
 *
 * @phpstan-type ModelsResponseData array{
 *     models?: list<array{id?: string, model_family_display_name?: string, name?: string}>,
 *     data?:   list<array{id: string}>
 * }
 */
class ChatGptModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {

	/**
	 * Model IDs Codex CLI ships in its bundled preset list and that the Codex
	 * backend accepts when called with a ChatGPT-account token. The /models
	 * endpoint itself only returns the account's primary default (e.g. just
	 * `gpt-5.2` for Plus), so without this fallback the WP AI picker would
	 * never see the rest.
	 *
	 * Filterable via `halawa_chatgpt_bundled_models`.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{id: string, model_family_display_name?: string}>
	 */
	private const BUNDLED_MODELS = array(
		array(
			'id'                        => 'gpt-5.2',
			'model_family_display_name' => 'GPT-5.2',
		),
		array(
			'id'                        => 'gpt-5.2-codex',
			'model_family_display_name' => 'GPT-5.2 (Codex)',
		),
		array(
			'id'                        => 'gpt-5.1',
			'model_family_display_name' => 'GPT-5.1',
		),
		array(
			'id'                        => 'gpt-5.1-codex',
			'model_family_display_name' => 'GPT-5.1 (Codex)',
		),
		array(
			'id'                        => 'gpt-5',
			'model_family_display_name' => 'GPT-5',
		),
		array(
			'id'                        => 'gpt-5-codex',
			'model_family_display_name' => 'GPT-5 (Codex)',
		),
		array(
			'id'                        => 'codex-mini-latest',
			'model_family_display_name' => 'Codex Mini',
		),
	);

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param HttpMethodEnum $method  The HTTP method.
	 * @param string         $path    The request path.
	 * @param array          $headers The request headers.
	 * @param mixed          $data    The request data.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$url = ChatGptProvider::url( $path );
		// The Codex backend's /models endpoint requires a client_version query
		// string parameter; without it, the server returns HTTP 400.
		if ( 'models' === $path || str_starts_with( $path, 'models?' ) ) {
			$separator = strpos( $url, '?' ) !== false ? '&' : '?';
			$url      .= $separator . 'client_version=' . rawurlencode( codex_client_version() );
		}
		return new Request( $method, $url, $headers, $data );
	}

	/**
	 * Substitutes the ChatGPT-account OAuth authenticator regardless of what
	 * the registry attempts to inject.
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
	 * @param Response $response The /models response.
	 * @return array
	 * @throws ResponseException When neither `models` nor `data` is present in the response body.
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		/**
		 * Decoded response payload from the /models endpoint.
		 *
		 * @var ModelsResponseData $response_data
		 */
		$response_data = $response->getData();

		// The Codex backend uses "models" — the standard OpenAI `/models`
		// endpoint uses "data". Accept either.
		if ( isset( $response_data['models'] ) && is_array( $response_data['models'] ) && array() !== $response_data['models'] ) {
			$models_data = $response_data['models'];
		} elseif ( isset( $response_data['data'] ) && is_array( $response_data['data'] ) && array() !== $response_data['data'] ) {
			$models_data = $response_data['data'];
		} else {
			$models_data = array();
		}

		/**
		 * Filters the bundled fallback list of Codex-supported model IDs.
		 *
		 * Codex's /models endpoint typically returns only the primary default
		 * for the account's plan; this list mirrors the preset catalog Codex
		 * CLI ships so the WP AI picker sees the full menu of usable models.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, array{id: string, model_family_display_name?: string}> $bundled The default bundled entries.
		 */
		$bundled        = apply_filters( 'halawa_chatgpt_bundled_models', self::BUNDLED_MODELS );
		$seen_ids       = array();
		foreach ( $models_data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = $entry['id'] ?? ( $entry['slug'] ?? ( $entry['name'] ?? null ) );
			if ( is_string( $id ) && '' !== $id ) {
				$seen_ids[ $id ] = true;
			}
		}
		foreach ( $bundled as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || ! is_string( $entry['id'] ) ) {
				continue;
			}
			if ( isset( $seen_ids[ $entry['id'] ] ) ) {
				continue;
			}
			$models_data[]              = $entry;
			$seen_ids[ $entry['id'] ]   = true;
		}

		if ( array() === $models_data ) {
			throw ResponseException::fromMissingData( 'ChatGPT', 'models' );
		}

		$gpt_capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);
		// `temperature`, `top_p`, and `stopSequences` are advertised as
		// supported so WP features that demand them (e.g. the Summarization
		// ability calls `->using_temperature(0.9)`) pass model selection.
		// The model class silently drops them before sending to Codex, which
		// rejects them with "Unsupported parameter". The model still respects
		// the user's intent through its built-in defaults.
		$gpt_base_options    = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
		);
		$text_options       = array_merge(
			$gpt_base_options,
			array(
				new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			)
		);
		$multimodal_options = array_merge(
			$gpt_base_options,
			array(
				new SupportedOption(
					OptionEnum::inputModalities(),
					array(
						array( ModalityEnum::text() ),
						array( ModalityEnum::text(), ModalityEnum::image() ),
						array( ModalityEnum::text(), ModalityEnum::document() ),
					)
				),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			)
		);

		$models = array_values(
			array_filter(
				array_map(
					static function ( array $model_data ) use ( $gpt_capabilities, $text_options, $multimodal_options ): ?ModelMetadata {
						// Codex uses "id" sometimes and "name"/"slug" elsewhere; accept either.
						$model_id = $model_data['id'] ?? ( $model_data['slug'] ?? ( $model_data['name'] ?? '' ) );
						if ( ! is_string( $model_id ) || '' === $model_id || ! self::isTextModel( $model_id ) ) {
							return null;
						}
						// Codex's live response uses `display_name`; older internal
						// shapes use `model_family_display_name`. Accept either,
						// then fall back to the id.
						$display_name = $model_data['display_name']
							?? ( $model_data['model_family_display_name'] ?? $model_id );
						if ( ! is_string( $display_name ) || '' === $display_name ) {
							$display_name = $model_id;
						}
						$options = self::supportsMultimodalInput( $model_id ) ? $multimodal_options : $text_options;
						return new ModelMetadata( $model_id, $display_name, $gpt_capabilities, $options );
					},
					$models_data
				)
			)
		);

		usort( $models, array( $this, 'modelSortCallback' ) );

		return $models;
	}

	/**
	 * Checks whether the model ID looks like a text-generation model exposed
	 * over the Codex backend.
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_id The model ID returned by the API.
	 * @return bool True if the model is text-capable.
	 */
	private static function isTextModel( string $model_id ): bool {
		$is_candidate = str_starts_with( $model_id, 'gpt-' )
			|| str_starts_with( $model_id, 'o1' )
			|| str_starts_with( $model_id, 'o3' )
			|| str_starts_with( $model_id, 'o4' )
			|| 'codex-mini-latest' === $model_id;
		if ( ! $is_candidate ) {
			return false;
		}
		return ! str_contains( $model_id, '-instruct' )
			&& ! str_contains( $model_id, '-realtime' )
			&& ! str_contains( $model_id, '-transcribe' )
			&& ! str_contains( $model_id, '-audio' )
			&& ! str_contains( $model_id, '-tts' );
	}

	/**
	 * Checks whether the model supports multimodal text input (image/file).
	 *
	 * @since 0.1.0
	 *
	 * @param string $model_id The model ID.
	 * @return bool True if multimodal input is supported.
	 */
	private static function supportsMultimodalInput( string $model_id ): bool {
		return (bool) preg_match(
			'/^(codex-mini-latest|gpt-4-turbo|gpt-4o|gpt-4\.1|gpt-5(?:\.\d+)?|o1|o3|o4)/',
			$model_id
		);
	}

	/**
	 * Sort callback that prefers GPT and higher-versioned models first.
	 *
	 * @since 0.1.0
	 *
	 * @param ModelMetadata $a First model.
	 * @param ModelMetadata $b Second model.
	 * @return int Comparison result.
	 */
	protected function modelSortCallback( ModelMetadata $a, ModelMetadata $b ): int {
		$a_id = $a->getId();
		$b_id = $b->getId();

		if ( str_contains( $a_id, '-preview' ) && ! str_contains( $b_id, '-preview' ) ) {
			return 1;
		}
		if ( str_contains( $b_id, '-preview' ) && ! str_contains( $a_id, '-preview' ) ) {
			return -1;
		}
		if ( str_starts_with( $a_id, 'gpt-' ) && ! str_starts_with( $b_id, 'gpt-' ) ) {
			return -1;
		}
		if ( str_starts_with( $b_id, 'gpt-' ) && ! str_starts_with( $a_id, 'gpt-' ) ) {
			return 1;
		}

		$a_match = preg_match( '/^gpt-([0-9.]+)(-[a-z0-9-]+)?$/', $a_id, $a_matches );
		$b_match = preg_match( '/^gpt-([0-9.]+)(-[a-z0-9-]+)?$/', $b_id, $b_matches );
		if ( $a_match && ! $b_match ) {
			return -1;
		}
		if ( $b_match && ! $a_match ) {
			return 1;
		}
		if ( $a_match && $b_match ) {
			if ( version_compare( $a_matches[1], $b_matches[1], '>' ) ) {
				return -1;
			}
			if ( version_compare( $b_matches[1], $a_matches[1], '>' ) ) {
				return 1;
			}
			if ( ! isset( $a_matches[2] ) && isset( $b_matches[2] ) ) {
				return -1;
			}
			if ( ! isset( $b_matches[2] ) && isset( $a_matches[2] ) ) {
				return 1;
			}
		}
		return strcmp( $a_id, $b_id );
	}
}
