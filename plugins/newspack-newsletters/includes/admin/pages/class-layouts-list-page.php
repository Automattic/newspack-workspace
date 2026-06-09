<?php
/**
 * Newsletter Layouts list admin page (React DataView).
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin\Pages;

use Newspack_Newsletters;

defined( 'ABSPATH' ) || exit;

/**
 * "Layouts" list page — always registered (prebuilts ship with the plugin).
 */
class Layouts_List_Page extends React_List_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-newsletters-layouts-list';

	/**
	 * Capability required. Matches the CPT registration so the page
	 * and the REST collection align.
	 *
	 * @var string
	 */
	protected $capability = 'edit_others_posts';

	/**
	 * Get the page label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Layouts', 'newspack-newsletters' );
	}

	/**
	 * Submenu under the Newsletters CPT menu.
	 *
	 * @return string
	 */
	public function get_parent_slug(): string {
		return 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Classic CPT list screen the React page shadows — catches the
	 * back button in the layout editor.
	 *
	 * @return string|null
	 */
	public function get_legacy_screen_id(): ?string {
		// Guard against a load-order regression fatalling every wp-admin request.
		if ( ! class_exists( '\Newspack_Newsletters_Layouts' ) ) {
			return null;
		}
		return 'edit-' . \Newspack_Newsletters_Layouts::NEWSPACK_NEWSLETTERS_LAYOUT_CPT;
	}

	/**
	 * The React page lives under the newsletters CPT menu, not the
	 * layouts CPT.
	 *
	 * @return string
	 */
	public function get_redirect_post_type(): string {
		return Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Slot directly after the auto-generated "All Newsletters" / "Add
	 * New Newsletter" submenus (indices 0 and 1).
	 *
	 * @return int
	 */
	public function get_submenu_index(): ?int {
		return 2;
	}

	/**
	 * Wizard breadcrumb label.
	 *
	 * @return string
	 */
	public function get_wizard_header_label(): ?string {
		return __( 'Newsletters / Layouts', 'newspack-newsletters' );
	}

	/**
	 * BlockPreview iframes need `wp-edit-blocks` loaded before
	 * `admin-shell.css`.
	 *
	 * @return string[]
	 */
	public function get_admin_shell_style_deps(): array {
		return [ 'wp-edit-blocks' ];
	}

	/**
	 * Layouts-specific bundle: `editorBlocks` assets for BlockPreview,
	 * the email-editor data `NewsletterPreview` reads at mount, and the
	 * compiled global stylesheet injected into the preview iframe.
	 *
	 * @param string $handle Admin-shell script handle.
	 */
	public function enqueue_extras( string $handle ): void {
		// Fallback for dev mode: `Asset_Loader` skips the CSS enqueue (and its dep chain) when `dist/admin-shell.css` is missing.
		wp_enqueue_style( 'wp-edit-blocks' );

		wp_localize_script(
			$handle,
			'newspack_email_editor_data',
			\Newspack_Newsletters_Editor::get_email_editor_data()
		);

		$blocks_js = NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/editorBlocks.js';
		if ( file_exists( $blocks_js ) ) {
			wp_enqueue_script(
				'newspack-newsletters-editor-blocks',
				plugins_url( '../../../dist/editorBlocks.js', __FILE__ ),
				[],
				filemtime( $blocks_js ),
				true
			);
		}
		$blocks_css = NEWSPACK_NEWSLETTERS_PLUGIN_FILE . 'dist/editorBlocks.css';
		if ( file_exists( $blocks_css ) ) {
			wp_enqueue_style(
				'newspack-newsletters-editor-blocks',
				plugins_url( '../../../dist/editorBlocks.css', __FILE__ ),
				[],
				filemtime( $blocks_css )
			);
		}

		wp_add_inline_script(
			$handle,
			'window.newspackNewslettersGlobalStyles = ' . wp_json_encode( wp_get_global_stylesheet() ) . ';',
			'before'
		);
	}
}
