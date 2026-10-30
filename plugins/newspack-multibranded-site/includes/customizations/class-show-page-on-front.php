<?php
/**
 * Newspack Multibranded site taxonomy.
 *
 * @package Newspack
 */

namespace Newspack_Multibranded_Site\Customizations;

use Newspack_Multibranded_Site\Meta\Show_Page_On_Front as Show_Page_On_Front_Meta;
use Newspack_Multibranded_Site\Taxonomy;
use WP_Term;

/**
 * Class to handle the Show_Page_On_Front Customization
 */
class Show_Page_On_Front {

	/**
	 * Option used to store all pages that are been used as Front pages
	 *
	 * This allows us to easily identify if a given page is the front page of a brand
	 *
	 * The option itself is an array where the keys are the page IDs and the values the brand IDs
	 */
	const FRONT_PAGES_OPTION_KEY = 'newspack_multibranded_site_front_pages';

	/**
	 * Whether the query has been filtered in the current request
	 *
	 * @var boolean
	 */
	protected static $filtered = false;

	/**
	 * The ID of the brand whose front page is being displayed, if any.
	 *
	 * @var ?int
	 */
	protected static $filtered_brand_id = null;

	/**
	 * Initializes
	 */
	public static function init() {
		add_action( 'pre_get_posts', [ __CLASS__, 'pre_get_posts' ], 20 );
		add_filter( 'body_class', [ __CLASS__, 'body_class' ], 10, 2 );
		add_filter( 'template_include', [ __CLASS__, 'template_include' ] );
		add_action( 'updated_term_meta', [ __CLASS__, 'on_term_meta_update' ], 10, 4 );
		add_action( 'added_term_meta', [ __CLASS__, 'on_term_meta_update' ], 10, 4 );
		add_filter( 'page_link', [ __CLASS__, 'filter_page_link' ], 10, 2 );
	}

	/**
	 * Checks whether the current request is being filtered by this customization
	 *
	 * @return boolean
	 */
	public static function is_filtered() {
		return self::$filtered;
	}

	/**
	 * Returns the ID of the brand whose front page is being displayed.
	 *
	 * Authoritative on a brand front page: it is the displayed brand, regardless of any
	 * brand terms assigned to the cover page itself.
	 *
	 * @return ?int
	 */
	public static function get_filtered_brand_id() {
		return self::$filtered_brand_id;
	}

	/**
	 * Change the query if we want to display a page on front
	 *
	 * @param \WP_Query $query The WP_Query object.
	 * @return void
	 */
	public static function pre_get_posts( &$query ) {
		// Bail before the reset so secondary queries can't clear the flag.
		if ( ! $query->is_main_query() ) {
			return;
		}

		self::$filtered          = false;
		self::$filtered_brand_id = null;

		if ( is_admin() || is_feed() ) {
			return;
		}

		if ( empty( $query->query[ Taxonomy::SLUG ] ) ) {
			return;
		}

		$brand_slug = $query->query[ Taxonomy::SLUG ];
		$term       = get_term_by( 'slug', $brand_slug, Taxonomy::SLUG );

		if ( $term ) {
			$show_page_on_front = get_term_meta( $term->term_id, Show_Page_On_Front_Meta::get_key(), true );
			if ( ! empty( $show_page_on_front ) ) {
				$page = get_page( $show_page_on_front );
				if ( $page ) {
					// Pass the query as an argument so parse_query() resets the flags; otherwise the
					// brand archive's is_archive/is_tax survive and consumers misread the page as an archive.
					$query->parse_query( [ 'page_id' => $page->ID ] );
					self::$filtered          = true;
					self::$filtered_brand_id = (int) $term->term_id;
				}
			}
		}
	}

	/**
	 * Adds the front page body class when the brand displays a page on front.
	 *
	 * Core supplies the page-* classes; the theme only adds newspack-front-page for the
	 * site's own front page, so brand front pages need it added here.
	 *
	 * @param string[] $classes An array of body class names.
	 * @param string[] $class   An array of additional class names added to the body.
	 * @return array
	 */
	public static function body_class( $classes, $class ) {
		if ( ! is_page() || ! self::is_filtered() ) {
			return $classes;
		}

		$classes[] = 'newspack-front-page';

		return $classes;
	}

	/**
	 * Filters the template to use when displaying a page on front
	 *
	 * @param string $template The template file.
	 * @return string
	 */
	public static function template_include( $template ) {
		if ( is_page() && self::is_filtered() ) {
			$template = get_front_page_template();
		}

		return $template;
	}

	/**
	 * Filters the page permalink
	 *
	 * @param string $permalink The page permalink.
	 * @param int    $page_id The page ID.
	 * @return string
	 */
	public static function filter_page_link( $permalink, $page_id ) {
		$brand_id = self::get_brand_page_is_cover_for( $page_id );
		if ( ! $brand_id ) {
			return $permalink;
		}

		$brand = get_term( $brand_id, Taxonomy::SLUG );
		if ( ! $brand instanceof WP_Term ) {
			return $permalink;
		}

		return get_term_link( $brand, Taxonomy::SLUG );
	}

	/**
	 * Gets the front pages option
	 *
	 * @return array
	 */
	protected static function get_front_pages() {
		$front_pages = get_option( self::FRONT_PAGES_OPTION_KEY, [] );
		if ( ! is_array( $front_pages ) ) {
			$front_pages = [];
		}
		return $front_pages;
	}

	/**
	 * Updates the front pages option
	 *
	 * @param array $front_pages The front pages array. Keys are page IDs and values Brand IDs.
	 * @return void
	 */
	protected static function update_front_pages( $front_pages ) {
		update_option( self::FRONT_PAGES_OPTION_KEY, $front_pages );
	}

	/**
	 * Updates the front pages option when a term meta is updated
	 *
	 * @param int    $meta_id The Meta ID.
	 * @param int    $object_id The Object ID.
	 * @param string $meta_key The Meta key.
	 * @param string $meta_value The Meta value.
	 * @return void
	 */
	public static function on_term_meta_update( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( '_show_page_on_front' === $meta_key ) {
			$front_pages     = self::get_front_pages();
			$pages_by_brands = array_flip( $front_pages );

			if ( isset( $pages_by_brands[ $object_id ] ) ) {
				unset( $pages_by_brands[ $object_id ] );
			}

			if ( ! empty( $meta_value ) ) {
				$pages_by_brands[ $object_id ] = (int) $meta_value;
			}

			// Doing array_flip twice also ensures an unique page by brand.
			self::update_front_pages( array_flip( $pages_by_brands ) );
		}
	}

	/**
	 * If a page is set as the front page for a brand, returns the brand ID
	 *
	 * @param int $page_id The page ID.
	 * @return ?int The Brand ID. Null if the page is not set as the front page for any brand
	 */
	public static function get_brand_page_is_cover_for( $page_id ) {
		$front_pages = self::get_front_pages();
		return isset( $front_pages[ $page_id ] ) ? $front_pages[ $page_id ] : null;
	}
}
