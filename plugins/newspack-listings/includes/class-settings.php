<?php
/**
 * Newspack Listings Settings Page
 *
 * @package Newspack_Listings
 */

namespace Newspack_Listings;

use Newspack_Listings\Core;
use Newspack_Listings\Products;

defined( 'ABSPATH' ) || exit;

/**
 * Manages Settings page.
 */
final class Settings {
	/**
	 * Slug to use for settings page.
	 */
	const PAGE_SLUG = 'newspack-listings-settings-admin';

	/**
	 * Set up hooks.
	 */
	public static function init() {
		add_action( 'admin_init', [ __CLASS__, 'page_init' ] );
	}

	/**
	 * Get settings sections.
	 */
	public static function get_sections() {
		$sections = [
			'url'       => [
				'slug'  => 'newspack_listings_url_settings',
				'title' => __( 'Permalink Settings', 'newspack-listings' ),
			],
			'directory' => [
				'slug'  => 'newspack_listings_directory_settings',
				'title' => __( 'Automated Directory Settings', 'newspack-listings' ),
			],
			'meta'      => [
				'slug'  => 'newspack_listings_meta_settings',
				'title' => __( 'Post Meta Settings', 'newspack-listings' ),
			],
			'date'      => [
				'slug'  => 'newspack_listings_event_date_settings',
				'title' => __( 'Event Listings Date Settings', 'newspack-listings' ),
			],
		];

		// Product settings are only relevant if WooCommerce is available.
		if ( Products::is_active() ) {
			$sections['product'] = [
				'slug'        => 'newspack_listings_product_settings',
				'title'       => __( 'Self-Serve Settings', 'newspack-listings' ),
				'description' => [ __CLASS__, 'get_self_serve_section_description' ],
			];
		}

		// If Related Posts is on, add a section for it.
		if ( class_exists( 'Jetpack_RelatedPosts' ) ) {
			$sections['related'] = [
				'slug'  => 'newspack_listings_related_settings',
				'title' => __( 'Related Content Settings', 'newspack-listings' ),
			];
		}

		return $sections;
	}

	/**
	 * Output a description for the self-serve listings section.
	 */
	public static function get_self_serve_section_description() {
		$products = Products::get_products();
		$is_valid = false !== $products && ! is_wp_error( $products );
		$redirect = wp_nonce_url(
			add_query_arg(
				[ 'newspack-listings-products' => $is_valid ? Products::ACTIONS['delete'] : Products::ACTIONS['create'] ],
				menu_page_url( self::PAGE_SLUG, false )
			),
			Products::ACTION_NONCE
		);
		echo wp_kses_post(
			sprintf(
				// Translators: instructions on how to fix missing self-serve products.
				__( 'Self-serve listing features are %1$s. <a href="%2$s">Click here to %3$s self-serve listings features on this site</a>.%4$s', 'newspack-listings' ),
				$is_valid ? __( 'active', 'newspack-listings' ) : __( 'not active', 'newspack-listings' ),
				$redirect,
				$is_valid ? __( 'disable', 'newspack-listings' ) : __( 'enable', 'newspack-listings' ),
				$is_valid ? __( ' <b>Warning:</b> Disabling will cancel all existing self-serve listing subscriptions.', 'newspack-listings' ) : ''
			)
		);
	}

