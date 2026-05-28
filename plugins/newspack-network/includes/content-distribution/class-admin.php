<?php
/**
 * Newspack Network Content Distribution Admin Page.
 *
 * @package Newspack
 */

namespace Newspack_Network\Content_Distribution;

use Newspack\Data_Events;
use Newspack_Network\Admin as Network_Admin;
use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Hub\Node;
use Newspack_Network\Hub\Nodes;
use Newspack_Network\Site_Role;

/**
 * Content Distribution Admin Page Class.
 */
class Admin {
	/**
	 * Capability to determine whether the user can distribute content.
	 */
	const CAPABILITY = 'newspack_network_distribute';

	/**
	 * The setting section constant
	 */
	const SETTINGS_SECTION = 'newspack_content_distribution_settings';

	/**
	 * The admin page slug
	 */
	const PAGE_SLUG = 'newspack-network-distribution-settings'; // Same as the main admin page slug, it will become the first menu item.

	/**
	 * The canonical node option name
	 */
	const CANONICAL_NODE_OPTION_NAME = 'newspack_hub_canonical_node';

	/**
	 * The capability roles option name
	 */
	const CAPABILITY_ROLES_OPTION_NAME = 'newspack_distribute_capability_roles';

	/**
	 * The default distribution status option name
	 */
	const DEFAULT_DISTRIBUTION_STATUS_OPTION_NAME = 'newspack_default_distribution_status';

	/**
	 * Initialize this class and register hooks
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', [ __CLASS__, 'register_default_capability_roles' ] );
		add_action( 'init', [ __CLASS__, 'register_listeners' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_filter( 'allowed_options', [ __CLASS__, 'allowed_options' ] );
		add_action( 'update_option_' . self::CAPABILITY_ROLES_OPTION_NAME, [ __CLASS__, 'update_capability_roles' ], 10, 2 );
		add_filter( 'post_row_actions', [ __CLASS__, 'filter_row_actions' ], 15, 2 );
	}

	/**
	 * Register the listeners to the Newspack Data Events API
	 *
	 * @return void
	 */
	public static function register_listeners() {
		if ( ! class_exists( 'Newspack\Data_Events' ) ) {
			return;
		}
		Data_Events::register_listener( 'update_option_' . self::CANONICAL_NODE_OPTION_NAME, 'canonical_url_updated', [ __CLASS__, 'dispatch_canonical_url_updated_event' ] );
	}

	/**
	 * Get the canonical node setting
	 *
	 * @return ?string
	 */
	public static function get_canonical_node() {
		return get_option( self::CANONICAL_NODE_OPTION_NAME );
	}

	/**
	 * Adds the submenu page
	 *
	 * @return void
	 */
	public static function add_menu() {
		Network_Admin::add_submenu_page( __( 'Distribute', 'newspack-network' ), self::PAGE_SLUG, [ __CLASS__, 'render' ] );
	}

