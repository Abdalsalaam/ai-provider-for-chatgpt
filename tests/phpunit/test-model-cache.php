<?php
/**
 * Tests for clearing the model cache without loading PSR-16 classes.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\Admin\Upgrade;
use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Cache\ModelCache;
use Halawa\ChatGptAiProvider\Cache\TransientCache;

use function Halawa\ChatGptAiProvider\can_use_transient_cache;

/**
 * Core ships the PSR interfaces prefixed, so these paths must never load TransientCache.
 */
class Test_Model_Cache extends Test_Case {

	/**
	 * ModelCache::clear() removes every ai_client_ transient and nothing else.
	 */
	public function test_clear_removes_ai_client_transients_only() {
		set_transient( 'ai_client_chatgpt_models', array( 'gpt-6-luna' ), HOUR_IN_SECONDS );
		set_transient( 'ai_client_other', 1, HOUR_IN_SECONDS );
		set_transient( 'other_plugin_cache', 1, HOUR_IN_SECONDS );

		ModelCache::clear();
		wp_cache_flush();

		$this->assertFalse( get_transient( 'ai_client_chatgpt_models' ) );
		$this->assertFalse( get_transient( 'ai_client_other' ) );
		$this->assertNotFalse( get_transient( 'other_plugin_cache' ) );
	}

	/**
	 * The 0.1.x upgrade clears the cache without loading TransientCache.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upgrade_from_legacy_record_does_not_load_transient_cache() {
		update_option( TokenStore::OPTION_NAME, 'not-a-version-2-record', false );
		set_transient( 'ai_client_models_test', array( 'old' ), DAY_IN_SECONDS );

		$this->assertTrue( ( new Upgrade() )->maybe_run() );

		wp_cache_flush();
		$this->assertSame( 'absent', get_option( TokenStore::OPTION_NAME, 'absent' ) );
		$this->assertFalse( get_transient( 'ai_client_models_test' ) );
		$this->assertFalse( class_exists( TransientCache::class, false ) );
	}

	/**
	 * Disconnecting clears the cache without loading TransientCache.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disconnect_does_not_load_transient_cache() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = rest_do_request( new WP_REST_Request( 'DELETE', '/ai-provider-for-chatgpt/v1/connection' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( class_exists( TransientCache::class, false ) );
	}

	/**
	 * TransientCache is only installed when the AI Client expects the plain PSR-16 interface.
	 */
	public function test_cache_is_only_installed_for_the_plain_interface() {
		$type = ( new ReflectionMethod( \WordPress\AiClient\AiClient::class, 'setCache' ) )->getParameters()[0]->getType();
		$name = $type instanceof ReflectionNamedType ? ltrim( $type->getName(), '\\' ) : '';

		$this->assertSame( 'Psr\\SimpleCache\\CacheInterface' === $name, can_use_transient_cache() );
	}
}
