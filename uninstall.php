<?php
/**
 * Uninstall routine for AI Provider for ChatGPT.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes every
 * option, transient and cron event the plugin created, including the encrypted
 * token record, so no orphaned data is left in the database.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

// Bail unless WordPress invoked this file as the plugin uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes all plugin-owned options and transients on the current site.
 *
 * @since 0.1.2
 *
 * @return void
 */
function halawa_chatgpt_uninstall_cleanup_site(): void {
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
		// Flags the connector as configured on the core Settings > Connectors screen.
		'connectors_ai_chatgpt_api_key',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// The plugin autoloader is not loaded during uninstall, so the hook name is literal.
	wp_clear_scheduled_hook( 'halawa_chatgpt_refresh_tokens' );

	// Plugin transients (JWKS, pending sign-ins, 0.1.x pairing) and the SDK model cache.
	// WordPress has no prefix delete for transients, so a prepared LIKE is the pattern.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix-matched bulk delete during uninstall; no caching equivalent.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_halawa_chatgpt_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_halawa_chatgpt_' ) . '%',
			$wpdb->esc_like( '_transient_ai_client_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ai_client_' ) . '%'
		)
	);
	if ( wp_using_ext_object_cache() ) {
		wp_cache_flush_group( 'transient' );
	}
}

/**
 * Runs the cleanup on every site of the install (network-aware).
 *
 * @since 0.1.2
 *
 * @return void
 */
function halawa_chatgpt_uninstall(): void {
	if ( is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			halawa_chatgpt_uninstall_cleanup_site();
			restore_current_blog();
		}
		return;
	}

	halawa_chatgpt_uninstall_cleanup_site();
}

halawa_chatgpt_uninstall();
