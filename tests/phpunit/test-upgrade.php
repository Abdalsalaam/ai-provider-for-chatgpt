<?php
/**
 * Upgrade routine tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Admin\Upgrade;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;

/**
 * Covers the 0.1.x to 0.2.0 migration and the reconnect notice.
 */
class Test_Upgrade extends Test_Case {

	/**
	 * The 0.1.x encryption key for the given salts.
	 *
	 * @param string $auth      AUTH_KEY value.
	 * @param string $logged_in LOGGED_IN_KEY value.
	 * @return string Raw key.
	 */
	private static function legacy_key( string $auth, string $logged_in ): string {
		return substr( hash( 'sha256', $auth . '|' . $logged_in . '|ai-provider-for-chatgpt', true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypts a payload into the option format 0.1.x wrote.
	 *
	 * @param array  $payload Plaintext record.
	 * @param string $key     Raw key.
	 * @return string Option value.
	 */
	private static function encrypt( array $payload, string $key ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( (string) wp_json_encode( $payload ), $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds a 0.1.x ciphertext fixture.
	}

	/**
	 * Seeds the rows a connected 0.1.x site has.
	 *
	 * @param string $option_value Encrypted token option value.
	 */
	private static function seed_legacy_site( string $option_value ): void {
		update_option( TokenStore::OPTION_NAME, $option_value, false );
		update_option( TokenStore::CORE_API_KEY_OPTION, TokenStore::CORE_API_KEY_PLACEHOLDER, false );
		update_option( 'halawa_chatgpt_thread_id', 'thread-test', false );
		update_option( 'halawa_chatgpt_pair_active', 'abc', false );
		set_transient( 'halawa_chatgpt_pair_abc', 1, 600 );
		set_transient( 'halawa_chatgpt_pair_rl_' . hash( 'sha256', '127.0.0.1' ), 3, 60 );
		set_transient( 'ai_client_models_test', array( 'gpt-codex-model' ), DAY_IN_SECONDS );
	}

	/**
	 * Returns the stored names that match a LIKE pattern, read from the table.
	 *
	 * @param string $like LIKE pattern.
	 * @return array<int, string>
	 */
	private static function option_names( string $like ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The test checks the table itself.
		return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	/**
	 * Asserts every 0.1.x row is gone and the notice is set.
	 */
	private function assert_legacy_cleared(): void {
		$this->assertSame( 'absent', get_option( TokenStore::OPTION_NAME, 'absent' ) );
		$this->assertSame( 'absent', get_option( TokenStore::CORE_API_KEY_OPTION, 'absent' ) );
		$this->assertSame( 'absent', get_option( 'halawa_chatgpt_thread_id', 'absent' ) );
		$this->assertSame( 'absent', get_option( 'halawa_chatgpt_pair_active', 'absent' ) );
		$this->assertFalse( get_transient( 'halawa_chatgpt_pair_abc' ) );
		$this->assertFalse( get_transient( 'halawa_chatgpt_pair_rl_' . hash( 'sha256', '127.0.0.1' ) ) );
		$this->assertSame( array(), self::option_names( '%halawa\_chatgpt\_pair%' ) );
		$this->assertSame( array(), self::option_names( '%ai\_client\_%' ) );
		$this->assertSame( '1', (string) get_option( Upgrade::NOTICE_OPTION ) );
		$this->assertSame( '0.2.0', get_option( Upgrade::VERSION_OPTION ) );
		$this->assertFalse( wp_next_scheduled( 'halawa_chatgpt_refresh_tokens' ) );
	}

	/**
	 * A readable 0.1.x record is removed with everything else 0.1.x stored.
	 */
	public function test_legacy_record_is_removed_and_notice_set() {
		$key   = self::legacy_key( AUTH_KEY, LOGGED_IN_KEY );
		$value = self::encrypt(
			array(
				'tokens'       => array(
					'access_token'  => 'fake-a',
					'refresh_token' => 'fake-r',
					'account_id'    => 'acct_test',
				),
				'last_refresh' => '2026-01-01T00:00:00Z',
			),
			$key
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Proves the fixture is a readable 0.1.x record.
		$raw = base64_decode( $value );
		$this->assertSame( 'acct_test', json_decode( (string) sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key ), true )['tokens']['account_id'] );
		self::seed_legacy_site( $value );

		$this->assertTrue( ( new Upgrade() )->maybe_run() );

		$this->assert_legacy_cleared();
		$this->assertNull( ( new TokenStore() )->get() );
	}

	/**
	 * A record encrypted under other salts is removed the same way.
	 */
	public function test_undecryptable_record_is_removed_and_notice_set() {
		self::seed_legacy_site( self::encrypt( array( 'version' => 2 ), self::legacy_key( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ) ) );

		$this->assertTrue( ( new Upgrade() )->maybe_run() );

		$this->assert_legacy_cleared();
	}

	/**
	 * The migration runs once; a second admin_init changes nothing.
	 */
	public function test_upgrade_runs_once() {
		self::seed_legacy_site( 'not-a-record' );
		$upgrade = new Upgrade();
		$upgrade->maybe_run();
		delete_option( Upgrade::NOTICE_OPTION );
		set_transient( 'halawa_chatgpt_pair_later', 1, 600 );

		$this->assertFalse( $upgrade->maybe_run() );
		$this->assertSame( 'absent', get_option( Upgrade::NOTICE_OPTION, 'absent' ) );
		$this->assertSame( 1, get_transient( 'halawa_chatgpt_pair_later' ) );
	}

	/**
	 * A v2 record without the version option stays connected and gets no notice.
	 */
	public function test_upgrade_keeps_v2_record() {
		( new TokenStore() )->save(
			array(
				'client_id'     => 'oaiapp_test',
				'subject'       => 'user_test',
				'email'         => 'user@example.com',
				'scopes'        => array( 'chatgpt.tokens.use.direct' ),
				'access_token'  => 'fake-access',
				'refresh_token' => 'fake-refresh',
				'id_token'      => 'fake.id.token',
				'expires_at'    => time() + 3600,
			)
		);
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'halawa_chatgpt_refresh_tokens' );
		delete_option( Upgrade::VERSION_OPTION );

		( new Upgrade() )->maybe_run();

		$this->assertSame( 'fake-access', ( new TokenStore() )->get_access_token() );
		$this->assertSame( TokenStore::CORE_API_KEY_PLACEHOLDER, get_option( TokenStore::CORE_API_KEY_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( 'halawa_chatgpt_refresh_tokens' ) );
		$this->assertSame( 'absent', get_option( Upgrade::NOTICE_OPTION, 'absent' ) );
		$this->assertSame( '0.2.0', get_option( Upgrade::VERSION_OPTION ) );
	}

	/**
	 * A fresh install records the version without a notice.
	 */
	public function test_fresh_install_sets_version_without_notice() {
		$this->assertTrue( ( new Upgrade() )->maybe_run() );

		$this->assertSame( '0.2.0', get_option( Upgrade::VERSION_OPTION ) );
		$this->assertSame( 'absent', get_option( Upgrade::NOTICE_OPTION, 'absent' ) );
	}

	/**
	 * Only administrators see the notice, and it links to settings and dismissal.
	 */
	public function test_notice_renders_for_admins_only() {
		update_option( Upgrade::NOTICE_OPTION, 1, false );
		$upgrade = new Upgrade();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', get_echo( array( $upgrade, 'render_notice' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = get_echo( array( $upgrade, 'render_notice' ) );
		$this->assertStringContainsString( 'AI Provider for ChatGPT now uses Sign in with ChatGPT. Connect your account again under Settings &gt; ChatGPT.', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
		$this->assertStringContainsString( 'options-general.php?page=ai-provider-for-chatgpt', $html );
		$this->assertStringContainsString( 'action=' . Upgrade::DISMISS_ACTION, $html );
		$this->assertStringContainsString( '_wpnonce=', $html );

		delete_option( Upgrade::NOTICE_OPTION );
		$this->assertSame( '', get_echo( array( $upgrade, 'render_notice' ) ) );
	}

	/**
	 * Dismissing with a valid nonce clears the flag.
	 */
	public function test_dismiss_clears_the_flag() {
		update_option( Upgrade::NOTICE_OPTION, 1, false );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Upgrade::DISMISS_ACTION );
		add_filter(
			'wp_redirect',
			static function () {
				throw new RuntimeException( 'redirected' );
			}
		);

		try {
			( new Upgrade() )->dismiss();
			$this->fail( 'dismiss() did not redirect' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}

		$this->assertSame( 'absent', get_option( Upgrade::NOTICE_OPTION, 'absent' ) );
	}

	/**
	 * Dismissing without the capability keeps the flag.
	 */
	public function test_dismiss_requires_manage_options() {
		update_option( Upgrade::NOTICE_OPTION, 1, false );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Upgrade::DISMISS_ACTION );

		try {
			( new Upgrade() )->dismiss();
			$this->fail( 'dismiss() did not stop an editor' );
		} catch ( WPDieException $e ) {
			$this->assertSame( '1', (string) get_option( Upgrade::NOTICE_OPTION ) );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * The hooks are registered for admin requests.
	 */
	public function test_register_adds_hooks() {
		$upgrade = new Upgrade();
		$upgrade->register();

		$this->assertSame( 10, has_action( 'admin_init', array( $upgrade, 'maybe_run' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $upgrade, 'render_notice' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_' . Upgrade::DISMISS_ACTION, array( $upgrade, 'dismiss' ) ) );
	}
}
