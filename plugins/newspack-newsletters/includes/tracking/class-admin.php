<?php
/**
 * Newspack Newsletters Tracking Admin UI Tweaks.
 *
 * @package Newspack
 */

namespace Newspack_Newsletters\Tracking;

/**
 * Tracking Admin Class.
 */
final class Admin {
	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'add_option_newspack_newsletters_use_tracking_pixel', [ __CLASS__, 'updated_option' ] );
		add_action( 'add_option_newspack_newsletters_use_click_tracking', [ __CLASS__, 'updated_option' ] );
		add_action( 'update_option_newspack_newsletters_use_tracking_pixel', [ __CLASS__, 'updated_option' ] );
		add_action( 'update_option_newspack_newsletters_use_click_tracking', [ __CLASS__, 'updated_option' ] );

		// Newsletters Ads columns.
		add_action( 'manage_' . \Newspack_Newsletters\Ads::CPT . '_posts_columns', [ __CLASS__, 'manage_ads_columns' ] );
		add_action( 'manage_' . \Newspack_Newsletters\Ads::CPT . '_posts_custom_column', [ __CLASS__, 'custom_ads_column' ], 10, 2 );
		add_action( 'manage_edit-' . \Newspack_Newsletters\Ads::CPT . '_sortable_columns', [ __CLASS__, 'sortable_ads_columns' ] );

		// Sorting.
		add_action( 'pre_get_posts', [ __CLASS__, 'handle_sorting' ] );
	}

	/**
	 * Whether tracking pixel is enabled.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public static function is_tracking_pixel_enabled() {
		return (bool) get_option( 'newspack_newsletters_use_tracking_pixel', true );
	}

	/**
	 * Whether click tracking is enabled.
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	public static function is_tracking_click_enabled() {
		return (bool) get_option( 'newspack_newsletters_use_click_tracking', true );
	}

	/**
	 * Flush rewrite rules upon successful update of tracking options.
	 */
	public static function updated_option() {
		\flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
	}

	/**
	 * Add settings page submenu.
	 */
	public static function add_settings_page() {
		\add_submenu_page(
			'edit.php?post_type=' . \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
			esc_html__( 'Newsletters Ads Tracking Options', 'newspack-newsletters' ),
			esc_html__( 'Ads Tracking', 'newspack-newsletters' ),
			'manage_options',
			'newspack-newsletters-tracking',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Create settings page.
	 */
	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Newsletters Ads Tracking Options', 'newspack-newsletters' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				\settings_fields( 'newspack_newsletters_tracking' );
				\do_settings_sections( 'newspack-newsletters-tracking' );
				\submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get config for all availbable settings.
	 *
	 * @return array
	 */
	public static function get_settings_config() {
		return [
			[
				'name'              => 'newspack_newsletters_use_tracking_pixel',
				'type'              => 'boolean',
				'label_for'         => 'use_tracking_pixel',
				'description'       => __( 'Track the impressions of ads in your newsletter', 'newspack-newsletters' ),
				'sanitize_callback' => [ __CLASS__, 'sanitize_boolean' ],
				'default'           => true,
			],
			[
				'name'              => 'newspack_newsletters_use_click_tracking',
				'type'              => 'boolean',
				'label_for'         => 'use_click_tracking',
				'description'       => __( 'Track the clicks on ads in your newsletter', 'newspack-newsletters' ),
				'sanitize_callback' => [ __CLASS__, 'sanitize_boolean' ],
				'default'           => true,
			],
		];
	}

	/**
	 * Register and add settings
	 */
	public static function register_settings() {
		\add_settings_section(
			'newspack_newsletters_tracking',
			null,
			null,
			'newspack-newsletters-tracking'
		);
		$config = self::get_settings_config();
		foreach ( $config as $setting ) {
			\register_setting(
				'newspack_newsletters_tracking',
				$setting['name'],
				[
					'type'              => $setting['type'],
					'description'       => $setting['description'],
					'sanitize_callback' => $setting['sanitize_callback'],
					'default'           => $setting['default'],
				]
			);
			\add_settings_field(
				$setting['name'],
				$setting['description'],
				[ __CLASS__, 'field_callback' ],
				'newspack-newsletters-tracking',
				'newspack_newsletters_tracking',
				$setting
			);
		}
	}

	/**
	 * Sanitize a value for storage in the options table.
	 * Forces a boolean value that's then converted to an int, otherwise update_option will fail if passing `false`.
	 *
	 * @param mixed $value Value to sanitize.
	 *
	 * @return int
	 */
	public static function sanitize_boolean( $value ) {
		return (int) boolval( $value );
	}

	/**
	 * Settings callback.
	 *
	 * @param array $setting Setting config.
	 */
	public static function field_callback( $setting ) {
		$type    = $setting['type'] ?? '';
		$default = $setting['default'] ?? false;
		switch ( $type ) {
			case 'boolean':
				?>
				<input
					id="<?php echo esc_attr( $setting['label_for'] ); ?>"
					type="checkbox"
					name="<?php echo esc_attr( $setting['name'] ); ?>"
					value="1"
					<?php checked( 1, get_option( $setting['name'], $default ) ); ?>
				/>
				<?php
				break;
			case 'text':
				?>
				<input
					id="<?php echo esc_attr( $setting['label_for'] ); ?>"
					type="text"
					name="<?php echo esc_attr( $setting['name'] ); ?>"
					value="<?php echo esc_attr( get_option( $setting['name'], $default ) ); ?>"
				/>
				<?php
				break;
			default:
				?>
				<input
					id="<?php echo esc_attr( $setting['label_for'] ); ?>"
					type="hidden"
					name="<?php echo esc_attr( $setting['name'] ); ?>"
					value="<?php echo esc_attr( get_option( $setting['name'], $default ) ); ?>"
				/>
				<?php
				break;
		}
	}

	/**
	 * Manage ads columns.
	 *
	 * @param array $columns Columns.
	 */
	public static function manage_ads_columns( $columns ) {
		$columns['impressions'] = __( 'Impressions', 'newspack-newsletters' );
		$columns['clicks']      = __( 'Clicks', 'newspack-newsletters' );
		return $columns;
	}

	/**
	 * Custom ads column content.
	 *
	 * @param array $column_name Column name.
	 * @param int   $post_id     Post ID.
	 */
	public static function custom_ads_column( $column_name, $post_id ) {
		if ( 'impressions' === $column_name ) {
			echo intval( get_post_meta( $post_id, 'tracking_impressions', true ) );
		} elseif ( 'clicks' === $column_name ) {
			echo intval( get_post_meta( $post_id, 'tracking_clicks', true ) );
		}
	}

	/**
	 * Sortable ads columns.
	 *
	 * @param array $columns Columns.
	 */
	public static function sortable_ads_columns( $columns ) {
		$columns['impressions'] = 'impressions';
		$columns['clicks']      = 'clicks';
		return $columns;
	}

	/**
	 * Handle sorting.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function handle_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT === $query->get( 'post_type' ) ) {
			$orderby = $query->get( 'orderby' );
			if ( 'opened' === $orderby ) {
				$query->set( 'meta_key', 'tracking_pixel_seen' );
				$query->set( 'orderby', 'meta_value_num' );
			} elseif ( 'clicks' === $orderby ) {
				$query->set( 'meta_key', 'tracking_clicks' );
				$query->set( 'orderby', 'meta_value_num' );
			}
		}

		if ( \Newspack_Newsletters\Ads::CPT === $query->get( 'post_type' ) ) {
			$orderby = $query->get( 'orderby' );
			if ( 'impressions' === $orderby ) {
				$query->set( 'meta_key', 'tracking_impressions' );
				$query->set( 'orderby', 'meta_value_num' );
			} elseif ( 'clicks' === $orderby ) {
				$query->set( 'meta_key', 'clicks' );
				$query->set( 'orderby', 'meta_value_num' );
			}
		}
	}
}
Admin::init();
