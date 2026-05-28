<?php
/**
 * Adds a filter by author to the Posts page in the Admin dashboard.
 *
 * @package Newspack
 */

namespace Newspack;

use WP_Post_Type;
use WP_User_Query;

/**
 * Author Filter class
 */
class Author_Filter {

	/**
	 * Flag to make sure hooks are initialized only once
	 *
	 * @var boolean
	 */
	private static $initiated = false;

	/**
	 * Initializes the hook
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::$initiated ) {
			add_action( 'restrict_manage_posts', array( __CLASS__, 'author_filters_admin' ) );
			self::$initiated = true;
		}
	}

	/**
	 * Checks if the Co Authors Plus plugin is enabled
	 *
	 * If $post_type is informed, will check if it is enabled for this specific post type
	 *
	 * @param string $post_type The post type you want to check if Co Authors plus is enabled to.
	 * @return boolean
	 */
	private static function is_coauthors_plus_enabled( $post_type = '' ) {
		global $coauthors_plus;
		if ( ! is_object( $coauthors_plus ) || ! method_exists( $coauthors_plus, 'search_authors' ) ) {
			return false;
		}
		if ( ! empty( $post_type ) ) {
			return $coauthors_plus->is_post_type_enabled( $post_type );
		}
		return true;
	}

	/**
	 * Echoes a User Dropdown to be added to the Filters
	 *
	 * @param string $post_type The post type the action is acting on.
	 * @return void
	 */
	public static function author_filters_admin( $post_type ) {

		/**
		 * Filters the post types that will have the author filter.
		 *
		 * @param array $filterable_post_types Array with post types slugs.
		 */
		$filterable_post_types = apply_filters( 'newspack_author_filter_post_types', [ 'post' ] );

		if ( ! in_array( $post_type, $filterable_post_types, true ) ) {
			return;
		}

		$type_object = get_post_type_object( $post_type );

		if ( ! $type_object instanceof WP_Post_Type ) {
			return;
		}

		$value_field = 'user_nicename';
		$select_name = 'author_name';
		$capability  = Guest_Contributor_Role::ASSIGNABLE_TO_POSTS_CAPABILITY_NAME; // This includes both Guest Contributors and regular Authors.
		$users       = self::get_options_from_users( $capability );

		if ( self::is_coauthors_plus_enabled( $post_type ) ) {
			// When using Coauthors plugin, dont show the filter if user is browsing the "Mine" tab. It can lead to inconsistent UI.
			if ( ! empty( $_GET['author'] ) && get_current_user_id() === (int) $_GET['author'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
		}


		$selected = ! empty( $_GET[ $select_name ] ) ? sanitize_text_field( $_GET[ $select_name ] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options  = array(
			sprintf(
				'<option value="" %s>%s</option>',
				selected( false, $selected, false ),
				__( 'All authors', 'newspack' )
			),
		);

		foreach ( $users as $user ) {
			$options[] = sprintf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $user->$value_field ),
				selected( $user->$value_field, $selected, false ),
				esc_attr( $user->display_name )
			);
		}

		printf( '<select id="author_filter" name="%s">', esc_attr( $select_name ) );
		echo implode( "\n", $options ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</select>';
	}

	/**
	 * Get the list of users
	 *
	 * @param string $capability The capability users must have to be listed.
	 * @return array
	 */
	private static function get_options_from_users( $capability ) {
		$args  = array(
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'capability' => [ $capability ],
			'fields'     => [ 'ID', 'display_name', 'user_nicename' ],
		);
		$query = new WP_User_Query( $args );
		return $query->get_results();
	}
}

/**
 * Disables the author filter feature which provides enhanced
 * author filtering and search capabilities in the admin.
 *
 * @constant NEWSPACK_DISABLE_AUTHORS_FILTER
 * @type     bool
 * @default  Authors filter enabled
 * @status   draft
 *
 * @example define( 'NEWSPACK_DISABLE_AUTHORS_FILTER', true );
 */
if ( ! defined( 'NEWSPACK_DISABLE_AUTHORS_FILTER' ) || ! NEWSPACK_DISABLE_AUTHORS_FILTER ) {
	Author_Filter::init();
}
