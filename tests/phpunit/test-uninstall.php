<?php
/**
 * Uninstall tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

/**
 * Runs uninstall.php against a site with every plugin row present.
 */
class Test_Uninstall extends Test_Case {

	/**
	 * Uninstall leaves no plugin option, transient or cron event, and nothing else is touched.
	 */
	public function test_uninstall_removes_everything() {
		global $wpdb;
		$options = array(
			'halawa_chatgpt_tokens',
			'halawa_chatgpt_installation_id',
			'halawa_chatgpt_version',
			'halawa_chatgpt_refresh_lock',
			'halawa_chatgpt_first_signin_notice',
			'halawa_chatgpt_upgrade_notice',
			'halawa_chatgpt_thread_id',
			'halawa_chatgpt_pair_active',
			'connectors_ai_chatgpt_api_key',
		);
		foreach ( $options as $option ) {
			update_option( $option, 'x', false );
		}
		// The tokens option holds a signed-out registration, the record a disconnect keeps.
		( new Halawa\ChatGptAiProvider\Authentication\TokenStore() )->save(
			array(
				'client_id'     => 'oaiapp_test',
				'subject'       => 'user_test',
				'access_token'  => 'fake-access',
				'refresh_token' => 'fake-refresh',
			)
		);
		( new Halawa\ChatGptAiProvider\Authentication\TokenStore() )->sign_out();
		$this->assertTrue( ( new Halawa\ChatGptAiProvider\Authentication\TokenStore() )->has_registration() );
		set_transient( 'halawa_chatgpt_jwks', '{"keys":[]}', DAY_IN_SECONDS );
		set_transient( 'halawa_chatgpt_pending_signin_1', array( 'state' => 's' ), 600 );
		set_transient( 'halawa_chatgpt_pending_signin_2', array( 'state' => 's' ), 600 );
		set_transient( 'halawa_chatgpt_pair_abc', 1, 600 );
		set_transient( 'ai_client_models_test', array( 'gpt-6-luna' ), DAY_IN_SECONDS );
		set_transient( 'other_plugin_cache', 'keep', DAY_IN_SECONDS );
		update_option( 'other_plugin_setting', 'keep' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'halawa_chatgpt_refresh_tokens' );

		self::run_uninstall();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The test checks the table itself.
		$left = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name = %s",
				'%halawa\_chatgpt%',
				'%ai\_client\_%',
				'connectors_ai_chatgpt_api_key'
			)
		);
		$this->assertSame( array(), $left );
		foreach ( $options as $option ) {
			$this->assertSame( 'absent', get_option( $option, 'absent' ), $option );
		}
		$this->assertFalse( wp_next_scheduled( 'halawa_chatgpt_refresh_tokens' ) );
		// A signed-out registration has no refresh token, so nothing is revoked.
		$this->assertSame( array(), Http_Mock::requests() );
		$this->assertSame( 'keep', get_transient( 'other_plugin_cache' ) );
		$this->assertSame( 'keep', get_option( 'other_plugin_setting' ) );
	}

	/**
	 * Runs uninstall.php; it can run more than once per process.
	 */
	private static function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ai-provider-for-chatgpt/ai-provider-for-chatgpt.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';
		wp_cache_flush();
	}

	/**
	 * Saves a connected record.
	 */
	private static function seed_connected_record(): void {
		( new Halawa\ChatGptAiProvider\Authentication\TokenStore() )->save(
			array(
				'client_id'     => 'oaiapp_test',
				'subject'       => 'user_test',
				'scopes'        => array( 'chatgpt.tokens.use.direct' ),
				'access_token'  => 'fake-access',
				'refresh_token' => 'fake-refresh',
				'id_token'      => 'fake.id.token',
				'expires_at'    => time() + 3600,
			)
		);
	}

	/**
	 * A connected record is revoked at OpenAI once, then deleted.
	 */
	public function test_uninstall_revokes_a_connected_record() {
		self::seed_connected_record();
		Http_Mock::queue( Http_Mock::json( array() ) );

		self::run_uninstall();

		$requests = Http_Mock::requests();
		$this->assertCount( 1, $requests );
		$this->assertSame( 'https://auth.openai.com/api/accounts/oauth/revoke', $requests[0]['url'] );
		$this->assertSame( 'POST', $requests[0]['method'] );
		$this->assertSame(
			array(
				'token'           => 'fake-refresh',
				'token_type_hint' => 'refresh_token',
				'client_id'       => 'oaiapp_test',
			),
			$requests[0]['body']
		);
		$this->assertLessThanOrEqual( 5, $requests[0]['args']['timeout'] );
		$this->assertSame( 'absent', get_option( 'halawa_chatgpt_tokens', 'absent' ) );
		$this->assertFalse( wp_next_scheduled( 'halawa_chatgpt_refresh_tokens' ) );
	}

	/**
	 * A failed revoke does not stop the cleanup.
	 */
	public function test_uninstall_deletes_everything_when_revoke_fails() {
		self::seed_connected_record();
		Http_Mock::queue( Http_Mock::json( array( 'error' => 'server_error' ), 500 ) );

		self::run_uninstall();

		$this->assertCount( 1, Http_Mock::requests() );
		$this->assertSame( 'absent', get_option( 'halawa_chatgpt_tokens', 'absent' ) );
		$this->assertSame( 'absent', get_option( 'connectors_ai_chatgpt_api_key', 'absent' ) );
	}

	/**
	 * A network error during the revoke does not stop the cleanup either.
	 */
	public function test_uninstall_deletes_everything_when_revoke_errors() {
		self::seed_connected_record();
		Http_Mock::queue( new WP_Error( 'http_request_failed', 'timed out' ) );

		self::run_uninstall();

		$this->assertCount( 1, Http_Mock::requests() );
		$this->assertSame( 'absent', get_option( 'halawa_chatgpt_tokens', 'absent' ) );
	}

	/**
	 * Without a record there is nothing to revoke.
	 */
	public function test_uninstall_without_record_makes_no_request() {
		self::run_uninstall();

		$this->assertSame( array(), Http_Mock::requests() );
	}
}
