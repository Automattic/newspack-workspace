<?php
/**
 * Newspack Block Theme patterns handling.
 *
 * @package Newspack_Block_Theme
 */

namespace Newspack_Block_Theme;

defined( 'ABSPATH' ) || exit;

/**
 * Main Patterns class.
 */
final class Patterns {
	/**
	 * Initializer.
	 */
	public static function init() {
		// Prevent external patterns from loading.
		\add_filter( 'should_load_remote_block_patterns', '__return_false' );
		\add_filter( 'a8c_override_patterns_source_site', '__return_false' );
		\add_filter( 'jetpack_load_block_patterns', '__return_false' );

		// Early WooCommerce pattern prevention.
		\add_action( 'plugins_loaded', [ __CLASS__, 'prevent_woocommerce_patterns' ], 1 );
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'disable_woocommerce_patterns' ] );
		\add_action( 'wp_loaded', [ __CLASS__, 'disable_additional_woocommerce_patterns' ], \PHP_INT_MIN );

		// Register our own patterns and categories.
		\add_action( 'init', [ __CLASS__, 'block_pattern_categories' ] );
		\add_action( 'init', [ __CLASS__, 'register_nested_patterns' ] );

		// Clean up any remaining unwanted patterns.
		\add_filter( 'rest_dispatch_request', [ __CLASS__, 'restrict_patterns' ], 12, 3 );
		\add_action( 'init', [ __CLASS__, 'remove_registered_patterns' ], 999 );
	}

	/**
	 * Pattern registration methods.
	 */

	/**
	 * Registers patterns nested in the /patterns directory. WP will only
	 * register automatically the patterns which are top-level files in this directory.
	 *
	 * @see get_block_patterns
	 */
	public static function register_nested_patterns() {
		$directory = get_stylesheet_directory() . '/patterns';
		$files = self::find_nested_php_files( $directory );

		foreach ( $files as $file ) {
			$relative_path = str_replace( $directory, '', $file );
			// Check if the path is nested. Non-nested patterns will be automatically registered by WP.
			if ( substr_count( $relative_path, '/' ) === 1 ) {
				continue;
			}
			$default_headers = [
				'title'         => 'Title',
				'slug'          => 'Slug',
				'description'   => 'Description',
				'viewportWidth' => 'Viewport Width',
				'inserter'      => 'Inserter',
				'categories'    => 'Categories',
				'keywords'      => 'Keywords',
				'blockTypes'    => 'Block Types',
				'postTypes'     => 'Post Types',
				'templateTypes' => 'Template Types',
			];
			$pattern = get_file_data( $file, $default_headers );

			if ( isset( $pattern['slug'] ) ) {
				$properties_to_parse = [
					'categories',
					'keywords',
					'blockTypes',
					'postTypes',
					'templateTypes',
				];
				// For properties of type array, parse data as comma-separated.
				foreach ( $properties_to_parse as $property ) {
					if ( ! empty( $pattern[ $property ] ) ) {
						$pattern[ $property ] = array_filter( wp_parse_list( (string) $pattern[ $property ] ) );
					} else {
						unset( $pattern[ $property ] );
					}
				}

				$pattern['filePath'] = $file;
				register_block_pattern( $pattern['slug'], $pattern );
			}
		}
	}

	/**
	 * Find nested PHP files.
	 *
	 * @param string $directory The base directory to search in.
	 */
	public static function find_nested_php_files( $directory ) {
		$php_files = [];
		$items = glob( $directory . '/*' );

		foreach ( $items as $item ) {
			if ( is_dir( $item ) ) {
				$php_files = array_merge( $php_files, self::find_nested_php_files( $item ) );
			} elseif ( is_file( $item ) && pathinfo( $item, PATHINFO_EXTENSION ) === 'php' ) {
				$php_files[] = $item;
			}
		}

		return $php_files;
	}

	/**
	 * Add block pattern categories.
	 *
	 * @since Newspack Block Theme 1.0
	 */
	public static function block_pattern_categories() {
		register_block_pattern_category(
			'newspack-block-theme',
			array(
				'label'       => __( 'Newspack Theme', 'newspack-block-theme' ),
				'description' => __( 'Patterns bundled with the Newspack Block Theme.', 'newspack-block-theme' ),
			)
		);

		register_block_pattern_category(
			'newspack-block-theme-author-bio',
			array(
				'label'       => __( 'Newspack Theme - Author Bio', 'newspack-block-theme' ),
				'description' => __( 'Patterns bundled with the Newspack Block Theme, specifically built for the author biography.', 'newspack-block-theme' ),
			)
		);

		register_block_pattern_category(
			'newspack-block-theme-post-header',
			array(
				'label'       => __( 'Newspack Theme - Post Header', 'newspack-block-theme' ),
				'description' => __( 'Patterns bundled with the Newspack Block Theme, specifically built for the post header.', 'newspack-block-theme' ),
			)
		);

		register_block_pattern_category(
			'newspack-block-theme-post-meta',
			array(
				'label'       => __( 'Newspack Theme - Post Meta', 'newspack-block-theme' ),
				'description' => __( 'Patterns bundled with the Newspack Block Theme, specifically built for the post meta.', 'newspack-block-theme' ),
			)
		);
	}

	/**
	 * Pattern restriction and removal methods.
	 */

	/**
	 * Restricts block editor patterns in the editor by removing support for patterns from:
	 *   - WordPress Core
	 *   - Jetpack
	 *   - WooCommerce
	 *   - Dotorg pattern directory (Core patterns)
	 *
	 * @link https://developer.wordpress.com/docs/developer-tools/block-patterns/disable-all-patterns/
	 *
	 * @since Newspack Block Theme 1.0
	 *
	 * @param mixed           $dispatch_result Dispatch result, will be used if not empty.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @param string          $route Route matched for the request.
	 * @return mixed Dispatch result.
	 */
	public static function restrict_patterns( $dispatch_result, $request, $route ) {
		// Define the pattern prefixes to blacklist.
		$blacklisted_pattern_prefixes = [
			'core/',
			'jetpack/',
			'woocommerce/',
			'woo/',
		];

		if ( strpos( $route, '/wp/v2/block-patterns/patterns' ) === 0 ) {
			$patterns = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();

			if ( ! empty( $patterns ) ) {
				// Remove patterns that match any of the blacklisted prefixes.
				foreach ( $patterns as $pattern ) {
					$should_remove = false;

					// Preserve patterns required for the WooCommerce Coming Soon mode — these need to remain in the inserter listing and stay registered globally so the template can render at runtime.
					if ( self::is_woocommerce_coming_soon_pattern( $pattern['name'] ) ) {
						continue;
					}

					// Check if the pattern's name starts with any of the blacklisted prefixes.
					foreach ( $blacklisted_pattern_prefixes as $prefix ) {
						if ( strpos( $pattern['name'], $prefix ) === 0 ) {
							$should_remove = true;
							break;
						}
					}

					// Also check for WooCommerce patterns that might not have the expected prefix.
					if ( ! $should_remove && isset( $pattern['source'] ) && $pattern['source'] === 'plugin' ) {
						// Check if it's from WooCommerce by looking at the pattern content or other indicators.
						if ( strpos( $pattern['name'], 'wc-' ) === 0 ||
							( isset( $pattern['categories'] ) && in_array( 'woocommerce', $pattern['categories'], true ) ) ) {
							$should_remove = true;
						}
					}

					// Unregister the pattern if it matches a blacklisted prefix.
					if ( $should_remove ) {
						\unregister_block_pattern( $pattern['name'] );
					}
				}

				// Remove theme support for Core patterns from the Dotorg pattern directory.
				\remove_theme_support( 'core-block-patterns' );
			}
		}

		return $dispatch_result;
	}

	/**
	 * Remove any registered patterns that match our blacklist.
	 *
	 * @since Newspack Block Theme 1.0
	 */
	public static function remove_registered_patterns() {
		$patterns = \WP_Block_Patterns_Registry::get_instance()->get_all_registered();

		if ( empty( $patterns ) ) {
			return;
		}

		$blacklisted_patterns = [];

		foreach ( $patterns as $pattern ) {
			$pattern_name = $pattern['name'];

			// Preserve patterns required for the WooCommerce Coming Soon mode. These are
			// referenced at render time by the coming-soon block template and must remain
			// registered on the frontend.
			if ( self::is_woocommerce_coming_soon_pattern( $pattern_name ) ) {
				continue;
			}

			// Check for various WooCommerce pattern naming conventions.
			if ( strpos( $pattern_name, 'woocommerce' ) === 0 ||
				strpos( $pattern_name, 'woo-' ) === 0 ||
				strpos( $pattern_name, 'wc-' ) === 0 ||
				strpos( $pattern_name, 'core/' ) === 0 ||
				strpos( $pattern_name, 'jetpack/' ) === 0 ||
				( isset( $pattern['categories'] ) && \array_intersect( [ 'woocommerce', 'featured' ], $pattern['categories'] ) ) ) {
				$blacklisted_patterns[] = $pattern_name;
			}
		}

		// Remove blacklisted patterns.
		foreach ( $blacklisted_patterns as $pattern_name ) {
			\unregister_block_pattern( $pattern_name );
		}
	}

	/**
	 * WooCommerce-specific pattern handling methods.
	 */

	/**
	 * Check whether a pattern name belongs to the WooCommerce Coming Soon chain.
	 *
	 * The `woocommerce/coming-soon` pattern resolves at runtime to either
	 * `woocommerce/coming-soon-store-only` or `woocommerce/page-coming-soon-default`,
	 * depending on the `woocommerce_store_pages_only` option. Both prefixes must be
	 * preserved so the chain can render.
	 *
	 * @param string $pattern_name The pattern name to check.
	 * @return bool
	 */
	private static function is_woocommerce_coming_soon_pattern( $pattern_name ) {
		return strpos( $pattern_name, 'woocommerce/coming-soon' ) === 0
			|| strpos( $pattern_name, 'woocommerce/page-coming-soon' ) === 0;
	}

	/**
	 * Prevent WooCommerce patterns from being registered early.
	 *
	 * @since Newspack Block Theme 1.0
	 */
	public static function prevent_woocommerce_patterns() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Remove WooCommerce pattern registration actions early.
		if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			try {
				$container = \Automattic\WooCommerce\Blocks\Package::container();
				if ( $container && $container->has( \Automattic\WooCommerce\Blocks\BlockPatterns::class ) ) {
					\remove_action( 'init', [ $container->get( \Automattic\WooCommerce\Blocks\BlockPatterns::class ), 'register_block_patterns' ], 9 );
					\remove_action( 'init', [ $container->get( \Automattic\WooCommerce\Blocks\BlockPatterns::class ), 'register_ptk_patterns' ], 9 );
				}
			} catch ( \Exception $e ) {
				// Silently fail.
				unset( $e );
			}
		}

		// Prevent individual block types from registering patterns.
		\add_action( 'init', [ __CLASS__, 'remove_woocommerce_block_patterns' ], 5 );
	}

	/**
	 * Disable WooCommerce block patterns.
	 *
	 * Removes the main block pattern registration actions.
	 *
	 * @since Newspack Block Theme 1.0
	 */
	public static function disable_woocommerce_patterns() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
			return;
		}

		try {
			\remove_action( 'init', [ \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\BlockPatterns::class ), 'register_block_patterns' ] );
			\remove_action( 'init', [ \Automattic\WooCommerce\Blocks\Package::container()->get( \Automattic\WooCommerce\Blocks\BlockPatterns::class ), 'register_ptk_patterns' ] );
		} catch ( \Exception $e ) {
			// Silently fail if WooCommerce structure has changed.
			// This is intentional - we don't want to break the site if WooCommerce changes.
			unset( $e );
		}
	}

	/**
	 * Disable additional WooCommerce block patterns.
	 *
	 * Removes additional pattern registration actions that may be registered later.
	 *
	 * @since Newspack Block Theme 1.0
	 */
	public static function disable_additional_woocommerce_patterns() {
		global $wp_filter;

		if ( ! isset( $wp_filter['wp_loaded']->callbacks[10] ) ) {
			return;
		}

		foreach ( $wp_filter['wp_loaded']->callbacks[10] as $filter ) {
			if ( ! \is_callable( $filter['function'] ) ) {
				continue;
			}

			if ( ! \is_array( $filter['function'] ) ) {
				continue;
			}

			if (
				( $filter['function'][0] instanceof \Automattic\WooCommerce\Blocks\BlockTypes\Cart && $filter['function'][1] === 'register_patterns' )
				|| ( $filter['function'][0] instanceof \Automattic\WooCommerce\Blocks\BlockTypesController && $filter['function'][1] === 'register_block_patterns' )
			) {
				\remove_action( 'wp_loaded', $filter['function'] );
			}
		}
	}

	/**
	 * Remove WooCommerce block patterns from specific block types.
	 *
	 * @since Newspack Block Theme 1.0
	 */
	public static function remove_woocommerce_block_patterns() {
		// List of WooCommerce block types that might register patterns.
		$woocommerce_blocks = [
			'woocommerce/cart',
			'woocommerce/checkout',
			'woocommerce/product-gallery',
			'woocommerce/mini-cart',
			'woocommerce/product-collection',
		];

		foreach ( $woocommerce_blocks as $block_name ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $block_name ) ) {
				$block_type = \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
				if ( $block_type && method_exists( $block_type, 'register_patterns' ) ) {
					\remove_action( 'init', [ $block_type, 'register_patterns' ] );
				}
			}
		}
	}
}

Patterns::init();
