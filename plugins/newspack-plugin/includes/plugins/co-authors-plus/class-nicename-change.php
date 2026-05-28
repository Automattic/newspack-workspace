<?php
/**
 * Nicename change class.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * This class adds some tweak to allow for a safe change of the nicename of a user.
 *
 * When a nicename changes it will:
 * * Create a redirect from the old nicename to the new one.
 * * Update the author term created by Co-Authors Plus.
 *
 * It will also register a new CLI command for convenience.
 */
class Nicename_Change {

	const OLD_NICENAME_META_KEY = '_np_old_nicename';

	/**
	 * Registers the hooks.
	 */
	public static function init() {
		add_action( 'profile_update', [ __CLASS__, 'profile_update' ], 10, 2 );
		add_action( 'template_redirect', [ __CLASS__, 'old_nicename_redirect' ] );

		// add a cli command to update the nicename.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'newspack nicename-change', [ __CLASS__, 'cli_change_nicename' ] );
			\WP_CLI::add_command( 'newspack nicename-check', [ __CLASS__, 'cli_check_nicename' ] );
		}
	}

	/**
	 * CLI command to check if a nicename is available.
	 *
	 * Usage: wp newspack nicename-check <new_nicename>
	 *
	 * @param array $args  The arguments.
	 */
	public static function cli_check_nicename( $args ) {
		if ( empty( $args ) ) {
			\WP_CLI::error( 'Please provide the nicename you want to check.' );
		}

		$new_nicename = $args[0];

		if ( ! self::cli_check_and_output_nicename( $new_nicename ) ) {
			\WP_CLI::error( 'Nicename not available.' );
		}

		\WP_CLI::success( $new_nicename . ' is available.' );
	}

	/**
	 * CLI command to safely change the nicename of a user.
	 *
	 * This will check the nicename availability before doing anything. If it does, it will:
	 * * Create a redirect from the old nicename to the new one.
	 * * Update the author term created by Co-Authors Plus.
	 *
	 * Usage: wp newspack nicename-change <user_id> <new_nicename>
	 *
	 * @param array $args  The arguments.
	 */
	public static function cli_change_nicename( $args ) {
		if ( empty( $args ) ) {
			\WP_CLI::error( 'Please provide the user ID and the new nicename.' );
		}

		$user_id = (int) $args[0];
		$user_data = get_user_by( 'ID', $user_id );

		if ( ! $user_data ) {
			\WP_CLI::error( 'User not found.' );
		}

		$new_nicename = $args[1];

		if ( ! self::cli_check_and_output_nicename( $new_nicename ) ) {
			\WP_CLI::error( 'Please choose a different nicename or fix the issues above by either removing the users/terms or merging the terms together.' );
		}

		// Update the nicename.
		wp_update_user(
			[
				'ID'            => $user_id,
				'user_nicename' => $new_nicename,
			]
		);

		\WP_CLI::success( 'Nicename updated.' );
	}

	/**
	 * Checks if the nicename is already in use and outputs it to CLI.
	 *
	 * @param string $nicename  The nicename to exclude.
	 * @return bool True if the nicename is available, false otherwise.
	 */
	private static function cli_check_and_output_nicename( $nicename ) {
		$existing_nicenames = self::get_existing_nicenames( $nicename );

		if ( ! empty( $existing_nicenames ) ) {
			\WP_CLI::line( 'This nicename is already in use:' );

			foreach ( $existing_nicenames as $existing_nicename ) {
				\WP_CLI::line( sprintf( ' - %s: %s (ID: %d, %d posts)', $existing_nicename['type'], $existing_nicename['value'], $existing_nicename['id'], $existing_nicename['num_posts'] ) );
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks if the nicename is already in use and return the usaged is it is.
	 *
	 * This will check both user_nicename and the author taxonomy used by Co-Authors Plus.
	 *
	 * @param string $nicename  The nicename to exclude.
	 * @return array An array with the existing usages of this nicename. Each item of the array will be an array with the following keys:
	 * * type: The type of usage (user_nicename or author taxonomy).
	 * * id: The ID of the item using this nicename.
	 * * value: Either the nicename or the term slug.
	 * * num_posts: The number of posts associated with this nicename.
	 */
	public static function get_existing_nicenames( $nicename ) {
		$existing_nicenames = [];

		// Check the user_nicename.
		$user = get_user_by( 'slug', $nicename );
		if ( $user ) {
			$existing_nicenames[] = [
				'type'      => 'WP User',
				'id'        => $user->ID,
				'value'     => $user->user_nicename,
				'num_posts' => self::get_num_of_posts( $nicename ),
			];
		}

		// Check the author taxonomy.
		$term = get_term_by( 'slug', $nicename, 'author' );
		if ( $term ) {
			$existing_nicenames[] = [
				'type'      => 'CAP Author term',
				'id'        => $term->term_id,
				'value'     => $term->slug,
				'num_posts' => self::get_num_of_posts( $nicename ),
			];
		}
		$term = get_term_by( 'slug', 'cap-' . $nicename, 'author' );
		if ( $term ) {
			$existing_nicenames[] = [
				'type'      => 'CAP Author term',
				'id'        => $term->term_id,
				'value'     => $term->slug,
				'num_posts' => self::get_num_of_posts( $nicename ),
			];
		}

		return $existing_nicenames;
	}

	/**
	 * Gets the number of posts associated with a nicename.
	 *
	 * @param string $nicename  The nicename to check.
	 * @return int The number of posts associated with this nicename.
	 */
	private static function get_num_of_posts( $nicename ) {
		global $coauthors_plus, $wpdb;
		$cap_enabled = false;
		if ( is_object( $coauthors_plus ) && method_exists( $coauthors_plus, 'search_authors' ) ) {
			$cap_enabled = true;
		}

		if ( $cap_enabled ) {
			$term = get_term_by( 'slug', $nicename, 'author' );
			if ( ! $term ) {
				return 0;
			}
			$query = $wpdb->prepare( "SELECT COUNT(object_id) FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d", $term->term_taxonomy_id );
		} else {
			$user = get_user_by( 'slug', $nicename );
			if ( ! $user ) {
				return 0;
			}
			$query = $wpdb->prepare( "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_author = %d", $user->ID );
		}

		return (int) $wpdb->get_var( $query ); //phpcs:ignore
	}

	/**
	 * Nicename update listener.
	 *
	 * @param int    $user_id        The user ID.
	 * @param object $old_user_data  The old user data.
	 */
	public static function profile_update( $user_id, $old_user_data ) {

		$user_data = get_user( $user_id );

		if ( ! $user_data || $old_user_data->user_nicename === $user_data->user_nicename ) {
			return;
		}

		self::handle_old_nicename_meta( $user_id, $old_user_data->user_nicename, $user_data->user_nicename );
		self::update_author_term( $user_id, $old_user_data->user_nicename, $user_data->user_nicename );
	}

	/**
	 * Handles the old nicename meta.
	 *
	 * @param int    $user_id       The user ID.
	 * @param string $old_nicename  The old nicename.
	 * @param string $new_nicename  The new nicename.
	 */
	public static function handle_old_nicename_meta( $user_id, $old_nicename, $new_nicename ) {
		$old_nicename_meta = (array) get_user_meta( $user_id, self::OLD_NICENAME_META_KEY, false );

		// If we haven't added this old nicename before, add it now.
		if ( ! empty( $old_nicename ) && ! in_array( $old_nicename, $old_nicename_meta, true ) ) {
			add_user_meta( $user_id, self::OLD_NICENAME_META_KEY, $old_nicename );
		}

		// If the new nicename was used previously, delete it from the list.
		if ( in_array( $new_nicename, $old_nicename_meta, true ) ) {
			delete_user_meta( $user_id, self::OLD_NICENAME_META_KEY, $new_nicename );
		}
	}

	/**
	 * Updates the author term created by Co-Authors Plus.
	 *
	 * @param int    $user_id       The user ID.
	 * @param string $old_nicename  The old nicename.
	 * @param string $new_nicename  The new nicename.
	 */
	public static function update_author_term( $user_id, $old_nicename, $new_nicename ) {
		$term = get_term_by( 'slug', 'cap-' . $old_nicename, 'author' );
		if ( ! $term ) {
			$term = get_term_by( 'slug', $old_nicename, 'author' );
		}

		if ( ! $term ) {
			return;
		}

		wp_update_term(
			$term->term_id,
			'author',
			[
				'slug' => 'cap-' . $new_nicename,
			]
		);
	}

	/**
	 * Redirect old nicenames to the correct permalink.
	 *
	 * Attempts to find the current nicename from the past nicenames.
	 */
	public static function old_nicename_redirect() {

		if ( ( is_404() || ! is_author() ) && '' !== get_query_var( 'author_name' ) ) {

			global $wpdb;

			$new_user_id = $wpdb->get_var( // phpcs:ignore
				$wpdb->prepare(
					"SELECT user_id FROM $wpdb->usermeta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
					self::OLD_NICENAME_META_KEY,
					get_query_var( 'author_name' )
				)
			);

			if ( empty( $new_user_id ) ) {
				return;
			}

			$link = get_author_posts_url( $new_user_id );

			if ( ! $link ) {
				return;
			}

			wp_safe_redirect( $link, 301 ); // Permanent redirect.
			exit;
		}
	}
}

Nicename_Change::init();
