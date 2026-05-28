<?php
/**
 * Co-Authors Plus CLI commands.
 *
 * @package Newspack
 */

namespace Newspack\CLI;

use WP_CLI;
use Newspack\Nicename_Change;

defined( 'ABSPATH' ) || exit;

/**
 * Co-Authors Plus CLI commands.
 */
class Co_Authors_Plus {
	private static $live = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $verbose = true; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $user_logins = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $guest_author_ids = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing
	private static $sideload_avatar = false; // phpcs:ignore Squiz.Commenting.VariableComment.Missing

	/**
	 * Migrate Co-Authors Plus guest authors to regular users with the [Guest Contributor role](https://help.newspack.com/publishing-and-appearance/guest-contributors/).
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Run the command in live mode, updating the subscriptions.
	 *
	 * [--verbose]
	 * : Produce more output.
	 *
	 * [--user_logins]
	 * : Comma-separated list of user logins. If provided, only WP Users with these logins will be processed.
	 *
	 * [--guest_author_ids]
	 * : Comma-separated list of Guest Author IDs. If provided, only Guest Authors with these IDs will be processed.
	 *
	 * [--sideload_avatar]
	 * : Whether to sideload the avatar image and create a new attachment instead of just assigning the existing attachment to the user. Defaults to false.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Assoc arguments.
	 * @return void
	 */
	public function migrate_guest_authors( $args, $assoc_args ) {
		WP_CLI::log( '' );

		self::$live = isset( $assoc_args['live'] ) ? true : false;
		self::$verbose = isset( $assoc_args['verbose'] ) ? true : false;
		self::$user_logins = isset( $assoc_args['user_logins'] ) ? explode( ',', $assoc_args['user_logins'] ) : false;
		self::$guest_author_ids = isset( $assoc_args['guest_author_ids'] ) ? explode( ',', $assoc_args['guest_author_ids'] ) : false;
		self::$sideload_avatar = isset( $assoc_args['sideload_avatar'] ) ? true : false;

		if ( self::$live ) {
			WP_CLI::log( 'Live mode - data will be changed.' );
			wp_defer_term_counting( true );
		} else {
			WP_CLI::log( 'Dry run. Use --live flag to run in live mode.' );
		}
		WP_CLI::log( '' );

		if ( ! class_exists( 'CoAuthors_Guest_Authors' ) ) {
			WP_CLI::error( 'Co-Authors Plus plugin is not active.' );
			WP_CLI::log( '' );
		}

		if ( self::$guest_author_ids === false ) {
			self::migrate_linked_guest_authors();
		} else {
			WP_CLI::log( 'Skipping linked Guest Authors, since guest_author_ids argument was provided.' );
		}
		WP_CLI::log( '' );
		if ( self::$user_logins === false ) {
			self::migrate_unlinked_guest_authors();
		} else {
			WP_CLI::log( 'Skipping unlinked Guest Authors, since user_logins argument was provided.' );
		}

		wp_defer_term_counting( false );

		WP_CLI::log( '' );
	}

	/**
	 * Backfill Non-Editing Contributor role. Will add this role to any Subscriber/Customer
	 * who has any posts assigned to them.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Run the command in live mode, updating the subscriptions.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Assoc arguments.
	 * @return void
	 */
	public function backfill_non_editing_contributor( $args, $assoc_args ) {
		WP_CLI::log( '' );

		self::$live = isset( $assoc_args['live'] ) ? true : false;

		if ( self::$live ) {
			WP_CLI::log( 'Live mode - data will be changed.' );
		} else {
			WP_CLI::log( 'Dry run. Use --live flag to run in live mode.' );
		}
		WP_CLI::log( '' );

		// Find all WP Users who have Subscriber or Customer role, and at least one post authored.
		$users = get_users(
			[
				'role__in'     => [ 'subscriber', 'customer' ],
				'role__not_in' => [ \Newspack\Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME, 'administrator', 'editor', 'author', 'contributor' ],
				'fields'       => 'ID',
				'number'       => -1,
			]
		);

		WP_CLI::success( 'Found ' . count( $users ) . " users with Subscriber or Customer role.\n" );

		foreach ( $users as $user_id ) {
			if ( count_user_posts( $user_id ) > 0 ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.count_user_posts_count_user_posts
				if ( self::$live ) {
					WP_CLI::log( ( sprintf( 'Will add the Non-Editing Contributor role to user %d.', $user_id ) ) . "\n" );
					get_user_by( 'id', $user_id )->add_role( \Newspack\Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME );
				} else {
					WP_CLI::log( ( sprintf( 'Would add the Non-Editing Contributor role to user %d.', $user_id ) ) . "\n" );
				}
			}
		}

		WP_CLI::log( '' );
	}