	/**
	 * Default values for site-wide settings.
	 *
	 * @return array Array of default settings.
	 */
	public static function get_default_settings() {
		$sections = self::get_sections();
		$settings = [
			[
				'description' => __( 'The URL prefix for all listings. This prefix will appear before the listing slug in all listing URLs.', 'newspack-listings' ),
				'key'         => 'newspack_listings_permalink_prefix',
				'label'       => __( 'Listings permalink prefix', 'newspack-listings' ),
				'type'        => 'input',
				'value'       => __( 'listings', 'newspack-listings' ),
				'allow_empty' => true,
				'section'     => $sections['url']['slug'],
			],
			[
				'description' => __( 'The URL slug for event listings.', 'newspack-listings' ),
				'key'         => 'newspack_listings_event_slug',
				'label'       => __( 'Event listings slug', 'newspack-listings' ),
				'type'        => 'input',
				'value'       => __( 'events', 'newspack-listings' ),
				'section'     => $sections['url']['slug'],
			],
			[
				'description' => __( 'The URL slug for generic listings.', 'newspack-listings' ),
				'key'         => 'newspack_listings_generic_slug',
				'label'       => __( 'Generic listings slug', 'newspack-listings' ),
				'type'        => 'input',
				'value'       => __( 'items', 'newspack-listings' ),
				'section'     => $sections['url']['slug'],
			],
			[
				'description' => __( 'The URL slug for marketplace listings.', 'newspack-listings' ),
				'key'         => 'newspack_listings_marketplace_slug',
				'label'       => __( 'Marketplace listings slug', 'newspack-listings' ),
				'type'        => 'input',
				'value'       => __( 'marketplace', 'newspack-listings' ),
				'section'     => $sections['url']['slug'],
			],
			[
				'description' => __( 'The URL slug for place listings.', 'newspack-listings' ),
				'key'         => 'newspack_listings_place_slug',
				'label'       => __( 'Place listings slug', 'newspack-listings' ),
				'type'        => 'input',
				'value'       => __( 'places', 'newspack-listings' ),
				'section'     => $sections['url']['slug'],
			],
			[
				'description' => __( 'Enables automated archives for each listing type. Archives will use the permalink slugs set above.', 'newspack-listings' ),
				'key'         => 'newspack_listings_enable_post_type_archives',
				'label'       => __( 'Enable listing type archives', 'newpack-listings' ),
				'type'        => 'checkbox',
				'value'       => false,
				'section'     => $sections['directory']['slug'],
			],
			[
				'description' => __( 'Allows listings to appear in automated category and tag archives.', 'newspack-listings' ),
				'key'         => 'newspack_listings_enable_term_archives',
				'label'       => __( 'Enable listing category/tag archives', 'newpack-listings' ),
				'type'        => 'checkbox',
				'value'       => false,
				'section'     => $sections['directory']['slug'],
			],
			[
				'description' => __( 'If listing archives are enabled, shows listings-only archives in a grid-like layout.', 'newspack-listings' ),
				'key'         => 'newspack_listings_archive_grid',
				'label'       => __( 'Show listing archives as grid', 'newpack-listings' ),
				'type'        => 'checkbox',
				'value'       => false,
				'section'     => $sections['directory']['slug'],
			],
			[
				'description' => __( 'This setting can be overridden per listing.', 'newspack-listings' ),
				'key'         => 'newspack_listings_hide_author',
				'label'       => __( 'Hide authors for listings by default', 'newpack-listings' ),
				'type'        => 'checkbox',
				'value'       => true,
				'section'     => $sections['meta']['slug'],
			],
			[
				'description' => __( 'This setting can be overridden per listing.', 'newspack-listings' ),
				'key'         => 'newspack_listings_hide_publish_date',
				'label'       => __( 'Hide publish and updated dates for listings by default', 'newpack-listings' ),
				'type'        => 'checkbox',
				'value'       => true,
				'section'     => $sections['meta']['slug'],
			],
			[
				'description' => __( 'Disables Yoast primary category functionality for all listings.', 'newspack-listings' ),
				'key'         => 'newspack_listings_disable_yoast_primary_categories',
				'label'       => __( 'Disable Yoast primary categories', 'newpack-listings' ),
				'type'        => 'checkbox',
				'value'       => false,
				'section'     => $sections['meta']['slug'],
			],
			[
				'description' => __( 'The full date format used by the Events Listings. <a href="https://wordpress.org/documentation/article/customize-date-and-time-format/">Documentation on date and time formatting.</a>', 'newspack-listings' ),
				'key'         => 'newspack_listings_events_date_format',
				'label'       => __( 'Events date format', 'newpack-listings' ),
				'type'        => 'input',
				'value'       => get_option( 'date_format', 'F j, Y' ),
				'section'     => $sections['date']['slug'],
			],
			[
				'description' => __( 'The time format used by the Events Listings.', 'newspack-listings' ),
				'key'         => 'newspack_listings_events_time_format',
				'label'       => __( 'Events time format', 'newpack-listings' ),
				'type'        => 'input',
				'value'       => get_option( 'time_format', 'g:i A' ),
				'section'     => $sections['date']['slug'],
			],
		];

		// If Related Posts is on, show the setting to hide it.
		if ( class_exists( 'Jetpack_RelatedPosts' ) ) {
			$settings[] = [
				'description' => __( 'Hide <a href="/wp-admin/admin.php?page=jetpack#/traffic">Jetpack’s Related Posts module</a> on individual listing pages.', 'newspack-listings' ),
				'key'         => 'newspack_listings_hide_jetpack_related_posts',
				'label'       => __( 'Hide Jetpack Related Posts module', 'newpack-listings' ),
				'type'        => 'checkbox',
				'value'       => true,
				'section'     => $sections['related']['slug'],
			];
		}

		// Product settings are only relevant if WooCommerce is available.
		if ( Products::is_active() ) {
			$products_are_invalid = ! Products::validate_products();
			$product_settings     = [
				[
					'description' => __( 'The base price for a single listing (no subscription).', 'newspack-listings' ),
					'key'         => Products::PRODUCT_META_KEYS['single'],
					'label'       => __( 'Single listing price', 'newpack-listings' ),
					'type'        => 'number',
					'value'       => 25,
					'section'     => $sections['product']['slug'],
					'disabled'    => $products_are_invalid,
				],
				[
					'description' => __( 'The upgrade price to make a single-purchase listing "featured."', 'newspack-listings' ),
					'key'         => Products::PRODUCT_META_KEYS['featured'],
					'label'       => __( 'Upgrade: Featured listing price', 'newpack-listings' ),
					'type'        => 'number',
					'value'       => 75,
					'section'     => $sections['product']['slug'],
					'disabled'    => $products_are_invalid,
				],
				// TODO: update all copy to be able to handle just 1 day in addition to multiple.
				[
					'description' => __( 'The number of days a single listing purchase remains live after being published. Set this value to 0 to allow purchased listings to remain live indefinitely.', 'newspack-listings' ),
					'key'         => 'newspack_listings_single_purchase_expiration',
					'label'       => __( 'Single listing expiration period', 'newpack-listings' ),
					'type'        => 'number',
					'value'       => 30,
					'section'     => $sections['product']['slug'],
					'disabled'    => $products_are_invalid,
				],
				[
					'description' => __( 'The base monthly subscription price. This fee is charged monthly.', 'newspack-listings' ),
					'key'         => Products::PRODUCT_META_KEYS['subscription'],
					'label'       => __( 'Monthly subscription listing price', 'newpack-listings' ),
					'type'        => 'number',
					'value'       => 50,
					'section'     => $sections['product']['slug'],
					'disabled'    => $products_are_invalid,
				],
				[
					'description' => __( 'The upgrade price for a premium subscription, which allows subscribers to create up to 10 additional Marketplace or Event listings. This fee is charged monthly.', 'newspack-listings' ),
					'key'         => Products::PRODUCT_META_KEYS['premium'],
					'label'       => __( 'Upgrade: Premium subscription price', 'newpack-listings' ),
					'type'        => 'number',
					'value'       => 100,
					'section'     => $sections['product']['slug'],
					'disabled'    => $products_are_invalid,
				],
			];

			$settings = array_merge( $settings, $product_settings );
		}

		return $settings;
	}

