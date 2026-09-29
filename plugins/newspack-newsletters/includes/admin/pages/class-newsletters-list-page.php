<?php
/**
 * Newsletters list admin page (React DataView).
 *
 * Replaces the classic WP_List_Table for the newsletters CPT. Lives
 * under the existing CPT menu so the menu structure is preserved.
 *
 * @package Newspack_Newsletters
 */

namespace Newspack\Newsletters\Admin\Pages;

use Newspack_Newsletters;

defined( 'ABSPATH' ) || exit;

/**
 * "All Newsletters" page — registered in both modes.
 */
class Newsletters_List_Page extends Hidden_React_List_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-newsletters-list';

	/**
	 * Get the page label. Matches the auto-generated CPT submenu
	 * label so the menu reads identically before and after the swap.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'All Newsletters', 'newspack-newsletters' );
	}

	/**
	 * Register under the newsletters CPT parent. `Admin_Shell_Menu::register_menu`
	 * removes the visible submenu because `is_hidden_from_menu()`.
	 *
	 * @return string
	 */
	public function get_parent_slug(): string {
		return 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Visible click target — the auto-generated "All Newsletters" submenu.
	 *
	 * @return string
	 */
	public function get_submenu_file(): ?string {
		return 'edit.php?post_type=' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Classic CPT list screen the React page shadows.
	 *
	 * @return string
	 */
	public function get_legacy_screen_id(): ?string {
		return 'edit-' . Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Post type the React page lives under in the admin URL.
	 *
	 * @return string
	 */
	public function get_redirect_post_type(): string {
		return Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT;
	}

	/**
	 * Post locks are exposed over Heartbeat only (`wp_check_locked_posts()`),
	 * so the list can flag newsletters someone else is editing.
	 *
	 * A sibling enqueue rather than a dependency of the admin-shell bundle.
	 * The bundle mounts on `domReady`, by which point every footer script has
	 * run, so a dependency would buy no ordering guarantee, and
	 * `WP_Dependencies::all_deps()` drops a handle whose dependency is
	 * unregistered: on a site running any of the plugins that deregister
	 * `heartbeat`, that would take the whole screen down rather than the
	 * indicator. Enqueueing it separately keeps the failure local, and the
	 * hook already no-ops when `wp.heartbeat` is absent.
	 *
	 * @param string $handle Admin-shell script handle.
	 */
	public function enqueue_extras( string $handle ): void {
		unset( $handle );
		wp_enqueue_script( 'heartbeat' );
	}

	/**
	 * Explicit admin-header breadcrumb trail.
	 *
	 * @return array<array{label: string}>
	 */
	public function get_wizard_breadcrumbs(): ?array {
		return [
			[ 'label' => __( 'Newsletters', 'newspack-newsletters' ) ],
			[ 'label' => __( 'All Newsletters', 'newspack-newsletters' ) ],
		];
	}
}