	/**
	 * Migrate unlinked guest authors to regular users.
	 *
	 * For all unlinked guest authors, copy the guest author's data to a new WP user,
	 * reassign the posts, and remove the guest author.
	 */
	private static function migrate_unlinked_guest_authors() {

		WP_CLI::log( "Starting migrate unlinked guest authors...\n" );

		$get_posts_args = [
			'post_type'      => 'guest-author',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		];
		if ( self::$guest_author_ids !== false && is_array( self::$guest_author_ids ) ) {
			$get_posts_args['post__in'] = self::$guest_author_ids;
		} else {
			$get_posts_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				[
					'key'     => 'cap-linked_account',
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => 'cap-linked_account',
					'compare' => '=',
					'value'   => '',
				],
			];
		}
		$unlinked_guest_authors = get_posts( $get_posts_args );
		WP_CLI::success( sprintf( 'Found %d guest author(s) not linked to any WP User.', count( $unlinked_guest_authors ) ) );

		$updated_users_count = 0;
		$created_users_count = 0;
		$memory_cleanup_count = 0;

		foreach ( $unlinked_guest_authors as $guest_author ) {

			if ( $memory_cleanup_count++ >= 50 ) {
				self::memory_cleanup();
				$memory_cleanup_count = 0;
			}

			WP_CLI::log( '' );
			$post_meta = array_map(
				function( $value ) {
					return $value[0];
				},
				get_post_meta( $guest_author->ID )
			);
			if ( self::$verbose ) {
				if ( self::$live ) {
					WP_CLI::log( sprintf( 'Creating user %s from Guest Author #%d.', $post_meta['cap-display_name'], $guest_author->ID ) );
				} else {
					WP_CLI::log( sprintf( 'Would create user %s from Guest Author #%d.', $post_meta['cap-display_name'], $guest_author->ID ) );
				}
			}

			$guest_author_login = $post_meta['cap-user_login'];

			$user_data = [
				'user_url'     => isset( $post_meta['cap-website'] ) && strlen( $post_meta['cap-website'] ) <= 100 ? $post_meta['cap-website'] : '',
				'display_name' => isset( $post_meta['cap-display_name'] ) ? trim( $post_meta['cap-display_name'] ) : '',
				'meta_input'   => [
					'_np_migrated_cap_guest_author' => $guest_author->ID,
				],
			];

			if ( ! empty( $post_meta['cap-first_name'] ) ) {
				$user_data['first_name'] = $post_meta['cap-first_name'];
			}
			if ( ! empty( $post_meta['cap-last_name'] ) ) {
				$user_data['last_name'] = $post_meta['cap-last_name'];
			}
			if ( ! empty( $post_meta['cap-description'] ) ) {
				$user_data['description'] = $post_meta['cap-description'];
			}

			/**
			 * CoAuthors plus assign users to posts using the author taxonomy. There is one term for each author.
			 * The term is the user->user_nicename. In case of Guest Authors, it's the cap-user_login.
			 * That's why here we see if there are already users with the same nicename. If there are, the posts are actually already assigned to them
			 * and to the Guest Author at the same time, which is kind of a broken state. But in this case, we just need to delete the GA and the posts will
			 * already be assigned to this existing user.
			 */
			global $wpdb;
			$existing_user = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE user_nicename = %s", $guest_author_login ) ); //phpcs:ignore
			$updating = false;

			if ( $existing_user ) {
				$updated_users_count++;
				$user_data['ID'] = $existing_user->ID;
				$user_data['user_login'] = $existing_user->user_login;
				$updating = true;

				if ( self::$verbose ) {
					WP_CLI::log( sprintf( 'User with nicename %s already exists, it will be updated.', $guest_author_login ) );
					if ( $post_meta['cap-display_name'] !== $existing_user->display_name ) {
						WP_CLI::log( sprintf( 'Display name will be updated from %s to %s', $existing_user->display_name, $post_meta['cap-display_name'] ) );
					}
					WP_CLI::log( 'Values before the migration stored in _np_cap_guest_author_migration_data user meta.' );
				}
				$migration_data = [
					'info'         => 'User data before Guest Authors migration executed on ' . gmdate( 'Y-m-d H:i:s' ),
					'display_name' => $existing_user->display_name,
					'first_name'   => get_user_meta( $existing_user->ID, 'first_name', true ),
					'last_name'    => get_user_meta( $existing_user->ID, 'last_name', true ),
					'description'  => get_user_meta( $existing_user->ID, 'description', true ),
				];
				add_user_meta( $existing_user->ID, '_np_cap_guest_author_migration_data', $migration_data );
			} else {
				$created_users_count++;
				$needs_nicename_change = false;
				$old_nicename = '';

				// For new users, we need to remove the cap- prefix from the login, otherwise CAP won't find them when getting the authors of a post
				// See https://github.com/Automattic/Co-Authors-Plus/blob/30602dbd59c6cd73bd4aa3ff8a3e6eda0c1bccea/template-tags.php#L20.
				$user_login_for_new_user = preg_replace( '/^cap-/', '', $guest_author_login );

				WP_CLI::log( sprintf( 'Creating new user with login %s.', $user_login_for_new_user ) );

				if ( strlen( $user_login_for_new_user ) > 50 ) {
					$old_nicename = $user_login_for_new_user;
					$user_login_for_new_user = substr( $user_login_for_new_user, 0, 50 );
					$needs_nicename_change = true;
					WP_CLI::log( sprintf( 'Nicename %s is too long and was updated to %s.', $old_nicename, $user_login_for_new_user ) );
				}

				$user_data['role']            = \Newspack\Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME;
				$user_data['user_registered'] = $guest_author->post_date;
				$user_data['user_login']      = $user_login_for_new_user;
				$user_data['user_nicename']   = $user_login_for_new_user;
				$user_data['user_pass']       = wp_generate_password();

				if ( ! empty( $post_meta['cap-user_email'] ) ) {
					$user_data['user_email'] = $post_meta['cap-user_email'];
				} else {
					// emails can't be bigger than 100 chars in WP, let's use the $user_login_for_new_user to create a dummy email, as it's capped at 50 chars. This leaves room for the prefix and the domain.
					$dummy_email = \Newspack\Guest_Contributor_Role::get_dummy_email_address( '_migrated-' . $guest_author->ID . '-' . $user_login_for_new_user );
					$user_data['user_email'] = $dummy_email;
					if ( self::$verbose ) {
						WP_CLI::log( sprintf( 'Missing email for Guest Author, email address will be updated to %s.', $dummy_email ) );
					}
				}

				// Check if a user with this email address already exists (they might be a Subscriber).
				$user = get_user_by( 'email', $user_data['user_email'] );
				if ( $user !== false ) {
					$new_email_address = '_migrated-' . $guest_author->ID . '-' . $user_data['user_email'];
					if ( self::$verbose ) {
						WP_CLI::log( sprintf( 'User with email %s already exists, email address will be updated to %s.', $user_data['user_email'], $new_email_address ) );
					}
					// Update the new user (non-editing contributor) email address.
					// Since they won't need to log in, this email address does not have to be real.
					$user_data['user_email'] = $new_email_address;
				}
			}

			foreach ( array_values( \Newspack\Authors_Custom_Fields::USER_META_NAMES ) as $meta_key ) {
				if ( isset( $post_meta[ 'cap-' . $meta_key ] ) ) {
					$user_data['meta_input'][ $meta_key ] = $post_meta[ 'cap-' . $meta_key ];
				}
			}

			if ( self::$live ) {
				if ( $updating ) {
					$user_id = wp_update_user( $user_data );
				} else {
					$user_id = wp_insert_user( $user_data );
				}

				if ( is_wp_error( $user_id ) ) {
					WP_CLI::warning( sprintf( 'Could not create/update user: %s', $user_id->get_error_message() ) );
					continue;
				}

				if ( ! is_int( $user_id ) || $user_id <= 0 ) {
					WP_CLI::warning( 'Could not create/update user: Could not get the user ID after creation/update.' );
					continue;
				}

				WP_CLI::success( sprintf( 'User created/updated successfully (#%d).', $user_id ) );

				if ( $needs_nicename_change ) {
					WP_CLI::log( 'Making sure the original nicename redirects to the shortned one.' );

					// Make sure to update the CAP term.
					Nicename_Change::update_author_term( $user_id, $old_nicename, $user_login_for_new_user );

					// Make sure to redirect from old nicename to new one.
					Nicename_Change::handle_old_nicename_meta( $user_id, $old_nicename, $user_login_for_new_user );
				}

				self::assign_user_avatar( $guest_author, $user_id );
				self::delete_guest_author_post( $guest_author->ID );
			} else {
				WP_CLI::log( sprintf( 'Would create/update user %s from Guest Author #%d. Payload: %s', $post_meta['cap-display_name'], $guest_author->ID, wp_json_encode( $user_data ) ) );
			}
		}
		if ( self::$live ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Created %d user(s) and updated %d user(s).', $created_users_count, $updated_users_count ) );
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Would create %d user(s) and update %d user(s).', $created_users_count, $updated_users_count ) );
		}
	}

	/**
	 * Migrate linked guest authors to regular users.
	 *
	 * For all linked guest authors, copy the guest author's data to the linked user,
	 * reassign the posts, and remove the guest author.
	 */
	private static function migrate_linked_guest_authors() {
		$meta_query = is_array( self::$user_logins ) ? [
			'key'     => 'cap-linked_account',
			'compare' => 'IN',
			'value'   => self::$user_logins,
		] : [
			'key'     => 'cap-linked_account',
			'compare' => '!=',
			'value'   => '',
		];
		$linked_guest_authors = get_posts(
			[
				'post_type'      => 'guest-author',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'meta_query'     => [ $meta_query ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);
		WP_CLI::success( sprintf( 'Found %d guest author(s) linked to WP Users.', count( $linked_guest_authors ) ) );

		$memory_cleanup_count = 0;

		foreach ( $linked_guest_authors as $guest_author ) {

			if ( $memory_cleanup_count++ >= 50 ) {
				self::memory_cleanup();
				$memory_cleanup_count = 0;
			}

			WP_CLI::log( '' );
			$linked_user_slug = get_post_meta( $guest_author->ID, 'cap-linked_account', true );
			$linked_user = get_user_by( 'login', $linked_user_slug );
			if ( ! $linked_user ) {
				WP_CLI::warning( sprintf( 'User with login %s not found.', $linked_user_slug ) );
				continue;
			}

			$user_id = $linked_user->ID;

			WP_CLI::log( sprintf( 'Guest Author %s (#%d) is linked to user %s (#%d).', $guest_author->post_title, $guest_author->ID, $linked_user->data->display_name, $user_id ) );

			$guest_author_data = get_post_meta( $guest_author->ID );

			// Avatar is assigned first, since the original will be removed when the guest author is deleted.
			self::assign_user_avatar( $guest_author, $user_id );

			global $coauthors_plus;

			$guest_term = $coauthors_plus->get_author_term( (object) [ 'user_nicename' => $guest_author->post_name ] );
			$wp_user_term = $coauthors_plus->get_author_term( $linked_user );
			if ( ! $guest_term ) {
				WP_CLI::warning( sprintf( 'No term found for guest author %d. This will make post reassignement impossible. Skipping!', $guest_author->ID ) );
				continue;
			}
			if ( ! $wp_user_term ) {
				// This happens when the WP User is created first, then the Guest Author, and then a post is assigned to the GA.
				// The term is then created based on the GA name, not the WP User name.
				WP_CLI::warning( sprintf( 'No term found for user %d.', $user_id ) );
			}

			$author_slug = preg_replace( '/^cap-/', '', $guest_term->slug );

			if ( self::$live ) {
				if ( $wp_user_term ) {
					// If the WP User term exists, delete the Guest Author term and reassign the posts.
					WP_CLI::log( 'Deleting Guest Author #' . $guest_author->ID . ' for user ' . $linked_user->data->user_login );
					$result = $coauthors_plus->guest_authors->delete( $guest_author->ID, $linked_user->data->user_login );
					if ( $result === true ) {
						WP_CLI::success( 'Deleted the guest author and reassigned the posts.' );
					} else {
						WP_CLI::warning( sprintf( 'Could not delete the guest author and reassign the posts: %s', $result->get_error_message() ) );
					}
				} else {
					// Otherwise, only delete the Guest Author post and cache, leaving the term intact.
					self::delete_guest_author_post( $guest_author->ID );
				}

				if ( $wp_user_term ) {
					// Update the user term to match the guest author term's name, so the author archive URL is preserved.
					$result = wp_update_term(
						$wp_user_term->term_id,
						'author',
						[
							'name' => $guest_term->name,
							'slug' => $guest_term->slug,
						]
					);
					if ( is_wp_error( $result ) ) {
						WP_CLI::warning( sprintf( 'Could not update the WP User CAP term: %s', $result->get_error_message() ) );
					} else {
						WP_CLI::success( sprintf( 'Updated the WP User CAP term: %d.', $result['term_id'] ) );
					}
				}
			}

			self::assign_user_props( $guest_author_data, $user_id, $author_slug );
			self::assign_user_meta( $guest_author, $user_id );

			// Add the Non-Editing Contributor role.
			$linked_user->add_role( \Newspack\Guest_Contributor_Role::CONTRIBUTOR_NO_EDIT_ROLE_NAME );
		}
	}

	/**
	 * Delete a Guest Author, without deleting the related term.
	 *
	 * @param int $guest_author_id The guest author post ID.
	 */
	private static function delete_guest_author_post( $guest_author_id ) {
		global $coauthors_plus;
		$guest_author_object = $coauthors_plus->guest_authors->get_guest_author_by( 'ID', $guest_author_id );
		$result = wp_delete_post( $guest_author_id, true );
		$coauthors_plus->guest_authors->delete_guest_author_cache( $guest_author_object );
		if ( ! $result ) {
			WP_CLI::warning( sprintf( 'Could not delete the guest author post: %s', $result->get_error_message() ) );
		} else {
			WP_CLI::success( sprintf( 'Deleted the guest author post (#%d).', $guest_author_id ) );
		}
		return $result;
	}

	/**
	 * Assign user props from guest author post's data.
	 *
	 * @param array  $guest_author_data The guest author post's data.
	 * @param int    $user_id The user ID to update the avatar for.
	 * @param string $author_slug Intended author slug. Must match the of the Guest Author slug (w/o "cap-" prefix).
	 * @return bool True if the user meta was updated, false otherwise.
	 */
	private static function assign_user_props( $guest_author_data, $user_id, $author_slug ) {
		$display_name = $guest_author_data['cap-display_name'][0];
		$user_login = $guest_author_data['cap-user_login'][0];

		if ( self::$verbose ) {
			WP_CLI::log( sprintf( 'Update display_name to %s and user_nicename to %s.', $display_name, $user_login ) );
		}
		if ( self::$live ) {
			wp_update_user(
				[
					'ID'            => $user_id,
					'display_name'  => $display_name,
					'user_nicename' => $author_slug,
				]
			);
		}
		return true;
	}

	/**
	 * Assign user meta from guest author post's meta.
	 *
	 * @param WP_Post $guest_author The guest author post.
	 * @param int     $user_id The user ID to update the avatar for.
	 * @return bool True if the user meta was updated, false otherwise.
	 */
	private static function assign_user_meta( $guest_author, $user_id ) {
		$guest_author_data = get_post_meta( $guest_author->ID );
		$assignable_meta = array_merge(
			[
				'display_name',
				'first_name',
				'last_name',
				'description',
			],
			array_values( \Newspack\Authors_Custom_Fields::USER_META_NAMES )
		);

		foreach ( $guest_author_data as $meta_key => $meta_value ) {
			if ( strpos( $meta_key, 'cap-' ) === false ) {
				continue;
			}
			$user_meta_key = str_replace( 'cap-', '', $meta_key );
			if ( ! in_array( $user_meta_key, $assignable_meta ) ) {
				continue;
			}
			$user_meta_value = $meta_value[0];
			if ( empty( $user_meta_value ) ) {
				continue;
			}
			if ( self::$verbose ) {
				WP_CLI::log( sprintf( 'User meta key: %s, value: %s', $user_meta_key, $user_meta_value ) );
			}
			$existing_meta_value = get_user_meta( $user_id, $user_meta_key, true );
			if ( $existing_meta_value && self::$verbose ) {
				WP_CLI::log( sprintf( '    - existing value: %s', $existing_meta_value ) );
			}
			if ( self::$live ) {
				if ( $existing_meta_value ) {
					update_user_meta( $user_id, '_np_original_' . $user_meta_key, $existing_meta_value );
				}
				update_user_meta( $user_id, $user_meta_key, $user_meta_value );
			}
		}
		return true;
	}

	/**
	 * Update the user avatar from guest author post's featured image.
	 *
	 * @param WP_Post $guest_author The guest author post.
	 * @param int     $user_id The user ID to update the avatar for.
	 * @return bool True if the avatar was updated, false otherwise.
	 */
	private static function assign_user_avatar( $guest_author, $user_id ) {
		global $simple_local_avatars;
		if ( ! $simple_local_avatars || ! is_a( $simple_local_avatars, 'Simple_Local_Avatars' ) ) {
			WP_CLI::warning( 'Simple Local Avatars plugin not active.' );
			return false;
		}
		$guest_author_featured_image_id = get_post_thumbnail_id( $guest_author->ID );
		if ( ! $guest_author_featured_image_id ) {
			if ( self::$verbose ) {
				WP_CLI::log( sprintf( 'No guest author image found for post %d, skipping.', $guest_author->ID ) );
			}
			return false;
		}
		$guest_author_featured_image_url = wp_get_attachment_image_url( $guest_author_featured_image_id, 'full' );
		if ( ! $guest_author_featured_image_url ) {
			WP_CLI::warning( 'Guest author image URL not found.' );
			return false;
		}

		$existing_avatar = get_user_meta( $user_id, 'simple_local_avatar', true );
		if ( ! empty( $existing_avatar ) ) {
			WP_CLI::warning( 'User already has an avatar, skipping' );
			return false;
		}

		if ( self::$verbose ) {
			WP_CLI::log( sprintf( 'Will assign image #%d to user #%d.', $guest_author_featured_image_id, $user_id ) );
		}

		if ( ! self::$live ) {
			return false;
		}

		if ( ! self::$sideload_avatar ) {
			$simple_local_avatars->assign_new_user_avatar( $guest_author_featured_image_id, $user_id );
			if ( self::$verbose ) {
				WP_CLI::success( sprintf( 'Assigned existing image with ID #%d to user #%d', $guest_author_featured_image_id, $user_id ) );
			}
		} else {

			/**
			 * Leaving sideloading avatar as an option because this was in the original implementation of this function.
			 * Not sure if there are any edge cases where the image ID can't be used directly.
			 */
			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$avatar_id = media_sideload_image( $guest_author_featured_image_url, 0, null, 'id' );

			if ( is_wp_error( $avatar_id ) ) {
				WP_CLI::warning( sprintf( 'Error sideloading avatar: %s', $avatar_id->get_error_message() ) );
				return false;
			}
			if ( is_int( $avatar_id ) ) {
				$simple_local_avatars->assign_new_user_avatar( $avatar_id, $user_id );
				if ( self::$verbose ) {
					WP_CLI::success( sprintf( 'Assigned new image with ID #%d to user #%d', $avatar_id, $user_id ) );
				}
				return true;
			}
		}
	}

	/**
	 * Reset the local WordPress object cache
	 *
	 * This only cleans the local cache in WP_Object_Cache, without
	 * affecting memcache
	 */
	private static function reset_local_object_cache() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$properties = [
			'group_ops',
			'memcache_debug',
			'cache',
		];

		foreach ( $properties as $property ) {
			if ( property_exists( $wp_object_cache, $property ) ) {
					$wp_object_cache->$property = [];
			}
		}

		if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset(); // important.
		}
	}

	/**
	 * Reset the WordPress DB query log
	 */
	private static function reset_db_query_log() {
		global $wpdb;

		$wpdb->queries = [];
	}

	/**
	 * Cleanup memory.
	 */
	private static function memory_cleanup() {
		self::reset_local_object_cache();
		self::reset_db_query_log();
	}
}
