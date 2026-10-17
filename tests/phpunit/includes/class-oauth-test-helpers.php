<?php
/**
 * Shared builders for OAuth tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

/**
 * Builders for token records and token endpoint responses.
 */
final class OAuth_Test_Helpers {

	/**
	 * A complete v2 record with fake tokens.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	public static function record( array $overrides = array() ) {
		return array_merge(
			array(
				'client_id'           => 'oaiapp_x',
				'ext_agent_host_id'   => 'urn:uuid:0b6a2c8e-1f0d-4c55-9a3e-6f1b2d7c9e10',
				'subject'             => 'user_1',
				'email'               => 'ada@example.com',
				'name'                => 'Ada',
				'scopes'              => array( 'chatgpt.tokens.use.direct', 'email', 'offline_access', 'openid', 'profile', 'resource.invoke' ),
				'access_token'        => 'at_1',
				'refresh_token'       => 'rt_1',
				'id_token'            => 'it_1',
				'expires_at'          => time() + 3600,
				'earliest_refresh_at' => time() + 3240,
				'needs_signin'        => false,
			),
			$overrides
		);
	}

	/**
	 * A token endpoint success body.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	public static function token_response( array $overrides = array() ) {
		return array_merge(
			array(
				'access_token'        => 'at_2',
				'refresh_token'       => 'rt_2',
				'id_token'            => 'it_2',
				'token_type'          => 'Bearer',
				'expires_in'          => 3600,
				'scope'               => 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct',
				'earliest_refresh_at' => time() + 3240,
			),
			$overrides
		);
	}
}