	/**
	 * Get current site-wide settings, or defaults if not set.
	 *
	 * @param string|null $option (Optional) Key name of a single setting to get. If not given, will return all settings.
	 * @param boolean     $get_default (Optional) If true, return the default value.
	 *
	 * @return array|boolean|WP_Error Array of current site-wide settings, false if returning a single option with no value,
	 *                                or WP_Error if given a key name that doesn't match a registered settings field.
	 */
	public static function get_settings( $option = null, $get_default = false ) {
		$defaults = self::get_default_settings();

		$settings = array_reduce(
			$defaults,
			function( $acc, $setting ) use ( $get_default ) {
				$key   = $setting['key'];
				$value = $get_default ? $setting['value'] : get_option( $key, $setting['value'] );

				// Guard against empty strings, which can happen if an option is set and then unset.
				if ( empty( $setting['allow_empty'] ) && '' === $value && 'checkbox' !== $setting['type'] ) {
					$value = $setting['value'];
				}

				$acc[ $key ] = $value;
				return $acc;
			},
			[]
		);

		// If passed an option key name, just give that option. If the option doesn't exist, return a WP Error.
		if ( ! empty( $option ) ) {
			if ( ! isset( $settings[ $option ] ) ) {
				return new \WP_Error(
					'newspack_listings_invalid_settings_key',
					/* translators: %s: Settings key being requested */
					sprintf( __( 'The settings key “%s” does not exist.', 'newspack-listings' ), $option )
				);
			}
			return $settings[ $option ];
		}

		// Otherwise, return all settings.
		return $settings;
	}

