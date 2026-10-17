<?php
/**
 * Tests for TokenStore v2.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

require_once __DIR__ . '/includes/class-oauth-test-helpers.php';

/**
 * TokenStore tests.
 */
class Test_Token_Store extends Test_Case {

	/**
	 * Encrypts a plaintext payload the way TokenStore does, for legacy fixtures.
	 *
	 * @param array $payload Plaintext record.
	 * @return string Option value.
	 */
	private function encrypt( array $payload ) {
		$key   = substr( hash( 'sha256', AUTH_KEY . '|' . LOGGED_IN_KEY . '|ai-provider-for-chatgpt', true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( wp_json_encode( $payload ), $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Builds a legacy ciphertext fixture.
	}

	/**
	 * A saved record round-trips with version and last_refresh set.
	 */
	public function test_round_trip() {
		$store = new TokenStore();
		$store->save( OAuth_Test_Helpers::record() );

		$record = $store->get();
		$this->assertSame( 2, $record['version'] );
		$this->assertSame( 'at_1', $record['access_token'] );
		$this->assertEqualsWithDelta( time(), strtotime( $record['last_refresh'] ), 5 );
		$this->assertStringNotContainsString( 'at_1', (string) get_option( TokenStore::OPTION_NAME ) );
		$this->assertSame( 'chatgpt-account-managed', get_option( TokenStore::CORE_API_KEY_OPTION ) );
		$this->assertFalse( $store->has_legacy_record() );
	}

	/**
	 * Accessors read the record fields.
	 */
	public function test_accessors() {
		$store  = new TokenStore();
		$record = OAuth_Test_Helpers::record();
		$store->save( $record );

		$this->assertSame( 'at_1', $store->get_access_token() );
		$this->assertSame( 'rt_1', $store->get_refresh_token() );
		$this->assertSame( 'it_1', $store->get_id_token() );
		$this->assertSame( 'oaiapp_x', $store->get_client_id() );
		$this->assertSame( 'user_1', $store->get_subject() );
		$this->assertSame( 'ada@example.com', $store->get_email() );
		$this->assertSame( 'Ada', $store->get_name() );
		$this->assertSame( $record['ext_agent_host_id'], $store->get_host_id() );
		$this->assertSame( $record['scopes'], $store->get_scopes() );
		$this->assertSame( $record['expires_at'], $store->get_expires_at() );
		$this->assertSame( $record['earliest_refresh_at'], $store->get_earliest_refresh_at() );
		$this->assertFalse( $store->needs_signin() );
		$this->assertTrue( $store->has_plan_scope() );
		$this->assertEqualsWithDelta( time(), $store->get_last_refresh_timestamp(), 5 );
	}

	/**
	 * Without a record every accessor is empty.
	 */
	public function test_empty_store() {
		$store = new TokenStore();
		$this->assertNull( $store->get() );
		$this->assertNull( $store->get_access_token() );
		$this->assertSame( array(), $store->get_scopes() );
		$this->assertSame( 0, $store->get_expires_at() );
		$this->assertSame( 0, $store->get_last_refresh_timestamp() );
		$this->assertFalse( $store->needs_signin() );
		$this->assertFalse( $store->has_plan_scope() );
		$this->assertFalse( $store->has_legacy_record() );
	}

	/**
	 * A 0.1.x record reads as absent and is flagged as legacy.
	 */
	public function test_legacy_record() {
		update_option(
			TokenStore::OPTION_NAME,
			$this->encrypt(
				array(
					'tokens'       => array(
						'id_token'      => 'it_old',
						'access_token'  => 'at_old',
						'refresh_token' => 'rt_old',
						'account_id'    => 'acc_1',
					),
					'last_refresh' => gmdate( 'c' ),
				)
			),
			false
		);
		$store = new TokenStore();
		$this->assertNull( $store->get() );
		$this->assertNull( $store->get_access_token() );
		$this->assertTrue( $store->has_legacy_record() );
	}

	/**
	 * Garbage in the option is legacy, not a crash.
	 */
	public function test_undecryptable_option_is_legacy() {
		update_option( TokenStore::OPTION_NAME, 'not-base64-$$$', false );
		$this->assertNull( ( new TokenStore() )->get() );
		$this->assertTrue( ( new TokenStore() )->has_legacy_record() );
	}

	/**
	 * Update merges fields and refreshes last_refresh.
	 */
	public function test_update_merges() {
		$store = new TokenStore();
		$store->save( OAuth_Test_Helpers::record() );
		$store->update(
			array(
				'access_token' => 'at_9',
				'scopes'       => array( 'openid' ),
			)
		);
		$this->assertSame( 'at_9', $store->get_access_token() );
		$this->assertSame( 'rt_1', $store->get_refresh_token() );
		$this->assertFalse( $store->has_plan_scope() );
	}

	/**
	 * Update without a record does nothing.
	 */
	public function test_update_without_record_is_a_no_op() {
		( new TokenStore() )->update( array( 'access_token' => 'at_9' ) );
		$this->assertFalse( get_option( TokenStore::OPTION_NAME ) );
	}

	/**
	 * Marking needs_signin keeps identity fields and last_refresh.
	 */
	public function test_mark_needs_signin_keeps_identity() {
		$store  = new TokenStore();
		$record = OAuth_Test_Helpers::record();
		$store->save( $record );
		$before = $store->get()['last_refresh'];

		$store->mark_needs_signin();

		$after = $store->get();
		$this->assertTrue( $store->needs_signin() );
		$this->assertSame( $before, $after['last_refresh'] );
		foreach ( array( 'client_id', 'subject', 'email', 'ext_agent_host_id' ) as $field ) {
			$this->assertSame( $record[ $field ], $after[ $field ] );
		}
	}

	/**
	 * Clear removes the record and the core placeholder.
	 */
	public function test_clear() {
		$store = new TokenStore();
		$store->save( OAuth_Test_Helpers::record() );
		$store->clear();
		$this->assertFalse( get_option( TokenStore::OPTION_NAME ) );
		$this->assertSame( 'absent', get_option( TokenStore::CORE_API_KEY_OPTION, 'absent' ) );
		$this->assertNull( $store->get() );
	}

	/**
	 * Sign-out keeps only the registration fields.
	 */
	public function test_sign_out_keeps_registration_only() {
		$store  = new TokenStore();
		$record = OAuth_Test_Helpers::record( array( 'needs_signin' => true ) );
		$store->save( $record );

		$store->sign_out();

		$remnant = $store->get();
		$this->assertSame(
			array( 'client_id', 'email', 'ext_agent_host_id', 'name', 'needs_signin', 'subject', 'version' ),
			$this->sorted_keys( $remnant )
		);
		$this->assertSame( 'oaiapp_x', $remnant['client_id'] );
		$this->assertSame( $record['ext_agent_host_id'], $remnant['ext_agent_host_id'] );
		$this->assertFalse( $store->needs_signin() );
		$this->assertSame( array(), $store->get_scopes() );
		$this->assertSame( 0, $store->get_expires_at() );
		$this->assertSame( 'absent', get_option( TokenStore::CORE_API_KEY_OPTION, 'absent' ) );
	}

	/**
	 * A signed-out registration is neither connected nor legacy, and keeps no cron.
	 */
	public function test_remnant_is_not_connected_or_legacy() {
		$store = new TokenStore();
		$store->save( OAuth_Test_Helpers::record() );
		$this->assertTrue( $store->is_connected() );
		$this->assertIsInt( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );

		$store->sign_out();

		$this->assertFalse( $store->is_connected() );
		$this->assertTrue( $store->has_registration() );
		$this->assertFalse( $store->has_legacy_record() );
		$this->assertFalse( wp_next_scheduled( TokenRefresher::CRON_HOOK ) );
	}

	/**
	 * Connected needs both tokens and no pending new sign-in.
	 */
	public function test_is_connected_cases() {
		$store = new TokenStore();
		$this->assertFalse( $store->is_connected() );
		$this->assertFalse( $store->has_registration() );

		$store->save( OAuth_Test_Helpers::record( array( 'needs_signin' => true ) ) );
		$this->assertFalse( $store->is_connected() );

		$store->save( OAuth_Test_Helpers::record( array( 'refresh_token' => '' ) ) );
		$this->assertFalse( $store->is_connected() );

		$store->save( OAuth_Test_Helpers::record() );
		$this->assertTrue( $store->is_connected() );
	}

	/**
	 * Sign-out without a record does nothing.
	 */
	public function test_sign_out_without_record() {
		( new TokenStore() )->sign_out();
		$this->assertFalse( get_option( TokenStore::OPTION_NAME ) );
	}

	/**
	 * Returns the sorted keys of an array.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private function sorted_keys( array $data ) {
		$keys = array_keys( $data );
		sort( $keys );
		return $keys;
	}
}
