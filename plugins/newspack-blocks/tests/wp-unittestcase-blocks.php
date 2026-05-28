<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class for Homepage Posts Block tests
 *
 * @package Newspack_Blocks
 */

/**
 * Mock CoAuthors_Plus class to pretend the plugin is active.
 */
class CoAuthors_Plus {} // phpcs:ignore

/**
 * Mock CoAuthors_Guest_Authors class.
 */
class CoAuthors_Guest_Authors { // phpcs:ignore
	public function get_guest_author_by( $field, $value ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable, Squiz.Commenting.FunctionComment.Missing
		$author = false;
		switch ( $field ) {
			case 'id':
				$authors = get_posts(
					[
						'post_type'   => 'author',
						'numberposts' => 1,
						'include'     => [ $value ],
					]
				);
				if ( ! empty( $authors ) ) {
					$author                = $authors[0];
					$author->user_nicename = $author->post_title;
				}
				break;
		}
		if ( $author ) {
			return (object) $author;
		} else {
			return false;
		}
	}
}
register_taxonomy( 'author', 'post' );


/**
 * Mock CoAuthors Plus class
 */
class CoAuthors_Plus_Mock { // phpcs:ignore

	/**
	 * Get coauthor by field mock
	 *
	 * @param string $field Only 'id' supported.
	 * @param string $value The value to search for.
	 * @return object|false
	 */
	public function get_coauthor_by( $field, $value ) {
		// search for guest authors first.
		$guest_author = get_post( $value );
		if ( $guest_author ) {
			$guest_author->type = 'guest-author';
			$guest_author->user_nicename = $guest_author->post_name;
			return $guest_author;
		}

		// search for users.
		$user = get_user_by( $field, $value );
		if ( $user ) {
			$user->type = 'wpuser';
			return $user;
		}

		return false;
	}

	/**
	 * Get the author term for a given co-author
	 *
	 * @param object $coauthor The co-author object.
	 * @return object|false $author_term The author term on success
	 */
	public function get_author_term( $coauthor ) {

		if ( ! is_object( $coauthor ) ) {
			return;
		}

		// See if the prefixed term is available, otherwise default to just the nicename.
		$term = get_term_by( 'slug', 'cap-' . $coauthor->user_nicename, 'author' );
		if ( ! $term ) {
			$term = get_term_by( 'slug', $coauthor->user_nicename, 'author' );
		}

		return $term;
	}
}

/**
 * WP_UnitTestCase which renders a page with popups.
 */
class WP_UnitTestCase_Blocks extends WP_UnitTestCase { // phpcs:ignore
	/**
	 * Get default query args, with defaults.
	 *
	 * @param array $args Query args.
	 */
	protected function get_args_with_defaults( $args ) {
		return array_merge(
			$args,
			[
				'post_status'         => [ 'publish' ],
				'suppress_filters'    => false,
				'ignore_sticky_posts' => true,
				'has_password'        => false,
				'is_newspack_query'   => true,
				'post__not_in'        => [],
			]
		);
	}

	/**
	 * Create a CAP author (guest author).
	 *
	 * @param string $name Author name.
	 */
	protected function create_guest_author( $name = false ) {
		if ( false === $name ) {
			$name = 'Test Author';
		}
		$cap_author_id = self::factory()->post->create(
			[
				'post_title' => $name,
				'post_type'  => 'author',
			]
		);
		$cap_term_id   = $this->factory->term->create(
			array(
				'name'     => $name,
				'taxonomy' => 'author',
			)
		);
		return [
			'id'      => $cap_author_id,
			'term_id' => $cap_term_id,
		];
	}

	/**
	 * Create a post with a CAP author.
	 *
	 * @param int $cap_author_term_id CAP author term ID.
	 */
	protected function create_post( $cap_author_term_id = false ) {
		$post_id = self::factory()->post->create( [ 'post_title' => 'Test Post' ] );
		if ( false !== $cap_author_term_id ) {
			wp_set_post_terms( $post_id, [ $cap_author_term_id ], 'author' );
		}
		return $post_id;
	}
}
