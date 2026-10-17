<?php
/**
 * Tests for TokenRefresher.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\OAuthException;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

require_once __DIR__ . '/includes/class-oauth-test-helpers.php';

/**
 * TokenRefresher tests.
 */
class Test_Token_Refresher extends Test_Case {

	/**
	 * Token store under test.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Fresh store, no lock row, no cron event.
	 */
	public function set_up() {
		parent::set_up();
		$this->store = new TokenStore();
		$this->delete_lock();
		wp_clear_scheduled_hook( TokenRefresher::CRON_HOOK );
	}

	/**
	 * Inserts a lock row the way another process would.
	 *
	 * @param int $time Lock timestamp.
	 */
	private function insert_lock( $time ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "INSERT INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'off')", TokenRefresher::LOCK_OPTION, (string) $time ) );
	}

	/**
	 * Returns the lock row value, or null when absent.
	 *
	 * @return string|null
	 */
	private function lock_value() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", TokenRefresher::LOCK_OPTION ) );
	}

	/**
	 * Removes the lock row.
	 */
	private function delete_lock() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => TokenRefresher::LOCK_OPTION ) );
	}

	/**
	 * Saves a record that expires in the given number of seconds.
	 *
	 * @param int   $seconds   Seconds until expiry.
	 * @param array $overrides Record overrides.
	 */
	private function save_expiring_in( $seconds, array $overrides = array() ) {
		$this->store->save( OAuth_Test_Helpers::record( array_merge( array( 'expires_at' => time() + $seconds ), $overrides ) ) );
	}

	/**
	 * Due and expired windows.
	 */
	public function test_due_and_expired_windows() {
		$refresher = new TokenRefresher( $this->store );

		$this->save_expiring_in( 301 );
		$this->assertFalse( $refresher->is_due() );
		$this->assertFalse( $refresher->is_expired() );

		$this->save_expiring_in( 299 );
		$this->assertTrue( $refresher->is_due() );
		$this->assertFalse( $refresher->is_expired() );

		$this->save_expiring_in( 0 );
		$this->assertTrue( $refresher->is_expired() );

		$this->store->save( OAuth_Test_Helpers::record( array( 'expires_at' => 0 ) ) );
		$this->assertTrue( $refresher->is_due() );
		$this->assertTrue( $refresher->is_expired() );
	}

	/**
	 * A successful refresh replaces every token field together.
	 */
	public function test_rotation_replaces_all_fields() {
		$this->save_expiring_in( 10 );
		$response = OAuth_Test_Helpers::token_response(
			array(
				'scope'               => 'openid resource.invoke chatgpt.tokens.use.direct',
				'earliest_refresh_at' => 1792269913,
			)
		);
		Http_Mock::queue( Http_Mock::json( $response ) );

		$this->assertSame( TokenRefresher::REFRESHED, ( new TokenRefresher( $this->store ) )->refresh( 'rt_1' ) );

		$this->assertSame( 'at_2', $this->store->get_access_token() );
		$this->assertSame( 'rt_2', $this->store->get_refresh_token() );
		$this->assertSame( 'it_2', $this->store->get_id_token() );
		$this->assertEqualsWithDelta( time() + 3600, $this->store->get_expires_at(), 2 );
		$this->assertSame( 1792269913, $this->store->get_earliest_refresh_at() );
		$this->assertSame( array( 'chatgpt.tokens.use.direct', 'openid', 'resource.invoke' ), $this->store->get_scopes() );
		$this->assertSame( 'user_1', $this->store->get_subject() );
		$this->assertSame( 'oaiapp_x', Http_Mock::requests()[0]['body']['client_id'] );
		$this->assertSame( 'rt_1', Http_Mock::requests()[0]['body']['refresh_token'] );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * A manual refresh or a 401 retry refreshes even when not due.
	 */
	public function test_refresh_when_not_due_still_rotates() {
		$this->save_expiring_in( 3000 );
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response() ) );
		$this->assertSame( TokenRefresher::REFRESHED, ( new TokenRefresher( $this->store ) )->refresh() );
		$this->assertSame( 'at_2', $this->store->get_access_token() );
		$this->assertCount( 1, Http_Mock::requests() );
	}

	/**
	 * A busy lock with an unexpired token returns at once with no HTTP call.
	 */
	public function test_held_lock_makes_no_http_call() {
		$this->save_expiring_in( 60 );
		$this->insert_lock( time() );

		$this->assertSame( TokenRefresher::BUSY, ( new TokenRefresher( $this->store ) )->refresh( 'rt_1' ) );

		$this->assertCount( 0, Http_Mock::requests() );
		$this->assertSame( 'rt_1', $this->store->get_refresh_token() );
		$this->assertNotNull( $this->lock_value() );
	}

	/**
	 * Busy lock and an expired token: wait, then return the token the holder wrote.
	 */
	public function test_busy_lock_with_expired_token_waits_for_rotation() {
		$this->save_expiring_in( -5 );
		$this->insert_lock( time() );
		$store     = $this->store;
		$refresher = new class( $store ) extends TokenRefresher {
			/**
			 * Pauses seen.
			 *
			 * @var int
			 */
			public $pauses = 0;

			/**
			 * Simulates the lock holder finishing after the second poll.
			 *
			 * @param int $milliseconds Ignored.
			 */
			protected function pause( int $milliseconds ): void {
				++$this->pauses;
				if ( 2 === $this->pauses ) {
					( new TokenStore() )->update(
						array(
							'access_token'  => 'at_2',
							'refresh_token' => 'rt_2',
							'expires_at'    => time() + 3600,
						)
					);
				}
			}
		};

		$this->assertSame( TokenRefresher::ALREADY_ROTATED, $refresher->refresh( 'rt_1' ) );

		$this->assertSame( 2, $refresher->pauses );
		$this->assertCount( 0, Http_Mock::requests() );
		$this->assertSame( 'at_2', $this->store->get_access_token() );
	}

	/**
	 * A rejected token that looks valid waits on a busy lock instead of returning BUSY.
	 */
	public function test_treat_as_expired_waits_on_busy_lock() {
		$this->save_expiring_in( 3000 );
		$this->insert_lock( time() );
		$refresher = new class( $this->store ) extends TokenRefresher {
			/**
			 * Pauses seen.
			 *
			 * @var int
			 */
			public $pauses = 0;

			/**
			 * Simulates the lock holder finishing after the third poll.
			 *
			 * @param int $milliseconds Ignored.
			 */
			protected function pause( int $milliseconds ): void {
				++$this->pauses;
				if ( 3 === $this->pauses ) {
					( new TokenStore() )->update(
						array(
							'access_token'  => 'at_2',
							'refresh_token' => 'rt_2',
						)
					);
				}
			}
		};

		$this->assertSame( TokenRefresher::BUSY, ( new TokenRefresher( $this->store ) )->refresh( 'rt_1' ) );
		$this->assertSame( TokenRefresher::ALREADY_ROTATED, $refresher->refresh( 'rt_1', true ) );
		$this->assertSame( 3, $refresher->pauses );
		$this->assertCount( 0, Http_Mock::requests() );
		$this->assertSame( 'at_2', $this->store->get_access_token() );

		$this->expectException( OAuthException::class );
		$refresher->refresh( 'rt_2', true );
	}

	/**
	 * Busy lock and a token still expired after 5 s: refresh_in_progress.
	 */
	public function test_busy_lock_times_out_with_refresh_in_progress() {
		$this->save_expiring_in( -5 );
		$this->insert_lock( time() );
		$refresher = new class( $this->store ) extends TokenRefresher {
			/**
			 * Pauses seen.
			 *
			 * @var int
			 */
			public $pauses = 0;

			/**
			 * Counts pauses without sleeping.
			 *
			 * @param int $milliseconds Ignored.
			 */
			protected function pause( int $milliseconds ): void {
				++$this->pauses;
			}
		};
		try {
			$refresher->refresh( 'rt_1' );
			$this->fail( 'Expected refresh_in_progress' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'refresh_in_progress', $e->getErrorCode() );
		}
		$this->assertSame( 20, $refresher->pauses );
		$this->assertCount( 0, Http_Mock::requests() );
		$this->assertFalse( $this->store->needs_signin() );
	}

	/**
	 * A lock older than LOCK_TTL is replaced and the refresh goes ahead.
	 */
	public function test_stale_lock_is_replaced() {
		$this->save_expiring_in( 60 );
		$this->insert_lock( time() - TokenRefresher::LOCK_TTL - 1 );
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response() ) );

		$this->assertSame( TokenRefresher::REFRESHED, ( new TokenRefresher( $this->store ) )->refresh( 'rt_1' ) );

		$this->assertCount( 1, Http_Mock::requests() );
		$this->assertSame( 'rt_2', $this->store->get_refresh_token() );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * The loser of a race holds the old refresh token: it sends nothing.
	 */
	public function test_lost_race_sends_nothing() {
		// The winner already rotated rt_1 to rt_2 and released the lock.
		$this->save_expiring_in(
			3600,
			array(
				'refresh_token' => 'rt_2',
				'access_token'  => 'at_2',
			)
		);

		$this->assertSame( TokenRefresher::ALREADY_ROTATED, ( new TokenRefresher( $this->store ) )->refresh( 'rt_1' ) );

		$this->assertCount( 0, Http_Mock::requests() );
		$this->assertFalse( $this->store->needs_signin() );
		$this->assertSame( 'at_2', $this->store->get_access_token() );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * The post-lock re-read bypasses a stale object cache entry.
	 */
	public function test_post_lock_read_bypasses_object_cache() {
		$this->save_expiring_in( 60, array( 'refresh_token' => 'rt_1' ) );
		$stale = get_option( TokenStore::OPTION_NAME );
		$this->save_expiring_in( 3600, array( 'refresh_token' => 'rt_2' ) );
		$fresh = get_option( TokenStore::OPTION_NAME );
		// Another process wrote rt_2 to the table while this one cached rt_1.
		wp_cache_set( TokenStore::OPTION_NAME, $stale, 'options' );
		$this->assertSame( 'rt_1', $this->store->get_refresh_token() );
		$this->assertNotSame( $stale, $fresh );

		$this->assertSame( TokenRefresher::ALREADY_ROTATED, ( new TokenRefresher( $this->store ) )->refresh( 'rt_1' ) );

		$this->assertCount( 0, Http_Mock::requests() );
		$this->assertSame( 'rt_2', $this->store->get_refresh_token() );
	}

	/**
	 * Terminal codes.
	 *
	 * @return array<string, array{string}>
	 */
	public function terminal_provider() {
		return array_combine(
			TokenRefresher::TERMINAL_CODES,
			array_map(
				static function ( $code ) {
					return array( $code );
				},
				TokenRefresher::TERMINAL_CODES
			)
		);
	}

	/**
	 * Each terminal code marks needs_signin and keeps identity fields.
	 *
	 * @dataProvider terminal_provider
	 *
	 * @param string $code OAuth error code.
	 */
	public function test_terminal_code_marks_needs_signin( $code ) {
		$this->save_expiring_in( 60 );
		Http_Mock::queue( Http_Mock::json( array( 'error' => $code ), 400 ) );
		try {
			( new TokenRefresher( $this->store ) )->refresh( 'rt_1' );
			$this->fail( 'Expected OAuthException' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'needs_signin', $e->getErrorCode() );
			$this->assertSame( 'ChatGPT session ended. Sign in again under Settings > ChatGPT.', $e->getMessage() );
		}
		$this->assertTrue( $this->store->needs_signin() );
		$this->assertSame( 'oaiapp_x', $this->store->get_client_id() );
		$this->assertSame( 'user_1', $this->store->get_subject() );
		$this->assertSame( 'ada@example.com', $this->store->get_email() );
		$this->assertNotNull( $this->store->get_host_id() );
		$this->assertNull( $this->lock_value() );
		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
	}

	/**
	 * A marked record refuses to refresh without an HTTP call.
	 */
	public function test_marked_record_does_not_refresh() {
		$this->save_expiring_in( 60, array( 'needs_signin' => true ) );
		$this->expectException( OAuthException::class );
		try {
			( new TokenRefresher( $this->store ) )->refresh();
		} finally {
			$this->assertCount( 0, Http_Mock::requests() );
		}
	}

	/**
	 * Network and 5xx failures keep the tokens and release the lock.
	 */
	public function test_network_error_keeps_tokens() {
		$this->save_expiring_in( 60 );
		Http_Mock::queue( new WP_Error( 'http_request_failed', 'timeout' ) );
		Http_Mock::queue( Http_Mock::json( array( 'error' => 'server_error' ), 503 ) );
		Http_Mock::queue( Http_Mock::json( array( 'detail' => 'Unavailable' ), 503 ) );

		foreach ( array( 'network_error', 'server_error', 'http_503' ) as $expected ) {
			try {
				( new TokenRefresher( $this->store ) )->refresh( 'rt_1' );
				$this->fail( 'Expected ' . $expected );
			} catch ( OAuthException $e ) {
				$this->assertSame( $expected, $e->getErrorCode() );
			}
			$this->assertSame( 'rt_1', $this->store->get_refresh_token() );
			$this->assertFalse( $this->store->needs_signin() );
			$this->assertNull( $this->lock_value() );
		}
	}

	/**
	 * Refresh without a record is not_connected.
	 */
	public function test_not_connected() {
		try {
			( new TokenRefresher( $this->store ) )->refresh();
			$this->fail( 'Expected not_connected' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'not_connected', $e->getErrorCode() );
		}
		$this->assertCount( 0, Http_Mock::requests() );
	}

	/**
	 * A record without a refresh token is not_connected after the lock.
	 */
	public function test_record_without_refresh_token_is_not_connected() {
		$this->store->save( OAuth_Test_Helpers::record( array( 'refresh_token' => '' ) ) );
		$this->assertNotNull( $this->store->get() );
		try {
			( new TokenRefresher( $this->store ) )->refresh();
			$this->fail( 'Expected not_connected' );
		} catch ( OAuthException $e ) {
			$this->assertSame( 'not_connected', $e->getErrorCode() );
		}
		$this->assertCount( 0, Http_Mock::requests() );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * Saving schedules the weekly event once; clearing removes it.
	 */
	public function test_cron_follows_the_record() {
		$this->save_expiring_in( 3600 );
		$first = wp_next_scheduled( TokenRefresher::CRON_HOOK );
		$this->assertIsInt( $first );
		$this->assertSame( 'weekly', wp_get_schedule( TokenRefresher::CRON_HOOK ) );
		$this->save_expiring_in( 3600 );
		$this->assertSame( $first, wp_next_scheduled( TokenRefresher::CRON_HOOK ) );

		$this->store->clear();
		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
	}

	/**
	 * The cron callback refreshes a live record and skips a marked one.
	 */
	public function test_run_cron() {
		$this->assertSame( 10, has_action( TokenRefresher::CRON_HOOK, array( TokenRefresher::class, 'run_cron' ) ) );

		TokenRefresher::run_cron();
		$this->assertCount( 0, Http_Mock::requests() );

		$this->save_expiring_in( 3600 );
		Http_Mock::queue( Http_Mock::json( OAuth_Test_Helpers::token_response() ) );
		TokenRefresher::run_cron();
		$this->assertSame( 'rt_2', $this->store->get_refresh_token() );

		$this->store->mark_needs_signin();
		TokenRefresher::run_cron();
		$this->assertCount( 1, Http_Mock::requests() );

		$this->save_expiring_in( 3600 );
		Http_Mock::queue( new WP_Error( 'http_request_failed', 'timeout' ) );
		TokenRefresher::run_cron();
		$this->assertCount( 2, Http_Mock::requests() );
	}

	/**
	 * A signed-out registration is never scheduled or refreshed by cron.
	 */
	public function test_remnant_gets_no_cron() {
		$this->save_expiring_in( 3600 );
		$this->store->sign_out();

		TokenRefresher::schedule();
		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );

		TokenRefresher::run_cron();
		$this->assertCount( 0, Http_Mock::requests() );
	}
}
