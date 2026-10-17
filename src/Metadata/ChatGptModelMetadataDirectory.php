<?php
/**
 * ChatGptModelMetadataDirectory class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Metadata;

use Throwable;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;

/**
 * Model metadata directory backed by GET /v1/models.
 *
 * @since 0.1.0
 */
class ChatGptModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {

	/**
	 * Models listed when a connected site cannot read /v1/models.
	 *
	 * @since 1.0.0
	 *
	 * @var list<string>
	 */
	public const BUNDLED_MODELS = array( 'gpt-6-luna', 'gpt-5.6-terra', 'gpt-5.6-luna' );

	/**
	 * Account authentication used for every request.
	 *
	 * @var ChatGptAccountAuthentication|null
	 */
	private $account_authentication;

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
		return new Request( $method, ChatGptProvider::url( $path ), $headers, $data );
	}

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
	 * Lists the account's models, or the bundled list when the request fails.
	 *
	 * Authentication runs first and is not caught, so a site that is not
	 * connected reports the provider as not configured. A record that needs a
	 * new sign-in lists the bundled models without calling OpenAI, so model
	 * selection succeeds and the request itself reports the ended session.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, ModelMetadata> Models keyed by id.
	 */
	protected function sendListModelsRequest(): array {
		$store = new TokenStore();
		if ( null !== $store->get() && $store->needs_signin() ) {
			return self::metadata_map( self::bundled_entries() );
		}
		$request = $this->getRequestAuthentication()->authenticateRequest(
			$this->createRequest( HttpMethodEnum::GET(), 'models' )
		);
		$models  = array();
		try {
			$response = $this->getHttpTransporter()->send( $request );
			$this->throwIfNotSuccessful( $response );
			$models = $this->parseResponseToModelMetadataList( $response );
		} catch ( Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic under WP_DEBUG only.
				error_log( 'ai-provider-for-chatgpt: listing models failed: ' . $e->getMessage() );
			}
		}
		if ( array() === $models ) {
			return self::metadata_map( self::bundled_entries() );
		}

		$map = array();
		foreach ( $models as $model ) {
			$map[ $model->getId() ] = $model;
		}
		return $map;
	}

	/**
	 * Builds model metadata keyed by id from normalised entries.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{id: string, name: string, listed: bool, image: bool}> $entries The entries.
	 * @return array<string, ModelMetadata>
	 */
	private static function metadata_map( array $entries ): array {
		$map = array();
		foreach ( $entries as $entry ) {
			$map[ $entry['id'] ] = self::to_metadata( $entry );
		}
		return $map;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response The /models response.
	 * @return list<ModelMetadata> Listed models in server order.
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		$listed = array_filter(
			self::normalise_entries( $response->getData() ),
			static function ( array $entry ): bool {
				return $entry['listed'];
			}
		);
		return array_values( array_map( array( self::class, 'to_metadata' ), $listed ) );
	}

	/**
	 * Normalises a /models body to one entry per model.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data The decoded body.
	 * @return list<array{id: string, name: string, listed: bool, image: bool}>
	 */
	public static function normalise_entries( $data ): array {
		$models  = is_array( $data ) && isset( $data['models'] ) && is_array( $data['models'] ) ? $data['models'] : array();
		$entries = array();
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) || ! isset( $model['slug'] ) || ! is_string( $model['slug'] ) || '' === $model['slug'] ) {
				continue;
			}
			$name       = $model['display_name'] ?? '';
			$modalities = $model['input_modalities'] ?? array();
			$entries[]  = array(
				'id'     => $model['slug'],
				'name'   => is_string( $name ) && '' !== $name ? $name : $model['slug'],
				// A model without a visibility field is listed.
				'listed' => 'list' === ( $model['visibility'] ?? 'list' ),
				'image'  => is_array( $modalities ) && in_array( 'image', $modalities, true ),
			);
		}
		return $entries;
	}

	/**
	 * Returns the filtered fallback list as normalised entries.
	 *
	 * @since 1.0.0
	 *
	 * @return list<array{id: string, name: string, listed: bool, image: bool}>
	 */
	private static function bundled_entries(): array {
		/**
		 * Filters the models listed when /v1/models cannot be read.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int, string|array{id: string, model_family_display_name?: string}> $models Model ids.
		 */
		$bundled = apply_filters( 'halawa_chatgpt_bundled_models', self::BUNDLED_MODELS );
		$entries = array();
		foreach ( is_array( $bundled ) ? $bundled : array() as $entry ) {
			$id   = is_array( $entry ) ? ( $entry['id'] ?? '' ) : $entry;
			$name = is_array( $entry ) ? ( $entry['model_family_display_name'] ?? $id ) : $id;
			if ( ! is_string( $id ) || '' === $id ) {
				continue;
			}
			$entries[] = array(
				'id'     => $id,
				'name'   => is_string( $name ) && '' !== $name ? $name : $id,
				'listed' => true,
				'image'  => true,
			);
		}
		return $entries;
	}

	/**
	 * Builds model metadata from a normalised entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array{id: string, name: string, listed: bool, image: bool} $entry The entry.
	 * @return ModelMetadata
	 */
	private static function to_metadata( array $entry ): ModelMetadata {
		$inputs = array( array( ModalityEnum::text() ) );
		if ( $entry['image'] ) {
			$inputs[] = array( ModalityEnum::text(), ModalityEnum::image() );
		}
		return new ModelMetadata(
			$entry['id'],
			$entry['name'],
			array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ),
			array(
				new SupportedOption( OptionEnum::systemInstruction() ),
				new SupportedOption( OptionEnum::maxTokens() ),
				// Advertised so the SDK's model requirements still select these models; never sent.
				new SupportedOption( OptionEnum::candidateCount() ),
				new SupportedOption( OptionEnum::temperature() ),
				new SupportedOption( OptionEnum::topP() ),
				new SupportedOption( OptionEnum::stopSequences() ),
				new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
				new SupportedOption( OptionEnum::outputSchema() ),
				new SupportedOption( OptionEnum::functionDeclarations() ),
				new SupportedOption( OptionEnum::customOptions() ),
				new SupportedOption( OptionEnum::inputModalities(), $inputs ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
			)
		);
	}
}
