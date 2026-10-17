<?php
/**
 * Activation and deactivation tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

/**
 * Covers the refresh cron around plugin activation and deactivation.
 */
class Test_Activation extends Test_Case {

	/**
	 * Saves a v2 record, then removes the event save() scheduled.
	 */
	private static function seed_record_without_cron(): void {
		( new TokenStore() )->save(
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
		TokenRefresher::unschedule();
	}

	/**
	 * Activation without a connection schedules nothing.
	 */
	public function test_activation_without_record_schedules_nothing() {
		\Halawa\ChatGptAiProvider\activate();

		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
	}

	/**
	 * Activation with a live connection schedules the weekly refresh.
	 */
	public function test_activation_with_record_schedules_cron() {
		self::seed_record_without_cron();

		\Halawa\ChatGptAiProvider\activate();

		$this->assertNotFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
		$this->assertSame( 'weekly', wp_get_schedule( TokenRefresher::CRON_HOOK ) );
	}

	/**
	 * A connection that needs a new sign-in is not refreshed, so nothing is scheduled.
	 */
	public function test_activation_with_ended_session_schedules_nothing() {
		self::seed_record_without_cron();
		( new TokenStore() )->mark_needs_signin();

		\Halawa\ChatGptAiProvider\activate();

		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
	}

	/**
	 * Deactivation removes the event and keeps the connection.
	 */
	public function test_deactivation_unschedules_and_keeps_record() {
		self::seed_record_without_cron();
		TokenRefresher::schedule();

		\Halawa\ChatGptAiProvider\deactivate();

		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
		$this->assertSame( 'fake-access', ( new TokenStore() )->get_access_token() );
	}

	/**
	 * Both callbacks are registered for this plugin file.
	 */
	public function test_hooks_are_registered() {
		$basename = plugin_basename( dirname( __DIR__, 2 ) . '/ai-provider-for-chatgpt.php' );

		$this->assertSame( 10, has_action( 'activate_' . $basename, 'Halawa\\ChatGptAiProvider\\activate' ) );
		$this->assertSame( 10, has_action( 'deactivate_' . $basename, 'Halawa\\ChatGptAiProvider\\deactivate' ) );
	}

	/**
	 * A signed-out registration has no tokens to keep alive, so nothing is scheduled.
	 */
	public function test_activation_with_signed_out_registration_schedules_nothing() {
		self::seed_record_without_cron();
		( new TokenStore() )->sign_out();

		\Halawa\ChatGptAiProvider\activate();

		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
	}
}
