<?php
/**
 * ModelCache class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Cache;

/**
 * Clears the cached AI Client model lists without loading any PSR-16 class.
 *
 * WordPress core bundles the AI Client with prefixed PSR interfaces, so code
 * that only needs to clear the cache must not depend on TransientCache.
 *
 * @since 1.0.1
 */
final class ModelCache {

	/**
	 * Deletes every ai_client_ transient, including the plugin's own model list.
	 *
	 * @since 1.0.1
	 *
	 * @return void
	 */
	public static function clear(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix-matched bulk delete; WordPress has no API for it.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ai_client_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ai_client_' ) . '%'
			)
		);
		if ( wp_using_ext_object_cache() && function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'transient' );
		}
	}
}
