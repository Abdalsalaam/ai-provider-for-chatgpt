<?php
/**
 * Upgrade class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Admin;

use Halawa\ChatGptAiProvider\Authentication\TokenStore;
use Halawa\ChatGptAiProvider\Cache\TransientCache;
use const Halawa\ChatGptAiProvider\VERSION;

/**
 * Migrates stored data once per plugin version and shows the reconnect notice.
 *
 * @since 1.0.0
 */
final class Upgrade {

	public const VERSION_OPTION = 'halawa_chatgpt_version';
	public const NOTICE_OPTION  = 'halawa_chatgpt_upgrade_notice';
	public const DISMISS_ACTION = 'halawa_chatgpt_dismiss_upgrade_notice';
	public const NOTICE_ID      = 'halawa-chatgpt-upgrade-notice';

	/**
	 * Token store.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param TokenStore|null $store Token store; a new one when null.
	 */
	public function __construct( ?TokenStore $store = null ) {
		$this->store = $store ?? new TokenStore();
	}

	/**
	 * Registers the admin hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'on_admin_init' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'dismiss' ) );
		add_action( 'wp_ajax_' . self::DISMISS_ACTION, array( $this, 'dismiss_ajax' ) );
	}

	/**
	 * Action callback for admin_init.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function on_admin_init(): void {
		$this->maybe_run();
	}

	/**
	 * Runs the migration when the stored version is older than this release.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the migration ran.
	 */
	public function maybe_run(): bool {
		$stored = get_option( self::VERSION_OPTION, '' );
		if ( is_string( $stored ) && '' !== $stored && version_compare( $stored, VERSION, '>=' ) ) {
			return false;
		}

		// Records from 0.1.x and unreadable ones cannot be used, so the admin signs in again.
		if ( $this->store->has_legacy_record() ) {
			$this->store->clear();
			( new TransientCache() )->clear();
			update_option( self::NOTICE_OPTION, 1, false );
		}
		delete_option( 'halawa_chatgpt_thread_id' );
		delete_option( 'halawa_chatgpt_pair_active' );
		self::delete_transients_with_prefix( 'halawa_chatgpt_pair_' );

		update_option( self::VERSION_OPTION, VERSION, false );
		return true;
	}

	/**
	 * Prints the reconnect notice for administrators while the flag is set.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( self::NOTICE_OPTION ) ) {
			return;
		}
		// A new sign-in answers the notice, so it is not shown again.
		if ( null !== $this->store->get() ) {
			delete_option( self::NOTICE_OPTION );
			return;
		}
		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );
		printf(
			'<div id="%6$s" class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a> | <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'AI Provider for ChatGPT now uses Sign in with ChatGPT. Connect your account again under Settings > ChatGPT.', 'ai-provider-for-chatgpt' ),
			esc_url( admin_url( 'options-general.php?page=' . SettingsPage::MENU_SLUG ) ),
			esc_html__( 'Connect now', 'ai-provider-for-chatgpt' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'ai-provider-for-chatgpt' ),
			esc_attr( self::NOTICE_ID )
		);
		// The core close button only hides the notice, so it also clears the flag on the server.
		wp_print_inline_script_tag(
			sprintf(
				'(function(){var n=document.getElementById(%1$s);if(!n){return;}n.addEventListener("click",function(e){if(!e.target.closest(".notice-dismiss")){return;}var d=new FormData();d.append("action",%2$s);d.append("_ajax_nonce",%3$s);fetch(%4$s,{method:"POST",body:d,credentials:"same-origin"});});})();',
				wp_json_encode( self::NOTICE_ID ),
				wp_json_encode( self::DISMISS_ACTION ),
				wp_json_encode( wp_create_nonce( self::DISMISS_ACTION ) ),
				wp_json_encode( admin_url( 'admin-ajax.php' ) )
			)
		);
	}

	/**
	 * Clears the notice flag from the notice's close button.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function dismiss_ajax(): void {
		check_ajax_referer( self::DISMISS_ACTION );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		delete_option( self::NOTICE_OPTION );
		wp_send_json_success();
	}

	/**
	 * Clears the notice flag and returns to the previous screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ai-provider-for-chatgpt' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DISMISS_ACTION );
		delete_option( self::NOTICE_OPTION );
		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Deletes every transient whose name starts with the prefix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Transient name prefix.
	 * @return void
	 */
	private static function delete_transients_with_prefix( string $prefix ): void {
		global $wpdb;
		// WordPress has no prefix lookup for transients, so the names come from the table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix lookup during a one-time migration.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%'
			)
		);
		foreach ( $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}
}
