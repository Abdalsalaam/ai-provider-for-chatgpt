<?php
/**
 * ChatGptProvider class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use Halawa\ChatGptAiProvider\Metadata\ChatGptModelMetadataDirectory;
use Halawa\ChatGptAiProvider\Models\ChatGptTextGenerationModel;

/**
 * Provider class for OpenAI accessed via the user's ChatGPT account.
 *
 * Requests target the Codex backend (`chatgpt.com/backend-api/codex`) rather
 * than `api.openai.com/v1`, and are authenticated with an OAuth access token
 * obtained through the "Sign in with ChatGPT" flow instead of an API key.
 *
 * @since 0.1.0
 */
class ChatGptProvider extends AbstractApiProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function baseUrl(): string {
		return 'https://chatgpt.com/backend-api/codex';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param ModelMetadata    $model_metadata    The model metadata.
	 * @param ProviderMetadata $provider_metadata The provider metadata.
	 * @return ModelInterface
	 * @throws RuntimeException When the requested model capability is not supported by this provider.
	 */
	protected static function createModel(
		ModelMetadata $model_metadata,
		ProviderMetadata $provider_metadata
	): ModelInterface {
		foreach ( $model_metadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new ChatGptTextGenerationModel( $model_metadata, $provider_metadata );
			}
		}
		throw new RuntimeException(
			'The ChatGPT provider currently only supports text generation models.'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The SDK's `RequestAuthenticationMethod` enum has no OAuth case at the
	 * time of writing, so we declare `apiKey()` and override the model-level
	 * `getRequestAuthentication()` to substitute our OAuth-aware auth class
	 * regardless of what the registry tries to inject.
	 *
	 * @since 0.1.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$description = function_exists( '__' )
			? __( 'OpenAI via your ChatGPT account. Configure under Settings → ChatGPT; do not enter an API key here.', 'ai-provider-for-chatgpt' )
			: 'OpenAI via your ChatGPT account. Configure under Settings → ChatGPT; do not enter an API key here.';

		$args = array(
			'chatgpt',
			'ChatGPT',
			ProviderTypeEnum::cloud(),
			admin_url( 'options-general.php?page=ai-provider-for-chatgpt' ),
			// The SDK's auth-method enum only has apiKey(); we declare it so the
			// picker shows the provider, but the model layer ignores any key
			// entered there and reads OAuth tokens from TokenStore instead.
			RequestAuthenticationMethod::apiKey(),
		);
		if ( version_compare( AiClient::VERSION, '1.2.0', '>=' ) ) {
			$args[] = $description;
		}
		if ( version_compare( AiClient::VERSION, '1.3.0', '>=' ) ) {
			$args[] = dirname( __DIR__, 2 ) . '/assets/images/chatgpt.svg';
		}
		return new ProviderMetadata( ...$args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ListModelsApiBasedProviderAvailability(
			static::modelMetadataDirectory()
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new ChatGptModelMetadataDirectory();
	}
}
