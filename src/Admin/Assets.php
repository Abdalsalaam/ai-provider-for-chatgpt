<?php
/**
 * Assets class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Admin;

/**
 * Enqueues the compiled React bundle and stylesheet on the Settings → ChatGPT
 * screen, plus a small bootstrap payload (REST root / nonce) used by the JS.
 *
 * The bundle and its companion `index.asset.php` (dependencies + hashed
 * version) are produced by `npm run build` and ship under `build/`.
 *
 * @since 0.1.0
 */
class Assets {

	private const HANDLE = 'ai-provider-for-chatgpt-admin';

	/**
	 * Hooks the enqueue callback on admin_enqueue_scripts.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the script and styles on the settings page only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . SettingsPage::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$plugin_root = dirname( __DIR__, 2 );
		$build_dir   = $plugin_root . '/build';
		$build_url   = plugins_url( 'build', $plugin_root . '/ai-provider-for-chatgpt.php' );
		$asset_file  = $build_dir . '/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_build_notice' ) );
			return;
		}

		$asset = include $asset_file;
		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) ) {
			add_action( 'admin_notices', array( $this, 'render_missing_build_notice' ) );
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			$build_url . '/index.js',
			$asset['dependencies'],
			$asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_set_script_translations( self::HANDLE, 'ai-provider-for-chatgpt' );

		$css_file = $build_dir . '/style-index.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$build_url . '/style-index.css',
				array( 'wp-components' ),
				$asset['version']
			);
		} else {
			wp_enqueue_style( 'wp-components' );
		}

		wp_add_inline_script(
			self::HANDLE,
			'window.aiProviderForChatGpt = ' . wp_json_encode(
				array(
					'restNamespace' => 'ai-provider-for-chatgpt/v1',
					'restRoot'      => esc_url_raw( rest_url() ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'adminUrl'      => esc_url_raw( admin_url() ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Admin notice shown when the JS bundle has not been built yet.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_missing_build_notice(): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__(
			'AI Provider for ChatGPT: the admin UI bundle is missing. Run `npm install && npm run build` inside the plugin directory.',
			'ai-provider-for-chatgpt'
		);
		echo '</p></div>';
	}
}
