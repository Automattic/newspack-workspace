<?php
/**
 * Authors_Controller file.
 *
 * @package Newspack_Content_Blocks
 */

namespace Newspack_Content_Blocks\REST;

use Newspack_Content_Blocks\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Author suggestions for the Content Loop block's "Authors" filter.
 *
 * Lists WP users who hold an author role, plus Co-Authors Plus guest authors when that
 * plugin is active. Serves only what the block's author picker asks for: `search`,
 * `include`, `per_page` and `fields` (`id` and `name`).
 */
class Authors_Controller extends \WP_REST_Controller {

	/**
	 * Most authors one request returns.
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Constructs the controller.
	 */
	public function __construct() {
		$this->namespace = 'newspack-content-blocks/v1';
		$this->rest_base = 'authors';
	}

	/**
	 * Register the routes on `rest_api_init`.
	 */
	public static function init() {
		add_action(
			'rest_api_init',
			function() {
				( new self() )->register_routes();
			}
		);
	}

	/**
	 * Registers the authors route.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'args'                => [
						'search'   => [
							'sanitize_callback' => 'sanitize_text_field',
						],
						'include'  => [
							'sanitize_callback' => [ __CLASS__, 'sanitize_id_list' ],
						],
						'per_page' => [
							'sanitize_callback' => 'absint',
						],
						'fields'   => [
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
					'permission_callback' => function() {
						return current_user_can( 'edit_posts' );
					},
				],
			]
		);
	}

	/**
	 * Turn a comma-separated list (or array) of IDs into unique positive integers.
	 *
	 * @param string|array $value Raw request param.
	 * @return int[]
	 */
	public static function sanitize_id_list( $value ) {
		$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
		$ids = array_filter( array_map( 'absint', $ids ) );
		return array_slice( array_values( array_unique( $ids ) ), 0, self::MAX_PER_PAGE );
	}

	/**
	 * Slugs of the roles this endpoint treats as authors: roles that can edit posts.
	 *
	 * Runs the same filters as the Newspack Blocks author roles, so sites that adjusted
	 * them keep one list.
	 *
	 * @return string[]
	 */
	private static function get_author_role_slugs() {
		global $wp_roles;

		$roles = [];
		foreach ( $wp_roles->roles as $role_slug => $role ) {
			if ( ! empty( $role['capabilities']['edit_posts'] ) ) {
				$roles[] = [
					'slug'  => $role_slug,
					'label' => $role['name'],
				];
			}
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$roles = apply_filters( 'newspack_blocks_author_list_editable_roles', $roles );
		$roles = apply_filters( 'newspack_blocks_authors_roles', $roles );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return array_map(
			function( $role ) {
				return $role['slug'];
			},
			$roles
		);
	}

	/**
	 * Returns a list of combined users and guest authors.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$search   = (string) $request->get_param( 'search' );
		$include  = $request->get_param( 'include' );
		$include  = ! empty( $include ) ? self::sanitize_id_list( $include ) : [];
		$per_page = min( self::MAX_PER_PAGE, (int) $request->get_param( 'per_page' ) );
		$per_page = $per_page > 0 ? $per_page : 10;
		$fields   = array_filter( explode( ',', (string) $request->get_param( 'fields' ) ) );
		$fields   = array_values( array_unique( array_merge( [ 'id' ], $fields ) ) );

		$guest_authors = [];
		$guest_total   = 0;

		// Guest authors. Linked ones are represented by their WP user, below.
		if ( class_exists( 'CoAuthors_Guest_Authors' ) ) {
			$guest_args = [
				'post_type'      => 'guest-author',
				'posts_per_page' => $per_page,
			];
			if ( $search ) {
				$guest_args['s'] = $search;
			}
			if ( $include ) {
				$guest_args['post__in']            = $include;
				$guest_args['ignore_sticky_posts'] = true;
			}
			foreach ( get_posts( $guest_args ) as $guest_author ) {
				if ( ! get_post_meta( $guest_author->ID, 'cap-linked_account', true ) ) {
					$guest_authors[] = $guest_author;
				}
			}
			$guest_total = count( $guest_authors );
		}

		$user_args = [
			'role__in' => self::get_author_role_slugs(),
			'orderby'  => 'registered',
			'order'    => 'DESC',
			'number'   => $per_page,
		];
		if ( $search ) {
			$user_args['search'] = '*' . $search . '*';
		}
		if ( $include ) {
			$user_args['include'] = $include;
		}
		$user_query = new \WP_User_Query( $user_args );
		$users      = [];
		foreach ( $user_query->get_results() as $user ) {
			// A user linked to a guest author is represented by the guest author.
			$linked_guest_author = class_exists( 'CoAuthors_Guest_Authors' ) ? Blocks::get_linked_guest_author( $user->user_login ) : false;
			if ( $linked_guest_author ) {
				$guest_authors[] = $linked_guest_author;
			} else {
				$users[] = $user;
			}
		}

		$authors = [];
		foreach ( $guest_authors as $guest_author ) {
			$data = self::format_guest_author( $guest_author, $fields );
			if ( $data ) {
				$authors[] = $data;
			}
		}
		foreach ( $users as $user ) {
			$authors[] = self::format_user( $user, $fields );
		}

		usort(
			$authors,
			function( $a, $b ) {
				return strtotime( $b['registered'] ) - strtotime( $a['registered'] );
			}
		);

		// The registration date only orders the list; it is not part of the response.
		$authors = array_map(
			function( $author ) {
				unset( $author['registered'] );
				return $author;
			},
			$authors
		);

		$response = new \WP_REST_Response( $authors );
		$response->header( 'x-wp-total', $user_query->get_total() + $guest_total );
		return $response;
	}

	/**
	 * Format a guest author post as a record.
	 *
	 * @param \WP_Post $guest_author Guest author post.
	 * @param string[] $fields       Fields to include.
	 * @return array|null Record, or null when Co-Authors Plus has no such guest author.
	 */
	private static function format_guest_author( $guest_author, $fields ) {
		$cap_author = ( new \CoAuthors_Guest_Authors() )->get_guest_author_by( 'id', $guest_author->ID );
		if ( ! $cap_author ) {
			return null;
		}
		$data = [
			'id'         => (int) $guest_author->ID,
			'registered' => $guest_author->post_date,
		];
		if ( in_array( 'name', $fields, true ) ) {
			$data['name'] = $cap_author->display_name;
		}
		return $data;
	}

	/**
	 * Format a WP user as a record.
	 *
	 * @param \WP_User $user   The user.
	 * @param string[] $fields Fields to include.
	 * @return array
	 */
	private static function format_user( $user, $fields ) {
		$data = [
			'id'         => (int) $user->ID,
			'registered' => $user->user_registered,
		];
		if ( in_array( 'name', $fields, true ) ) {
			$data['name'] = $user->display_name;
		}
		return $data;
	}
}
