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
	 * Holds the admin title.
	 *
	 * @var string
	 */
	protected $title = '';

	/**
	 * Initialize the admin header script with localized data.
	 *
	 * @param array $args Title and tabs array.
	 */
	public function admin_header_init( $args = [] ) {
		$this->tabs = $args['tabs'] ?? array();
		$this->title = $args['title'] ?? __( 'Newspack Settings', 'newspack-plugin' );
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
		$wizards_admin_header = include dirname( NEWSPACK_PLUGIN_FILE ) . '/dist/admin-header.asset.php';
		wp_register_script(
			'newspack-wizards-admin-header',
			Newspack::plugin_url() . '/dist/admin-header.js',
			[ 'wp-components' ],
			$wizards_admin_header['version'] ?? NEWSPACK_PLUGIN_VERSION,
			true
		);

		// Localized data.
		wp_enqueue_script( 'newspack-wizards-admin-header' );
		wp_localize_script(
			'newspack-wizards-admin-header',
			'newspackWizardsAdminHeader',
			[
				'tabs'  => $this->tabs,
				'title' => $this->title,
			] 
		);

		// CSS.
		wp_register_style(
			'newspack-wizards-admin-header',
			Newspack::plugin_url() . '/dist/admin-header.css',
			[],
			NEWSPACK_PLUGIN_VERSION
		);
		wp_style_add_data( 'newspack-wizards-admin-header', 'rtl', 'replace' );
		wp_enqueue_style( 'newspack-wizards-admin-header' );
	}

	/**
	 * Add necessary markup to bind React app to. The initial markup is replaced by React app and serves as a loading screen.
	 */
	public function admin_header_render() {
		?>
		<div id="newspack-wizards-admin-header" class="newspack-wizards-admin-header">
			<div class="newspack-wizard__header">
				<div class="newspack-wizard__header__inner">
					<div class="newspack-wizard__title">
						<svg xmlns="http://www.w3.org/2000/svg" height="36" width="36" viewBox="0 0 32 32" class="newspack-icon" aria-hidden="true" focusable="false">
							<path fill-rule="evenodd" clip-rule="evenodd" d="M32 16c0 8.836-7.164 16-16 16-8.837 0-16-7.164-16-16S7.164 0 16 0s16 7.164 16 16zm-10.732.622h1.72v-1.124h-2.823l1.103 1.124zm-3.249-3.31h4.97v-1.124h-6.072l1.102 1.124zm-3.248-3.31h8.217V8.877h-9.32l1.103 1.125zM9.01 8.877l13.977 14.246h-4.66l-5.866-5.98v5.98h-3.45V8.877z" />
						</svg>
						<div>
							<h2><?php _e( 'Loading...', 'newspack-plugin' ); ?></h2>
						</div>
					</div>
				</div>
			</div>
			<?php
			if ( ! empty( $this->tabs ) ) {
			// phpcs:ignore Generic.WhiteSpace.ScopeIndent.IncorrectExact
			?>
			<div class="newspack-tabbed-navigation">
				<ul>
					<li><a href="#"></a></li>
				</ul>
			</div>
			<?php // phpcs:ignore Generic.WhiteSpace.ScopeIndent.Incorrect
			} 
			?>
		</div>
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
