<?php
/**
 * Shared helpers for the API client tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\ChatGptAccountAuthentication;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Models\ChatGptTextGenerationModel;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

require_once __DIR__ . '/class-api-test-refresher.php';

/**
 * Builders and readers shared by the stream B tests.
 */
final class Api_Test_Helpers {

	/**
	 * Saves a connected v2 record and returns the store.
	 *
	 * @param array<string, mixed> $overrides Record fields to change.
	 * @return TokenStore
	 */
	public static function connected_store( array $overrides = array() ): TokenStore {
		$store = new TokenStore();
		$store->save(
			array_merge(
				array(
					'client_id'           => 'oaiapp_test',
					'ext_agent_host_id'   => 'urn:uuid:00000000-0000-4000-8000-000000000000',
					'subject'             => 'user-test',
					'email'               => 'test@example.com',
					'name'                => 'Test User',
					'scopes'              => array( 'chatgpt.tokens.use.direct', 'email', 'offline_access', 'openid', 'profile', 'resource.invoke' ),
					'access_token'        => 'access-1',
					'refresh_token'       => 'refresh-1',
					'id_token'            => 'id-1',
					'expires_at'          => time() + 3600,
					'earliest_refresh_at' => time() + 3000,
					'needs_signin'        => false,
				),
				$overrides
			)
		);
		return $store;
	}

	/**
	 * Builds a text model wired to the WordPress HTTP transport.
	 *
	 * @param ModelConfig|null                  $config Model config.
	 * @param ChatGptAccountAuthentication|null $auth   Authentication to inject.
	 * @return ChatGptTextGenerationModel
	 */
	public static function model( ?ModelConfig $config = null, ?ChatGptAccountAuthentication $auth = null ): ChatGptTextGenerationModel {
		$metadata = new ModelMetadata(
			'gpt-6-luna',
			'GPT-6-Luna',
			array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ),
			array()
		);
		$model    = new ChatGptTextGenerationModel( $metadata, ChatGptProvider::metadata() );
		$model->setHttpTransporter( HttpTransporterFactory::createTransporter() );
		if ( null !== $config ) {
			$model->setConfig( $config );
		}
		$model->setRequestAuthentication( $auth ?? new ChatGptAccountAuthentication( new TokenStore() ) );
		return $model;
	}

	/**
	 * Builds a mocked response with any body, including non-JSON error pages.
	 *
	 * @param int                   $code    Status code.
	 * @param string                $body    Body.
	 * @param array<string, string> $headers Headers with lower-case names.
	 * @return array<string, mixed>
	 */
	public static function response( int $code, string $body, array $headers = array() ): array {
		return array(
			'headers'  => new WpOrg\Requests\Utility\CaseInsensitiveDictionary( $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Encodes one SSE event from its data payload.
	 *
	 * @param array<string, mixed> $data Event data with a type key.
	 * @return string
	 */
	public static function sse_event( array $data ): string {
		return 'event: ' . $data['type'] . "\n" . 'data: ' . wp_json_encode( $data ) . "\n\n";
	}

	/**
	 * Returns the last mocked request.
	 *
	 * @return array<string, mixed> The Http_Mock record: url, method, headers, body, args.
	 * @throws RuntimeException When no request was made.
	 */
	public static function last_request(): array {
		$requests = Http_Mock::requests();
		if ( array() === $requests ) {
			throw new RuntimeException( 'No HTTP request was made.' );
		}
		return end( $requests );
	}

	/**
	 * Returns a request's headers with lower-case names.
	 *
	 * @param array<string, mixed> $request Http_Mock record.
	 * @return array<string, string>
	 */
	public static function request_headers( array $request ): array {
		$headers = array();
		foreach ( (array) ( $request['headers'] ?? array() ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $headers;
	}

	/**
	 * Returns a request's decoded JSON body.
	 *
	 * @param array<string, mixed> $request Http_Mock record.
	 * @return array<string, mixed>
	 */
	public static function request_json( array $request ): array {
		$decoded = json_decode( (string) ( $request['body'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
