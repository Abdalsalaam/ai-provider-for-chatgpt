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

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ai-provider-for-chatgpt/ai-provider-for-chatgpt.php' );
		}
		require_once dirname( __DIR__, 2 ) . '/uninstall.php';
		wp_cache_flush();

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
		$this->assertSame( 'keep', get_transient( 'other_plugin_cache' ) );
		$this->assertSame( 'keep', get_option( 'other_plugin_setting' ) );
	}
}