	/**
	 * Options page callback
	 */
	public static function create_admin_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Newspack Listings: Site-Wide Settings', 'newspack-listings' ); ?></h1>
			<form method="post" action="options.php">
					<?php
					settings_fields( 'newspack_listings_options_group' );
					do_settings_sections( self::PAGE_SLUG );
					submit_button();
					?>
			</form>
		</div>
					<?php
	}

	/**
	 * Register and add settings
	 */
	public static function page_init() {
		$sections = self::get_sections();

		foreach ( $sections as $section ) {
			$section_description = isset( $section['description'] ) ? $section['description'] : null;
			add_settings_section( $section['slug'], $section['title'], $section_description, self::PAGE_SLUG );
		}

		foreach ( self::get_default_settings() as $setting ) {
			register_setting(
				'newspack_listings_options_group',
				$setting['key']
			);
			add_settings_field(
				$setting['key'],
				$setting['label'],
				[ __CLASS__, 'newspack_listings_settings_callback' ],
				self::PAGE_SLUG,
				$setting['section'],
				$setting
			);

			// Flush permalinks when permalink option is updated.
			$is_permalink_option = preg_match( '/newspack_listings_(.*)(_prefix|_slug|_archives)/', $setting['key'] );
			if ( $is_permalink_option ) {
				add_action( 'update_option_' . $setting['key'], [ __CLASS__, 'flush_permalinks' ], 10, 3 );
			}
		}
	}

	/**
	 * Render settings fields.
	 *
	 * @param array $setting Settings array.
	 */
	public static function newspack_listings_settings_callback( $setting ) {
		$key      = $setting['key'];
		$type     = $setting['type'];
		$disabled = isset( $setting['disabled'] ) && $setting['disabled'];
		$value    = get_option( $key, $setting['value'] );

		if ( 'checkbox' === $type ) {
			printf(
				'<input type="checkbox" id="%s" name="%s" %s %s /><p class="description" for="%s">%s</p>',
				esc_attr( $key ),
				esc_attr( $key ),
				! empty( $value ) ? 'checked' : '',
				$disabled ? 'disabled' : '', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr( $key ),
				wp_kses_post( $setting['description'] )
			);
		} elseif ( 'number' === $type ) {
			if ( '' === $value && empty( $setting['allow_empty'] ) ) {
				$value = $setting['value'];
			}
			printf(
				'<input type="number" id="%s" name="%s" value="%s" class="small-text" %s /><p class="description" for="%s">%s</p>',
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value ),
				$disabled ? 'disabled' : '',
				esc_attr( $key ),
				wp_kses_post( $setting['description'] )
			);
		} else {
			if ( empty( $value ) && empty( $setting['allow_empty'] ) ) {
				$value = $setting['value'];
			}
			printf(
				'<input type="text" id="%s" name="%s" value="%s" class="regular-text" %s /><p class="description" for="%s">%s</p>',
				esc_attr( $key ),
				esc_attr( $key ),
				esc_attr( $value ),
				$disabled ? 'disabled' : '',
				esc_attr( $key ),
				wp_kses_post( $setting['description'] )
			);
		}
	}

	/**
	 * Flush permalinks automatically if updating a permalink slug option.
	 *
	 * @param mixed  $old_value Old option value.
	 * @param mixed  $new_value New option value.
	 * @param string $option Name of the option to update.
	 */
	public static function flush_permalinks( $old_value, $new_value, $option ) {
		// Prevent empty slug value.
		if ( empty( $new_value ) ) {
			$defaults = self::get_default_settings();
			$matching = array_reduce(
				$defaults,
				function( $acc, $default_option_config ) use ( $option ) {
					if ( $option === $default_option_config['key'] ) {
						$acc = $default_option_config;
					}
					return $acc;
				},
				false
			);

			if ( $matching && empty( $matching['allow_empty'] ) ) {
				return update_option( $option, $matching['value'] ); // Return early to prevent flushing rewrite rules twice.
			}
		}

		Core::activation_hook();
	}
}

if ( is_admin() ) {
	Settings::init();
}
