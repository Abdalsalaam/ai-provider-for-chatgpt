<?php
/**
 * SettingsPage class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Admin;

/**
 * Registers the Settings → ChatGPT submenu and renders the mount point that
 * the React admin app (built from client/) hydrates into.
 *
 * The React app uses the new @wordpress/admin-ui `<Page>` primitive, which
 * paints its own header (visual, title, subTitle, actions), so the PHP wrapper
 * deliberately omits the usual `<h1>`. We still emit the `.wrap` container so
 * WordPress core can inject admin notices in their expected slot.
 *
 * @since 0.1.0
 */
class SettingsPage {

	public const MENU_SLUG = 'ai-provider-for-chatgpt';
	public const MOUNT_ID  = 'ai-provider-for-chatgpt-root';

	/**
	 * Registers admin_menu.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Registers the Settings submenu page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'ChatGPT', 'ai-provider-for-chatgpt' ),
			__( 'ChatGPT', 'ai-provider-for-chatgpt' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the React mount point and a noscript fallback.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div id="<?php echo esc_attr( self::MOUNT_ID ); ?>" class="ai-provider-for-chatgpt-root">
			<noscript>
				<div class="notice notice-error">
					<p>
						<?php esc_html_e( 'This settings page requires JavaScript.', 'ai-provider-for-chatgpt' ); ?>
					</p>
				</div>
			</noscript>
		</div>
		<?php
	}
}
