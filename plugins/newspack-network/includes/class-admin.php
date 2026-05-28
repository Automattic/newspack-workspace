<?php
/**
 * Newspack Network plugin administration screen handling.
 *
 * @package Newspack
 */

namespace Newspack_Network;

use Newspack_Network\Utils\Sites;

/**
 * Class to handle the plugin admin pages
 */
class Admin {
	const PAGE_SLUG = 'newspack-network';

	/**
	 * The setting section constant
	 */
	const SETTINGS_SECTION = 'newspack_network_settings';

	/**
	 * The action name for the link-site functionality.
	 */
	const LINK_ACTION_NAME = 'newspack-network-link-site';

	/**
	 * Runs the initialization.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_filter( 'allowed_options', [ __CLASS__, 'allowed_options' ] );
		add_action( 'admin_bar_menu', [ __CLASS__, 'admin_bar_menu' ], 100 );
	}

	/**
	 * Adds the options page to the allowed list of options
	 *
	 * @param array $allowed_options The allowed options.
	 * @return array
	 */
	public static function allowed_options( $allowed_options ) {
		$allowed_options[ self::SETTINGS_SECTION ] = [
			Site_Role::OPTION_NAME,
		];
		return $allowed_options;
	}

	/**
	 * Register the settings
	 *
	 * @return void
	 */
	public static function register_settings() {

		add_settings_section(
			self::SETTINGS_SECTION,
			esc_html__( 'Newspack Network Settings', 'newspack-network' ),
			[ __CLASS__, 'section_callback' ],
			self::PAGE_SLUG
		);

		$settings = [
			[
				'key'      => Site_Role::OPTION_NAME,
				'label'    => esc_html__( 'Site Role', 'newspack-network' ),
				'callback' => [ __CLASS__, 'site_role_callback' ],
				'args'     => [
					'sanitize_callback' => [ 'Newspack_Network\Site_Role', 'sanitize' ],
				],
			],
		];
		foreach ( $settings as $setting ) {
			add_settings_field(
				$setting['key'],
				$setting['label'],
				$setting['callback'],
				self::PAGE_SLUG,
				self::SETTINGS_SECTION
			);
			register_setting(
				self::PAGE_SLUG,
				$setting['key'],
				$setting['args'] ?? []
			);
		}
	}

	/**
	 * The Settings page callback
	 *
	 * @return void
	 */
	public static function section_callback() {
		// Nothing here for now.
	}

