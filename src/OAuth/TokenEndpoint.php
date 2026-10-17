<?php
/**
 * TokenEndpoint class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

use function Halawa\ChatGptAiProvider\user_agent;

use const Halawa\ChatGptAiProvider\RESOURCE;
use const Halawa\ChatGptAiProvider\REVOKE_ENDPOINT;
use const Halawa\ChatGptAiProvider\TOKEN_ENDPOINT;

/**
 * Form-encoded calls to the OpenAI token and revoke endpoints.
 *
 * @since 0.2.0
 */
final class TokenEndpoint {

	/**
	 * Exchanges an authorization code for tokens.
	 *
	 * @since 0.2.0
	 *
	 * @param string $client_id     Issued client id.
	 * @param string $code          Authorization code.
	 * @param string $code_verifier PKCE verifier.
	 * @param string $redirect_uri  Redirect URI sent on authorize.
	 * @return array{access_token:string, refresh_token:string, id_token:string, expires_in:int, scope:string, earliest_refresh_at:?int, token_type:string}
	 * @throws OAuthException On any failure.
	 */
	public function exchange( string $client_id, string $code, string $code_verifier, string $redirect_uri ): array {
		return $this->tokens(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'code'          => $code,
				'code_verifier' => $code_verifier,
				'redirect_uri'  => $redirect_uri,
				'resource'      => RESOURCE,
			)
		);
	}

	/**
	 * Exchanges a refresh token for a rotated token set.
	 *
	 * @since 0.2.0
	 *
	 * @param string $client_id     Issued client id.
	 * @param string $refresh_token Current refresh token.
	 * @return array{access_token:string, refresh_token:string, id_token:string, expires_in:int, scope:string, earliest_refresh_at:?int, token_type:string}
	 * @throws OAuthException On any failure.
	 */
	public function refresh( string $client_id, string $refresh_token ): array {
		return $this->tokens(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $client_id,
				'refresh_token' => $refresh_token,
				'resource'      => RESOURCE,
			)
		);
	}

	/**
	 * Revokes a refresh token; true only on HTTP 200.
	 *
	 * @since 0.2.0
	 *
	 * @param string $client_id     Issued client id.
	 * @param string $refresh_token Refresh token to revoke.
	 * @return bool True when OpenAI confirmed the revocation.
	 */
	public function revoke( string $client_id, string $refresh_token ): bool {
		$response = $this->post(
			REVOKE_ENDPOINT,
			array(
				'token'           => $refresh_token,
				'token_type_hint' => 'refresh_token',
				'client_id'       => $client_id,
			)
		);
		return ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Posts to the token endpoint and returns the typed token set.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $fields Form fields.
	 * @return array{access_token:string, refresh_token:string, id_token:string, expires_in:int, scope:string, earliest_refresh_at:?int, token_type:string}
	 * @throws OAuthException On any failure.
	 */
	private function tokens( array $fields ): array {
		$response = $this->post( TOKEN_ENDPOINT, $fields );
		if ( is_wp_error( $response ) ) {
			throw new OAuthException( 'network_error', esc_html( 'Could not reach ChatGPT sign-in: ' . $response->get_error_message() ) );
		}
		$status     = (int) wp_remote_retrieve_response_code( $response );
		$raw        = (string) wp_remote_retrieve_body( $response );
		$request_id = wp_remote_retrieve_header( $response, 'x-oai-request-id' );
		$request_id = is_string( $request_id ) && '' !== $request_id ? $request_id : null;
		$data       = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 && ! is_array( $data ) ) {
			// The body may be a truncated token response, so it never goes into the message.
			throw new OAuthException( 'http_' . $status, 'ChatGPT returned a response that could not be read.', (int) $status, $request_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status and request id are not output.
		}
		if ( $status < 200 || $status >= 300 ) {
			list( $code, $message ) = self::error_parts( $data, $status );
			throw new OAuthException( esc_html( $code ), esc_html( $message ), (int) $status, $request_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status and request id are not output.
		}

		foreach ( array( 'access_token', 'refresh_token', 'id_token' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || '' === $data[ $key ] ) {
				throw new OAuthException( 'token_response_incomplete', 'ChatGPT returned an incomplete token response.', (int) $status, $request_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Status and request id are not output.
			}
		}

		return array(
			'access_token'        => $data['access_token'],
			'refresh_token'       => $data['refresh_token'],
			'id_token'            => $data['id_token'],
			'token_type'          => is_string( $data['token_type'] ?? null ) ? $data['token_type'] : 'Bearer',
			'expires_in'          => is_int( $data['expires_in'] ?? null ) ? $data['expires_in'] : 0,
			'scope'               => is_string( $data['scope'] ?? null ) ? $data['scope'] : '',
			'earliest_refresh_at' => is_int( $data['earliest_refresh_at'] ?? null ) ? $data['earliest_refresh_at'] : null,
		);
	}

	/**
	 * Extracts the error code and message from an error body.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $data   Decoded body.
	 * @param int   $status HTTP status.
	 * @return array{0:string, 1:string} Code and message.
	 */
	private static function error_parts( $data, int $status ): array {
		$code    = 'http_' . $status;
		$message = '';
		if ( is_array( $data ) ) {
			$error = $data['error'] ?? null;
			if ( is_string( $error ) && '' !== $error ) {
				$code    = $error;
				$message = is_string( $data['error_description'] ?? null ) ? $data['error_description'] : '';
			} elseif ( is_array( $error ) && is_string( $error['code'] ?? null ) ) {
				$code    = $error['code'];
				$message = is_string( $error['message'] ?? null ) ? $error['message'] : '';
			}
			if ( '' === $message && is_string( $data['detail'] ?? null ) ) {
				$message = $data['detail'];
			}
		}
		if ( '' === $message ) {
			$message = 'ChatGPT sign-in returned HTTP ' . $status . '.';
		}
		return array( $code, $message );
	}

	/**
	 * Sends a form-encoded POST.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $url    Endpoint.
	 * @param array<string, string> $fields Form fields.
	 * @return array|\WP_Error The response.
	 */
	private function post( string $url, array $fields ) {
		return wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
					'User-Agent'   => user_agent(),
				),
				'body'    => $fields,
			)
		);
	}
}
