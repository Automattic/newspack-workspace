<?php
/**
 * Newspack category pager fix.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Newspack category pager.
 *
 * This class adds tweaks to sites where the permalink structure is "/%category%/%postname%/".
 *
 * See https://core.trac.wordpress.org/ticket/8905 and https://core.trac.wordpress.org/ticket/21209
 */
final class Category_Pager {
	/**
	 * Initialize Hooks.
	 */
	public static function init() {
		add_filter( 'paginate_links', [ __CLASS__, 'fix_category_pager' ] );
	}

	/**
	 * Filter callback for paginate_links.
	 *
	 * Filter the pager urls on category pages.
	 *
	 * For a category with the slug "kitten" for instance, the url /kitten will show the content that
	 * really should be on /category/kitten. On the /kitten page, WP will generate pager urls like "/kitten/page/2/"
	 * – this fixes the pager urls to be "/category/kitten/page/2/".
	 *
	 * @param string $link The pager link to filter.
	 *
	 * @return mixed|string
	 */
	public static function fix_category_pager( $link ) {
		if ( ! is_category() || ! str_starts_with( get_option( 'permalink_structure' ), '/%category%/' ) ) {
			return $link;
		}

		$category_base = get_option( 'category_base' );
		if ( empty( $category_base ) ) {
			$category_base = 'category';
		}

		$path = wp_parse_url( $link, PHP_URL_PATH );
		if ( str_starts_with( $path, '/' . $category_base ) ) {
			// No fixing needed.
			return $link;
		}

		$new_path = '/' . $category_base . $path;

		return str_replace( $path, $new_path, $link );
	}
}

Category_Pager::init();
