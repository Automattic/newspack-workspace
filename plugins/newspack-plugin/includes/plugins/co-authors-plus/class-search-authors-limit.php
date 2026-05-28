<?php
/**
 * Search Authors limit class.
 * https://wordpress.org/plugins/co-authors-plus
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * This class increases the number of results returned by the search authors query.
 *
 * In some cases, when you have too many authors starting with the same string, user is never able to find the author they are looking for.
 *
 * Also, because the search is performed using the terms table, even if we underpromote a user, they will still appear in the search results.
 */
class Search_Authors_Limit {

	/**
	 * Initialize hooks and filters.
	 */
	public static function init() {
		add_filter( 'coauthors_search_authors_get_terms_args', [ __CLASS__, 'search_args' ] );
	}

	/**
	 * Increase the number of results returned by the search authors query.
	 *
	 * @param array $args The arguments for the get_terms query.
	 * @return array The modified arguments.
	 */
	public static function search_args( $args ) {
		$args['number'] = 100;
		return $args;
	}
}

Search_Authors_Limit::init();