	/**
	 * Adds the options page to the allowed list of options
	 *
	 * @param array $allowed_options The allowed options.
	 * @return array
	 */
	public static function allowed_options( $allowed_options ) {
		$allowed_options[ self::SETTINGS_SECTION ] = [
			self::CANONICAL_NODE_OPTION_NAME,
			self::CAPABILITY_ROLES_OPTION_NAME,
			self::DEFAULT_DISTRIBUTION_STATUS_OPTION_NAME,
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
			esc_html__( 'Distribute', 'newspack-network' ),
			null,
			self::PAGE_SLUG
		);

		$settings = [];

		if ( Site_Role::is_hub() ) {
			$settings[] = [
				'key'      => self::CANONICAL_NODE_OPTION_NAME,
				'label'    => esc_html__( 'Node the Canonical URLs should point to', 'newspack-network' ),
				'callback' => [ __CLASS__, 'canonical_node_callback' ],
			];
		}

		$settings[] = [
			'key'      => self::CAPABILITY_ROLES_OPTION_NAME,
			'label'    => esc_html__( 'Roles Allowed to Distribute', 'newspack-network' ),
			'callback' => [ __CLASS__, 'capability_roles_callback' ],
		];

		$settings[] = [
			'key'      => self::DEFAULT_DISTRIBUTION_STATUS_OPTION_NAME,
			'label'    => esc_html__( 'Default Post Status for Distribution', 'newspack-network' ),
			'callback' => [ __CLASS__, 'default_distribution_status_callback' ],
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
	 * The canonical node setting callback
	 *
	 * @return void
	 */
	public static function canonical_node_callback() {
		$current = self::get_canonical_node();

		Nodes::nodes_dropdown( $current, self::CANONICAL_NODE_OPTION_NAME, __( 'Default', 'newspack-network' ) );

		printf(
			'<br/><small>%1$s</small>',
			esc_html__( 'By default, canonical URLs will point to the site where the post was created. Modify this setting if you want them to point to one of the nodes.', 'newspack-network' )
		);
		printf(
			'<br/><small>%1$s</small>',
			esc_html__( 'Note: This assumes that all sites use the same permalink structure for posts.', 'newspack-network' )
		);
	}

	/**
	 * The distribute capability roles setting callback
	 *
	 * @return void
	 */
	public static function capability_roles_callback() {
		global $wp_roles;

		foreach ( $wp_roles->roles as $role_key => $role ) {
			$role_obj = get_role( $role_key );

			// Bail if role can't edit posts.
			if ( ! $role_obj->has_cap( 'edit_posts' ) ) {
				continue;
			}

			$role_name = $role['name'];
			$role_key  = $role_obj->name;

			$checked = '';
			if ( $role_obj->has_cap( self::CAPABILITY ) || 'administrator' === $role_key ) {
				$checked = 'checked';
			}

			$disabled = '';
			if ( 'administrator' === $role_key ) {
				$disabled = 'disabled';
			}

			printf(
				'<p><input type="checkbox" name="%1$s[]" value="%2$s" id="%2$s" %3$s %4$s> <label for="%2$s">%5$s</label></p>',
				esc_attr( self::CAPABILITY_ROLES_OPTION_NAME ),
				esc_attr( $role_key ),
				esc_attr( $checked ),
				esc_attr( $disabled ),
				esc_html( $role_name )
			);
		}

		printf(
			'<br/><small>%1$s</small>',
			esc_html__( 'Select the roles of users on this site that will be allowed to distribute content to sites in the network.', 'newspack-network' )
		);
	}

	/**
	 * The default distribution status setting callback
	 *
	 * @return void
	 */
	public static function default_distribution_status_callback() {
		$current = self::get_default_distribution_status();

		$statuses = [
			'draft'   => __( 'Draft', 'newspack-network' ),
			'pending' => __( 'Pending', 'newspack-network' ),
			'publish' => __( 'Publish', 'newspack-network' ),
		];

		foreach ( $statuses as $status => $label ) {
			$checked = '';
			if ( $status === $current ) {
				$checked = 'checked';
			}

			printf(
				'<p><input type="radio" name="%1$s" value="%2$s" id="distribution-status-%2$s" %3$s> <label for="distribution-status-%2$s">%4$s</label></p>',
				esc_attr( self::DEFAULT_DISTRIBUTION_STATUS_OPTION_NAME ),
				esc_attr( $status ),
				esc_attr( $checked ),
				esc_html( $label )
			);
		}

		printf(
			'<br/><small>%1$s</small><br/><small>%2$s</small>',
			esc_html__( 'Select the default status to be used upon publishing distributed posts.', 'newspack-network' ),
			esc_html__( 'Note: Editors will still be able to modify the status upon distribution of a post.', 'newspack-network' )
		);
	}

	/**
	 * Get the default distribution status.
	 *
	 * @return string
	 */
	public static function get_default_distribution_status() {
		return get_option( self::DEFAULT_DISTRIBUTION_STATUS_OPTION_NAME, 'draft' );
	}

	/**
	 * Renders the settings page
	 *
	 * @return void
	 */
	public static function render() {
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
	 * Get the default capability roles
	 *
	 * @return array
	 */
	public static function get_default_capability_roles() {
		return [ 'administrator', 'editor', 'author' ];
	}

	/**
	 * Register the default capability roles for content distribution.
	 *
	 * @return void
	 */
	public static function register_default_capability_roles() {
		$roles         = get_option( self::CAPABILITY_ROLES_OPTION_NAME, [] );
		$default_roles = self::get_default_capability_roles();
		if ( empty( $roles ) || ! is_array( $roles ) ) {
			update_option( self::CAPABILITY_ROLES_OPTION_NAME, $default_roles );

			// Let's call this manually since the option being added for the first
			// time doesn't call the `update_option_${option}` hook.
			self::update_capability_roles( [], $default_roles );
		}
	}

	/**
	 * Update the capability roles on option update.
	 *
	 * @param mixed $old_value The old value.
	 * @param mixed $value     The new value.
	 *
	 * @return void
	 */
	public static function update_capability_roles( $old_value, $value ) {
		$selected_roles = $value;
		if ( empty( $selected_roles ) || ! is_array( $selected_roles ) ) {
			return;
		}
		// Ensure administrator role is always selected.
		if ( ! in_array( 'administrator', $selected_roles, true ) ) {
			$selected_roles[] = 'administrator';
		}
		foreach ( $selected_roles as $role ) {
			$role_obj = get_role( $role );
			if ( ! $role_obj ) {
				continue;
			}
			if ( ! $role_obj->has_cap( self::CAPABILITY ) ) {
				$role_obj->add_cap( self::CAPABILITY );
			}
		}
		$all_roles = wp_roles();
		foreach ( $all_roles->roles as $role_key => $role ) {
			$role_obj = get_role( $role_key );
			if ( ! in_array( $role_key, $selected_roles, true ) && $role_obj->has_cap( self::CAPABILITY ) ) {
				$role_obj->remove_cap( self::CAPABILITY );
			}
		}
	}

	/**
	 * Update option callback
	 *
	 * @param mixed  $old_value The old value.
	 * @param mixed  $value The new value.
	 * @param string $option The option name.
	 * @return array
	 */
	public static function dispatch_canonical_url_updated_event( $old_value, $value, $option ) {
		if ( '0' === (string) $value ) {
			return [
				'url' => get_bloginfo( 'url' ),
			];
		}
		$node     = new Node( $value );
		$node_url = $node->get_url();
		if ( ! $node_url ) {
			$node_url = '';
		}

		return [
			'url' => $node_url,
		];
	}

	/**
	 * Filter callback.
	 *
	 * Remove "quick actions" for linked posts.
	 *
	 * @param array    $actions An array of row action links.
	 * @param \WP_Post $post The post object.
	 *
	 * @return array
	 */
	public static function filter_row_actions( $actions, $post ) {
		if ( Site_Role::is_hub() || empty( $actions['inline hide-if-no-js'] ) ) {
			return $actions;
		}

		if ( Content_Distribution_Class::is_post_incoming( $post ) && ( new Incoming_Post( $post->ID ) )->is_linked() ) {
			unset( $actions['inline hide-if-no-js'] );
		}

		return $actions;
	}
}
