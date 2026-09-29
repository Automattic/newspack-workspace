<?php
/**
 * Wizard Traits - Admin Header
 * 
 * @package Newspack
 */

namespace Newspack\Wizards\Traits;

use Newspack\Newspack;

/**
 * Trait Admin_Header
 *
 * Provides methods to enqueue admin header CSS, JavaScript, and localize script data.
 *
 * @package Newspack\Wizards\Traits
 */
trait Admin_Header {
	/**
	 * Holds the admin tabs data.
	 *
	 * @var array
	 */
	protected $tabs = [];

	/**
	 * Holds the admin breadcrumb trail.
	 *
	 * @var array
	 */
	protected $breadcrumbs = [];

	/**
	 * Initialize the admin header script with localized data.
	 *
	 * @param array $args Breadcrumbs and tabs array.
	 */
	public function admin_header_init( $args = [] ) {
		$this->tabs        = $args['tabs'] ?? [];
		$this->breadcrumbs = $args['breadcrumbs'] ?? [];
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_header_enqueue' ] );
		add_action( 'in_admin_header', [ $this, 'admin_header_render' ] );
		add_filter( 'admin_body_class', [ $this, 'admin_header_body_class' ] );
	}

	/**
	 * Enqueue the admin header css, JavaScript file, and localize the data.
	 */
	public function admin_header_enqueue() {
		
		Newspack::load_common_assets();
		
		// JS.
		wp_register_script(
			'newspack-wizards-admin-header',
			Newspack::plugin_url() . '/dist/admin-header.js',
			[ 'wp-components' ],
			Newspack::asset_version( 'admin-header' ),
			true
		);

		// Localized data.
		$screen      = get_current_screen();
		$breadcrumbs = apply_filters(
			'newspack_wizards_admin_header_breadcrumbs',
			$this->breadcrumbs,
			$screen ? $screen->id : ''
		);
		// The filter is public, so a misbehaving callback could return a non-array
		// value. Fall back to the default trail so array_values() can't fatal.
		if ( ! is_array( $breadcrumbs ) ) {
			$breadcrumbs = $this->breadcrumbs;
		}
		wp_enqueue_script( 'newspack-wizards-admin-header' );
		wp_localize_script(
			'newspack-wizards-admin-header',
			'newspackWizardsAdminHeader',
			[
				'tabs'        => $this->tabs,
				'breadcrumbs' => array_values( $breadcrumbs ),
			]
		);

		// CSS.
		wp_register_style(
			'newspack-wizards-admin-header',
			Newspack::plugin_url() . '/dist/admin-header.css',
			[],
			Newspack::asset_version( 'admin-header' )
		);
		wp_style_add_data( 'newspack-wizards-admin-header', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-wizards-admin-header' );
	}

	/**
	 * Render the mount node for the admin-header React app. The React `<Page>`
	 * replaces this node's contents on mount; it is intentionally empty so the
	 * pre-hydration state carries no legacy header markup.
	 */
	public function admin_header_render() {
		?>
		<div id="newspack-wizards-admin-header" class="newspack-wizards-admin-header"></div>
		<?php
	}

	/**
	 * Add body class for admin header.
	 * 
	 * @param string $classes The current body classes.
	 */
	public function admin_header_body_class( $classes ) {
		return $classes . ' newspack-admin-header';
	}
}