	/**
	 * The hub_url setting callback
	 *
	 * @return void
	 */
	public static function site_role_callback() {
		$role = Site_Role::get();
		?>
		<select name="<?php echo esc_attr( Site_Role::OPTION_NAME ); ?>">
			<option value="">---</option>
			<option value="node" <?php selected( 'node', $role ); ?>><?php esc_html_e( 'This site is a node in a network', 'newspack-network' ); ?></option>
			<option value="hub" <?php selected( 'hub', $role ); ?>><?php esc_html_e( 'This site acts as the Network Hub', 'newspack-network' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Adds the admin page
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		$icon        = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjE4cHgiIGhlaWdodD0iNjE4cHgiIHZpZXdCb3g9IjAgMCA2MTggNjE4IiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiPgogICAgPGcgaWQ9IlBhZ2UtMSIgc3Ryb2tlPSJub25lIiBzdHJva2Utd2lkdGg9IjEiIGZpbGw9Im5vbmUiIGZpbGwtcnVsZT0iZXZlbm9kZCI+CiAgICAgICAgPHBhdGggZD0iTTMwOSwwIEM0NzkuNjU2NDk1LDAgNjE4LDEzOC4zNDQyOTMgNjE4LDMwOS4wMDE3NTkgQzYxOCw0NzkuNjU5MjI2IDQ3OS42NTY0OTUsNjE4IDMwOSw2MTggQzEzOC4zNDM1MDUsNjE4IDAsNDc5LjY1OTIyNiAwLDMwOS4wMDE3NTkgQzAsMTM4LjM0NDI5MyAxMzguMzQzNTA1LDAgMzA5LDAgWiBNMTc0LDE3MSBMMTc0LDI2Mi42NzEzNTYgTDE3NS4zMDUsMjY0IEwxNzQsMjY0IEwxNzQsNDQ2IEwyNDEsNDQ2IEwyNDEsMzMwLjkxMyBMMzUzLjk5Mjk2Miw0NDYgTDQ0NCw0NDYgTDE3NCwxNzEgWiBNNDQ0LDI5OSBMMzg5LDI5OSBMNDEwLjQ3NzYxLDMyMSBMNDQ0LDMyMSBMNDQ0LDI5OSBaIE00NDQsMjM1IEwzMjcsMjM1IEwzNDguMjQ1OTE5LDI1NyBMNDQ0LDI1NyBMNDQ0LDIzNSBaIE00NDQsMTcxIEwyNjQsMTcxIEwyODUuMjkwNTEyLDE5MyBMNDQ0LDE5MyBMNDQ0LDE3MSBaIiBpZD0iQ29tYmluZWQtU2hhcGUiIGZpbGw9IiMyQTdERTEiPjwvcGF0aD4KICAgIDwvZz4KPC9zdmc+';
		$page_suffix = add_menu_page(
			__( 'Newspack Network', 'newspack-network' ),
			__( 'Newspack Network', 'newspack-network' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			$icon,
			4
		);

		self::add_submenu_page( __( 'Site Role', 'newspack-network' ), self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );

		add_action( 'load-' . $page_suffix, array( __CLASS__, 'admin_init' ) );
	}

	/**
	 * Adds a child admin page to the main Newspack Hub admin page
	 *
	 * @param string   $title The menu title.
	 * @param string   $slug The menu slug.
	 * @param callable $callback The function to be called to output the content for this page.
	 * @return string|false The resulting page's hook_suffix, or false if the user does not have the capability required.
	 */
	public static function add_submenu_page( $title, $slug, $callback ) {
		return add_submenu_page(
			self::PAGE_SLUG,
			$title,
			$title,
			'manage_options',
			$slug,
			$callback
		);
	}

	/**
	 * Renders the page content
	 *
	 * @return void
	 */
	public static function render_page() {
		?>
		<div class='wrap'>
			<?php settings_errors(); ?>
			<form method='post' action='options.php'>
			<?php
				do_settings_sections( self::PAGE_SLUG );
				settings_fields( self::SETTINGS_SECTION );
			?>
				<p class='submit'>
						<input name='submit' type='submit' id='submit' class='button-primary' value='<?php _e( 'Save Changes' ); ?>' />
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Callback for the load admin page hook.
	 *
	 * @return void
	 */
	public static function admin_init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue admin page assets.
	 *
	 * @return void
	 */
	public static function enqueue_scripts() {
	}

	/**
	 * Has experimental auditing features?
	 *
	 * @return bool True if experimental auditing features are enabled.
	 */
	public static function use_experimental_auditing_features() {
		/**
		 * Enables experimental content auditing features in the Network admin.
		 * These features are under development and may change.
		 *
		 * @constant NEWSPACK_NETWORK_EXPERIMENTAL_AUDITING_FEATURES
		 * @type     bool
		 * @default  Experimental auditing disabled
		 * @status   draft
		 *
		 * @example define( 'NEWSPACK_NETWORK_EXPERIMENTAL_AUDITING_FEATURES', true );
		 */
		return defined( 'NEWSPACK_NETWORK_EXPERIMENTAL_AUDITING_FEATURES' ) ? NEWSPACK_NETWORK_EXPERIMENTAL_AUDITING_FEATURES : false;
	}

	/**
	 * Adds the admin bar menu
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The WP_Admin_Bar instance.
	 * @return void
	 */
	public static function admin_bar_menu( $wp_admin_bar ) {
		if ( ! Site_Role::has_role() ) {
			return;
		}

		$sites = Sites::get_all_sites_without_current();
		foreach ( $sites as $site ) {
			$item_id = 'site-' . sanitize_title( $site['name'] );
			$wp_admin_bar->add_node(
				[
					'id'     => $item_id,
					'title'  => $site['name'],
					'href'   => $site['url'],
					'parent' => 'site-name',
				]
			);

			foreach ( Sites::generate_bookmarks( $site['url'] ) as $bookmark ) {
				$sub_item_id = $item_id . '-' . sanitize_title( $bookmark['label'] );
				$args        = [
					'id'     => $sub_item_id,
					'title'  => $bookmark['label'],
					'href'   => $bookmark['url'],
					'parent' => $item_id,
				];
				$wp_admin_bar->add_node( $args );
			}
		}
	}
}
