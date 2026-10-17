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

// The plugin is inactive here, so its autoloader is loaded for the token store.
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// Guarded so the routine can run again in the same request, as the tests do.
if ( ! function_exists( 'halawa_chatgpt_uninstall' ) ) {
	/**
	 * Revokes a connected record at OpenAI so its refresh token does not outlive the plugin.
	 *
	 * Best effort: a short timeout, every failure ignored, the cleanup always continues.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function halawa_chatgpt_uninstall_revoke(): void {
		if ( ! class_exists( 'Halawa\\ChatGptAiProvider\\Authentication\\TokenStore' ) ) {
			return;
		}
		try {
			$store         = new \Halawa\ChatGptAiProvider\Authentication\TokenStore();
			$client_id     = $store->get_client_id();
			$refresh_token = $store->get_refresh_token();
			if ( null === $client_id || null === $refresh_token ) {
				return;
			}
			// The main plugin file with its endpoint constants is not loaded, so the URL is literal.
			wp_remote_post(
				'https://auth.openai.com/api/accounts/oauth/revoke',
				array(
					'timeout' => 5,
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
						'Accept'       => 'application/json',
					),
					'body'    => array(
						'token'           => $refresh_token,
						'token_type_hint' => 'refresh_token',
						'client_id'       => $client_id,
					),
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
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

		halawa_chatgpt_uninstall_revoke();

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

		// The hook name is literal so cleanup does not depend on the autoloader.
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
		// Some persistent object caches cannot flush a single group.
		if ( wp_using_ext_object_cache() && wp_cache_supports( 'flush_group' ) ) {
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
}

halawa_chatgpt_uninstall();
