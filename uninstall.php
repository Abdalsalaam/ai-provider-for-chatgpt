<?php
/**
 * Uninstall routine for AI Provider for ChatGPT.
 *
 * Runs when the plugin is deleted from the WordPress admin. Removes every
 * option and transient the plugin created so that no orphaned data — including
 * the encrypted OAuth token bundle — is left behind in the database.
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
		'halawa_chatgpt_thread_id',
		'halawa_chatgpt_pair_active',
		// Written by this plugin to flag the connector as "configured" for the
		// official WordPress "AI" plugin's Settings → Connectors UI.
		'connectors_ai_chatgpt_api_key',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove the plugin's pairing and rate-limit transients. WordPress has no
	// public API for prefix-matched transient deletion, so a prepared LIKE on
	// wp_options is the established pattern.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix-matched bulk delete during uninstall; no caching equivalent.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_halawa_chatgpt_pair_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_halawa_chatgpt_pair_' ) . '%'
		)
	);
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
