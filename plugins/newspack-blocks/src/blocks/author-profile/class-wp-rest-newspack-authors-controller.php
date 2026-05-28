<?php
/**
 * WP_REST_Newspack_Authors_Controller file.
 *
 * @package WordPress
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
/**
 * Class WP_REST_Newspack_Authors_Controller.
 */
class WP_REST_Newspack_Authors_Controller extends WP_REST_Controller {
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

	/**
	 * Constructs the controller.
	 *
	 * @access public
	 */
	public function __construct() {
		$this->namespace = 'newspack-blocks/v1';
		$this->rest_base = 'authors';
	}

	/**
	 * Registers the necessary REST API routes.
	 *
	 * @access public
	 */
	public function register_routes() {
		// Endpoint to get authors.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_authors' ],
					'args'                => [
						'author_id'           => [
							'sanitize_callback' => 'absint',
						],
						'is_guest_author'     => [
							'sanitize_callback' => 'absint',
						],
						'avatar_hide_default' => [
							'sanitize_callback' => 'absint',
						],
						'offset'              => [
							'sanitize_callback' => 'absint',
						],
						'per_page'            => [
							'sanitize_callback' => 'absint',
						],
						'search'              => [
							'sanitize_callback' => 'sanitize_text_field',
						],
						'fields'              => [
							'sanitize_callback' => 'sanitize_text_field',
						],
						'post_id'             => [
							'sanitize_callback' => 'absint',
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
	 * Returns a list of combined authors and guest authors.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_authors( $request ) {
		$author_id           = ! empty( $request->get_param( 'author_id' ) ) ? $request->get_param( 'author_id' ) : 0; // Fetch a specific user or guest author by ID.
		$is_guest_author     = null !== $request->get_param( 'is_guest_author' ) ? $request->get_param( 'is_guest_author' ) : true; // If $author_id is known to be a regular WP user, not a guest author, this will be `false`.
		$search              = ! empty( $request->get_param( 'search' ) ) ? $request->get_param( 'search' ) : null; // Fetch authors by search string.
		$offset              = ! empty( $request->get_param( 'offset' ) ) ? $request->get_param( 'offset' ) : 0; // Offset results (for pagination).
		$per_page            = ! empty( $request->get_param( 'perPage' ) ) ? $request->get_param( 'perPage' ) : 10; // Number of results to return per page. This is applied to each query, so the actual number of results returned may be up to 2x this number.
		$avatar_hide_default = ! empty( $request->get_param( 'avatar_hide_default' ) ) ? true : false; // Hide the default avatar if the user has no custom avatar.
		$fields              = ! empty( $request->get_param( 'fields' ) ) ? explode( ',', $request->get_param( 'fields' ) ) : [ 'id' ]; // Fields to get. Will return at least id.
		$include             = ! empty( $request->get_param( 'include' ) ) ? explode( ',', $request->get_param( 'include' ) ) : null; // Fetch authors by multiple IDs.
		$post_id             = ! empty( $request->get_param( 'post_id' ) ) ? $request->get_param( 'post_id' ) : 0; // Fetch authors for a specific post (contextual mode).

		// If post_id is provided, get authors for that specific post.
		if ( $post_id ) {
			return $this->get_post_authors( $post_id, $fields, $avatar_hide_default );
		}

		// Total number of users and guest authors.
		$guest_author_total = 0;
		$user_total         = 0;
		$guest_authors      = [];
		$users              = [];

		// Get Co-authors guest authors.
		if ( $is_guest_author ) {
			$unlinked_guest_authors = [];
			$guest_author_args      = [
				'post_type'      => 'guest-author',
				'posts_per_page' => $per_page,
				'offset'         => $offset,
			];

			if ( $search && ! $author_id ) {
				$guest_author_args['s'] = $search;
			}

			if ( $author_id ) {
				$guest_author_args['p'] = $author_id;
			}

			if ( $include ) {
				$guest_author_args['post__in']            = $include;
				$guest_author_args['ignore_sticky_posts'] = true;
			}

			$guest_authors = get_posts( $guest_author_args );

			// If we are searching for a specific ID we want to return the guest author regardless of if it is linked or not.
			if ( ! $author_id ) {
				foreach ( $guest_authors as $ga ) {
					$linked_guest_author = get_post_meta( $ga->ID, 'cap-linked_account', true );

					if ( $linked_guest_author ) {
						continue;
					}

					$unlinked_guest_authors[] = $ga;
				}

				$guest_authors = $unlinked_guest_authors;
			}

			$guest_author_total = count( $guest_authors );
		}

		// If we are searching for a specific ID we just want to return the specific user.
		if ( $author_id ) {
			// Unless we've already identified a guest author.
			if ( 0 === $guest_author_total ) {
				$user = get_user_by( 'id', $author_id ); // Get the WP user.

				if ( $user ) {
					$users = [ $user ];
				}
			}
		} else {
			$role_in = Newspack_Blocks\get_authors_roles_slugs();

			$user_args = [
				'role__in' => $role_in,
				'offset'   => $offset,
				'orderby'  => 'registered',
				'order'    => 'DESC',
				'number'   => $per_page,
			];

			// If passed a search string.
			if ( $search && ! $author_id ) {
				$user_args['search'] = '*' . $search . '*';
			}

			// If passed an array of IDs.
			if ( $include ) {
				$user_args['include'] = $include;
			}

			$user_query = new \WP_User_Query( $user_args );
			$users      = $user_query->get_results();
			$user_total = $user_query->get_total();
		}

		if ( 0 < $user_total ) {
			// But wait, there's more! Let's see if this user is linked to a guest author.
			$unlinked_users = [];
			foreach ( $users as $user ) {
				$linked_guest_author = self::get_linked_guest_author( $user->user_login );

				// If it is, let's use that instead.
				if ( $linked_guest_author ) {
					$guest_authors[] = $linked_guest_author;
				} else {
					$unlinked_users[] = $user;
				}
			}

			$users = $unlinked_users;
		}

		// Format and combine results.
		$combined_authors = array_merge(
			array_reduce(
				! empty( $guest_authors ) ? $guest_authors : [],
				function( $acc, $guest_author ) use ( $fields, $avatar_hide_default ) {
					if ( $guest_author ) {
						if ( class_exists( 'CoAuthors_Guest_Authors' ) ) {
							$guest_author_data = [
								'id'         => intval( $guest_author->ID ),
								'registered' => $guest_author->post_date,
								'is_guest'   => true,
								'slug'       => $guest_author->post_name,
							];

							$guest_author = ( new CoAuthors_Guest_Authors() )->get_guest_author_by( 'id', $guest_author->ID );

							if ( in_array( 'avatar', $fields, true ) && function_exists( 'coauthors_get_avatar' ) ) {
								$avatar = coauthors_get_avatar( $guest_author, 256 );

								if ( $avatar && ( false === strpos( $avatar, 'avatar-default' ) || ! $avatar_hide_default ) ) {
									$guest_author_data['avatar'] = $avatar;
								}
							}

							$guest_author_data = self::fill_guest_author_data( $guest_author_data, $guest_author, $fields );

							$acc[] = $guest_author_data;
						}
					}
					return $acc;
				},
				[]
			),
			array_reduce(
				$users,
				function( $acc, $user ) use ( $fields, $avatar_hide_default ) {
					if ( $user ) {
						$user_data = [
							'id'         => intval( $user->data->ID ),
							'registered' => $user->data->user_registered,
							'is_guest'   => false,
							'slug'       => $user->data->user_login,
						];

						if ( in_array( 'avatar', $fields, true ) ) {
							$avatar = get_avatar( $user->data->ID, 256 );

							if ( $avatar && ( false === strpos( $avatar, 'avatar-default' ) || ! $avatar_hide_default ) ) {
								$user_data['avatar'] = $avatar;
							}
						}

						$user_data = self::fill_user_data( $user_data, $user, $fields );
						$acc[]     = $user_data;
					}
					return $acc;
				},
				[]
			)
		);

		// Sort combined authors array by registration date.
		usort(
			$combined_authors,
			function( $a, $b ) {
				return strtotime( $b['registered'] ) - strtotime( $a['registered'] );
			}
		);

		$response = new WP_REST_Response( $combined_authors );
		$response->header( 'x-wp-total', $user_total + $guest_author_total );

		return rest_ensure_response( $response );
	}

	/**
	 * Fill guest author data.
	 *
	 * @param array  $guest_author_data Guest author data.
	 * @param object $guest_author The guest author object.
	 * @param array  $fields Fields requested.
	 */
	public static function fill_guest_author_data( $guest_author_data, $guest_author, $fields = false ) {
		if ( false === $fields || in_array( 'login', $fields, true ) ) {
			$guest_author_data['login'] = $guest_author->user_login;
		}
		if ( false === $fields || in_array( 'name', $fields, true ) ) {
			$guest_author_data['name'] = $guest_author->display_name;
		}
		if ( false === $fields || in_array( 'bio', $fields, true ) ) {
			$guest_author_data['bio'] = get_post_meta( $guest_author->ID, 'cap-description', true );
		}
		if ( false === $fields || in_array( 'email', $fields, true ) ) {
			$email_data = self::get_email( $guest_author->ID );

			if ( $email_data ) {
				$guest_author_data['email'] = $email_data;
			}
		}
		if ( false === $fields || in_array( 'url', $fields, true ) ) {
			$guest_author_data['url'] = esc_url(
				get_site_url( null, '?author_name=' . get_post_meta( $guest_author->ID, 'cap-user_login', true ) )
			);
		}
		if ( false === $fields || in_array( 'social', $fields, true ) ) {
			$guest_author_data['social'] = self::get_social( $guest_author->ID );
		}

		if ( class_exists( '\Newspack\Authors_Custom_Fields' ) ) {
			foreach ( \Newspack\Authors_Custom_Fields::get_custom_fields() as $custom_field ) {
				$key   = $custom_field['name'];
				$value = $guest_author->$key;
				if ( ! empty( $value ) && 'newspack_phone_number' === $custom_field['name'] ) {
					$value = [
						'url' => 'tel:' . $value,
					];
					if ( class_exists( '\Newspack\Social_Icons' ) ) {
						$value['svg'] = \Newspack\Social_Icons::get_svg( 'phone' );
					}
				}
				$guest_author_data[ $custom_field['name'] ] = $value;
			}
		}

		return $guest_author_data;
	}

	/**
	 * Fill user data.
	 *
	 * @param array   $user_data User data.
	 * @param WP_User $user The current WP_User object.
	 * @param array   $fields Fields requested.
	 */
	public static function fill_user_data( $user_data, $user, $fields = false ) {
		if ( false === $fields || in_array( 'login', $fields, true ) ) {
			$user_data['login'] = $user->data->user_login;
		}
		if ( false === $fields || in_array( 'name', $fields, true ) ) {
			$user_data['name'] = $user->data->display_name;
		}
		if ( false === $fields || in_array( 'bio', $fields, true ) ) {
			$user_data['bio'] = get_the_author_meta( 'description', $user->data->ID );
		}
		if ( false === $fields || in_array( 'email', $fields, true ) ) {
			$email_data = self::get_email( $user->data->ID, false, $user->data->user_email );

			if ( $email_data ) {
				$user_data['email'] = $email_data;
			}
		}
		if ( false === $fields || in_array( 'url', $fields, true ) ) {
			$user_data['url'] = esc_url( get_author_posts_url( $user->data->ID ) );
		}
		if ( false === $fields || in_array( 'social', $fields, true ) ) {
			$user_data['social'] = self::get_social( $user->data->ID );
		}

		if ( class_exists( '\Newspack\Authors_Custom_Fields' ) ) {
			foreach ( \Newspack\Authors_Custom_Fields::get_custom_fields() as $custom_field ) {
				$value = \get_user_meta( $user->data->ID, $custom_field['name'], true );
				if ( ! empty( $value ) && 'newspack_phone_number' === $custom_field['name'] ) {
					$value = [
						'url' => 'tel:' . $value,
					];
					if ( class_exists( '\Newspack\Social_Icons' ) ) {
						$value['svg'] = \Newspack\Social_Icons::get_svg( 'phone' );
					}
				}
				$user_data[ $custom_field['name'] ] = $value;
			}
		}
		return $user_data;
	}

	/**
	 * Given a WP user login, get the linked guest author, if any.
	 *
	 * @param string $user_login WP user login name.
	 *
	 * @return WP_Post|boolean Linked guest author in post form, or false if none.
	 */
	public static function get_linked_guest_author( $user_login ) {
		$linked_guest_authors = get_posts(
			[
				'post_type'      => 'guest-author',
				'posts_per_page' => 1,
				'meta_key'       => 'cap-linked_account', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $user_login, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return 0 < count( $linked_guest_authors ) ? reset( $linked_guest_authors ) : false;
	}

	/**
	 * Get author email address and SVG icon, if available.
	 *
	 * @param int     $author_id Author ID.
	 * @param boolean $is_guest_author Is the author ID a CAP guest author?.
	 * @param string  $email_address If a standard WP user, use this email address.
	 * @return array Array with email address and SVG.
	 */
	public static function get_email( $author_id, $is_guest_author = true, $email_address = '' ) {
		$email_data    = false;
		$email_address = $is_guest_author ? get_post_meta( $author_id, 'cap-user_email', true ) : $email_address;

		if ( $email_address ) {
			$email_data = [ 'url' => 'mailto:' . $email_address ];

			if ( class_exists( '\Newspack\Social_Icons' ) ) {
				$email_data['svg'] = \Newspack\Social_Icons::get_svg( 'email' );
			}
		}

		return $email_data;
	}

	/**
	 * Get social media URLs and SVGs, if available. Only standard WP users have this user meta.
	 *
	 * @param int $author_id Author ID.
	 * @return array Array of social links and SVGs.
	 */
	public static function get_social( $author_id ) {
		$social_profiles = [
			'facebook',
			'twitter',
			'instagram',
			'linkedin',
			'myspace',
			'pinterest',
			'soundcloud',
			'tumblr',
			'youtube',
			'wikipedia',
			'bluesky',
			'website', // This is the only "social media" link for CAP guest authors.
		];

		return array_reduce(
			$social_profiles,
			function( $acc, $profile ) use ( $author_id ) {
				$is_website = 'website' === $profile;
				$handle     = $is_website ? get_post_meta( $author_id, 'cap-website', true ) : get_the_author_meta( $profile, $author_id );

				if ( $handle ) {
					$url             = 'twitter' === $profile ? esc_url( 'https://x.com/' . $handle ) : esc_url( $handle );
					$acc[ $profile ] = [ 'url' => $url ];

					if ( class_exists( '\Newspack\Social_Icons' ) && ! $is_website ) {
						$acc[ $profile ]['svg'] = \Newspack\Social_Icons::get_svg( $profile );
					}
				}

				return $acc;
			},
			[]
		);
	}

	/**
	 * Get authors for a specific post (contextual mode).
	 *
	 * Uses Co-Authors Plus if available, otherwise falls back to the default post author.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields Fields to include in response.
	 * @param bool  $avatar_hide_default Whether to hide default avatars.
	 * @return WP_REST_Response
	 */
	protected function get_post_authors( $post_id, $fields, $avatar_hide_default ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return rest_ensure_response( [] );
		}

		$authors = [];

		// Try Co-Authors Plus first. When CAP is active, always return its result
		// and never fall back to post_author, which could show the wrong person.
		if ( function_exists( 'get_coauthors' ) ) {
			$coauthors = get_coauthors( $post_id );
			foreach ( $coauthors as $coauthor ) {
				$author_data = $this->format_coauthor( $coauthor, $fields, $avatar_hide_default );
				if ( $author_data ) {
					$authors[] = $author_data;
				}
			}

			$response = new WP_REST_Response( $authors );
			$response->header( 'x-wp-total', count( $authors ) );
			return rest_ensure_response( $response );
		}

		// Fallback to default post author.
		$author_id = $post->post_author;
		if ( $author_id ) {
			$user = get_user_by( 'id', $author_id );
			if ( $user ) {
				$user_data = [
					'id'         => intval( $user->data->ID ),
					'registered' => $user->data->user_registered,
					'is_guest'   => false,
					'slug'       => $user->data->user_login,
				];

				if ( in_array( 'avatar', $fields, true ) ) {
					$avatar = get_avatar( $user->data->ID, 256 );
					if ( $avatar && ( false === strpos( $avatar, 'avatar-default' ) || ! $avatar_hide_default ) ) {
						$user_data['avatar'] = $avatar;
					}
				}

				$user_data = self::fill_user_data( $user_data, $user, $fields );
				$authors[] = $user_data;
			}
		}

		$response = new WP_REST_Response( $authors );
		$response->header( 'x-wp-total', count( $authors ) );
		return rest_ensure_response( $response );
	}

	/**
	 * Format a co-author object for the REST response.
	 *
	 * Co-Authors Plus returns stdClass objects from get_coauthors().
	 * These can be either guest authors or linked WP users.
	 *
	 * @param object $coauthor Co-author object from get_coauthors().
	 * @param array  $fields Fields to include in response.
	 * @param bool   $avatar_hide_default Whether to hide default avatars.
	 * @return array|null Formatted author data or null if invalid.
	 */
	protected function format_coauthor( $coauthor, $fields, $avatar_hide_default ) {
		if ( ! is_object( $coauthor ) || ! isset( $coauthor->ID ) ) {
			return null;
		}

		// Check if this is a guest author.
		$is_guest = isset( $coauthor->type ) && 'guest-author' === $coauthor->type;

		if ( $is_guest && class_exists( 'CoAuthors_Guest_Authors' ) ) {
			$guest_author = ( new CoAuthors_Guest_Authors() )->get_guest_author_by( 'id', $coauthor->ID );

			if ( ! $guest_author ) {
				return null;
			}

			$author_data = [
				'id'         => intval( $coauthor->ID ),
				'registered' => isset( $coauthor->post_date ) ? $coauthor->post_date : '',
				'is_guest'   => true,
				'slug'       => isset( $coauthor->user_nicename ) ? $coauthor->user_nicename : '',
			];

			if ( in_array( 'avatar', $fields, true ) && function_exists( 'coauthors_get_avatar' ) ) {
				$avatar = coauthors_get_avatar( $guest_author, 256 );
				if ( $avatar && ( false === strpos( $avatar, 'avatar-default' ) || ! $avatar_hide_default ) ) {
					$author_data['avatar'] = $avatar;
				}
			}

			return self::fill_guest_author_data( $author_data, $guest_author, $fields );
		}

		// It's a linked WP user.
		$user = get_user_by( 'id', $coauthor->ID );
		if ( ! $user ) {
			// Try to get by login if ID doesn't work.
			if ( isset( $coauthor->user_login ) ) {
				$user = get_user_by( 'login', $coauthor->user_login );
			}
		}

		if ( ! $user ) {
			return null;
		}

		// Check if this user has a linked guest author for additional data.
		$linked_guest_author = self::get_linked_guest_author( $user->user_login );

		if ( $linked_guest_author && class_exists( 'CoAuthors_Guest_Authors' ) ) {
			$guest_author = ( new CoAuthors_Guest_Authors() )->get_guest_author_by( 'id', $linked_guest_author->ID );

			if ( $guest_author ) {
				$author_data = [
					'id'         => intval( $linked_guest_author->ID ),
					'registered' => $linked_guest_author->post_date,
					'is_guest'   => true,
					'slug'       => $linked_guest_author->post_name,
				];

				if ( in_array( 'avatar', $fields, true ) && function_exists( 'coauthors_get_avatar' ) ) {
					$avatar = coauthors_get_avatar( $guest_author, 256 );
					if ( $avatar && ( false === strpos( $avatar, 'avatar-default' ) || ! $avatar_hide_default ) ) {
						$author_data['avatar'] = $avatar;
					}
				}

				return self::fill_guest_author_data( $author_data, $guest_author, $fields );
			}
		}

		// Return as regular WP user.
		$user_data = [
			'id'         => intval( $user->data->ID ),
			'registered' => $user->data->user_registered,
			'is_guest'   => false,
			'slug'       => $user->data->user_login,
		];

		if ( in_array( 'avatar', $fields, true ) ) {
			$avatar = get_avatar( $user->data->ID, 256 );
			if ( $avatar && ( false === strpos( $avatar, 'avatar-default' ) || ! $avatar_hide_default ) ) {
				$user_data['avatar'] = $avatar;
			}
		}

		return self::fill_user_data( $user_data, $user, $fields );
	}
}
