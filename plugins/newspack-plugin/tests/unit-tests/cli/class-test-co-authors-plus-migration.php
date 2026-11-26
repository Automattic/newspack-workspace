<?php // phpcs:disable Squiz.Commenting, Generic.Files.OneObjectStructurePerFile
/**
 * Tests the Co-Authors Plus guest author migration command.
 *
 * @package Newspack\Tests
 */

require_once __DIR__ . '/../../mocks/wp-cli-mocks.php';
require_once dirname( __DIR__, 3 ) . '/includes/cli/class-co-authors-plus.php';

if ( ! class_exists( 'CoAuthors_Guest_Authors' ) ) {
	/**
	 * Stand-in for CAP's guest author API. The command only needs the class to exist
	 * and to hand back guest author objects.
	 */
	class CoAuthors_Guest_Authors {
		public function get_guest_author_by( $key, $value ) {
			$post       = get_post( $value );
			$user_login = get_post_meta( $post->ID, 'cap-user_login', true );
			return (object) [
				'ID'             => $post->ID,
				'user_login'     => $user_login,
				'user_nicename'  => sanitize_title( $user_login ),
				'linked_account' => get_post_meta( $post->ID, 'cap-linked_account', true ),
				'type'           => 'guest-author',
			];
		}

		public function delete_guest_author_cache( $guest_author ) {}

		// Stands in for the one effect these tests need: the guest author post and its meta are gone.
		public function delete( $id, $reassign_to = false ) {
			wp_delete_post( $id, true );
			return true;
		}
	}
}

/**
 * Tests the guest author migration command.
 *
 * @group cli
 */
class Newspack_Test_Co_Authors_Plus_Migration extends WP_UnitTestCase {

	private $previous_coauthors_plus;

	public function set_up() {
		parent::set_up();
		WP_CLI::reset();
		register_post_type( 'guest-author' );
		register_taxonomy( 'author', [ 'post', 'guest-author' ] );
		$this->previous_coauthors_plus = $GLOBALS['coauthors_plus'] ?? null;

		/*
		 * Copies the term lookup of Co-Authors Plus 4.2.2, including the check that
		 * reads user_login when the term slug is exactly the nicename passed in.
		 */
		$GLOBALS['coauthors_plus'] = new class() {
			public $guest_authors;

			public function __construct() {
				$this->guest_authors = new CoAuthors_Guest_Authors();
			}

			public function get_author_term( $coauthor ) {
				$term = get_term_by( 'slug', 'cap-' . $coauthor->user_nicename, 'author' );
				if ( ! $term ) {
					$term = get_term_by( 'slug', $coauthor->user_nicename, 'author' );
				}
				if (
					$term
					&& $term->slug === $coauthor->user_nicename
					&& str_starts_with( $term->slug, 'cap-' )
					&& $term->name !== ( $coauthor->user_login ?? null )
				) {
					return false;
				}
				return $term;
			}
		};
	}

	public function tear_down() {
		$GLOBALS['coauthors_plus'] = $this->previous_coauthors_plus;
		unregister_taxonomy( 'author' );
		unregister_post_type( 'guest-author' );
		parent::tear_down();
	}

	private function create_guest_author( $login, $meta = [] ) {
		$guest_author_id = self::factory()->post->create(
			[
				'post_type' => 'guest-author',
				'post_name' => 'cap-' . $login,
			]
		);
		$meta = array_merge(
			[
				'user_login'   => $login,
				'display_name' => 'Guest ' . $login,
			],
			$meta
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $guest_author_id, 'cap-' . $key, wp_slash( $value ) );
		}
		return $guest_author_id;
	}

	private function migrate( $assoc_args = [] ) {
		( new Newspack\CLI\Co_Authors_Plus() )->migrate_guest_authors( [], $assoc_args );
	}

	/**
	 * A linked user without an author term takes over the guest author's term.
	 */
	public function test_live_run_migrates_linked_guest_author() {
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'linked123',
				'role'       => 'administrator',
			]
		);
		$term_id         = wp_insert_term( 'jane-doe', 'author', [ 'slug' => 'cap-jane-doe' ] )['term_id'];
		$guest_author_id = $this->create_guest_author( 'jane-doe', [ 'linked_account' => 'linked123' ] );

		$this->migrate( [ 'live' => true ] );

		$user = get_userdata( $user_id );
		$this->assertSame( 'jane-doe', $user->user_nicename );
		$this->assertNull( get_post( $guest_author_id ) );
		$this->assertNotNull( get_term( $term_id, 'author' ) );
		$this->assertContains( 'administrator', $user->roles );
		$this->assertContains( 'contributor_no_edit', $user->roles );
	}

	public function test_dry_run_leaves_linked_user_unchanged() {
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'linked123',
				'role'       => 'administrator',
			]
		);
		wp_insert_term( 'jane-doe', 'author', [ 'slug' => 'cap-jane-doe' ] );
		$guest_author_id = $this->create_guest_author( 'jane-doe', [ 'linked_account' => 'linked123' ] );

		$this->migrate();

		$user = get_userdata( $user_id );
		$this->assertSame( [ 'administrator' ], array_values( $user->roles ) );
		$this->assertSame( 'linked123', $user->user_nicename );
		$this->assertNotNull( get_post( $guest_author_id ) );
		$this->assertStringContainsString( 'Would add the Non-Editing Contributor role', implode( "\n", WP_CLI::$logs ) );
		$this->assertSame( [], get_user_meta( $user_id, '_np_cap_guest_author_migration_data', false ) );
	}

	public function linked_user_terms() {
		return [
			'linked user without an author term' => [ false ],
			'linked user with an author term'    => [ true ],
		];
	}

	/**
	 * The guest author is deleted during the run, so anything not copied is lost.
	 *
	 * @dataProvider linked_user_terms
	 */
	public function test_live_run_copies_the_guest_profile_to_the_linked_user( $has_own_term ) {
		$user_id = self::factory()->user->create( [ 'user_login' => 'linked123' ] );
		if ( $has_own_term ) {
			wp_insert_term( 'linked123', 'author', [ 'slug' => 'cap-linked123' ] );
		}
		wp_insert_term( 'jane-doe', 'author', [ 'slug' => 'cap-jane-doe' ] );
		$profile = [
			'first_name'         => 'Jane',
			'last_name'          => 'Doe',
			'description'        => 'Guest bio',
			'newspack_job_title' => 'Guest title',
		];
		$this->create_guest_author( 'jane-doe', array_merge( [ 'linked_account' => 'linked123' ], $profile ) );

		$this->migrate( [ 'live' => true ] );

		foreach ( $profile as $key => $value ) {
			$this->assertSame( $value, get_user_meta( $user_id, $key, true ), $key );
		}
	}

	public function unusable_slugs() {
		return [
			'held by another user, live run' => [ 'jane-doe', true, [ 'live' => true ] ],
			'held by another user, dry run'  => [ 'jane-doe', true, [] ],
			'over 50 characters, live run'   => [ str_repeat( 'long-', 11 ), false, [ 'live' => true ] ],
			'over 50 characters, dry run'    => [ str_repeat( 'long-', 11 ), false, [] ],
		];
	}

	/**
	 * Migrating either would move the guest author's posts to another byline, or to none.
	 *
	 * @dataProvider unusable_slugs
	 */
	public function test_linked_guest_author_with_an_unusable_slug_is_left_alone( $slug, $held_by_another_user, $args ) {
		$slug    = rtrim( $slug, '-' );
		$user_id = self::factory()->user->create(
			[
				'user_login' => 'linked123',
				'role'       => 'administrator',
			]
		);
		if ( $held_by_another_user ) {
			self::factory()->user->create( [ 'user_nicename' => $slug ] );
		}
		$term_id         = wp_insert_term( $slug, 'author', [ 'slug' => 'cap-' . $slug ] )['term_id'];
		$guest_author_id = $this->create_guest_author( $slug, [ 'linked_account' => 'linked123' ] );

		$this->migrate( $args );

		$user = get_userdata( $user_id );
		$this->assertNotNull( get_post( $guest_author_id ) );
		$this->assertSame( 'cap-' . $slug, get_term( $term_id, 'author' )->slug );
		$this->assertSame( 'linked123', $user->user_nicename );
		$this->assertSame( [ 'administrator' ], array_values( $user->roles ) );
		$this->assertStringContainsString( sprintf( 'Skipping guest author %d', $guest_author_id ), implode( "\n", WP_CLI::$warnings ) );
		$this->assertNotContains( 'Simple Local Avatars plugin not active.', WP_CLI::$warnings );
	}

	public function test_live_run_reports_a_failed_update_of_a_linked_user() {
		self::factory()->user->create( [ 'user_login' => 'linked123' ] );
		wp_insert_term( 'jane-doe', 'author', [ 'slug' => 'cap-jane-doe' ] );
		$this->create_guest_author( 'jane-doe', [ 'linked_account' => 'linked123' ] );
		// An empty result from this filter makes WordPress refuse the user write.
		add_filter( 'wp_pre_insert_user_data', '__return_empty_array' );

		$this->migrate( [ 'live' => true ] );

		$this->assertStringContainsString( 'Could not update user', implode( "\n", WP_CLI::$warnings ) );
	}

	public function test_live_run_backs_up_the_linked_user_name_and_nicename() {
		$user_id = self::factory()->user->create(
			[
				'user_login'   => 'linked123',
				'display_name' => 'Original Name',
			]
		);
		wp_insert_term( 'jane-doe', 'author', [ 'slug' => 'cap-jane-doe' ] );
		$this->create_guest_author( 'jane-doe', [ 'linked_account' => 'linked123' ] );

		$this->migrate( [ 'live' => true ] );

		$backup = get_user_meta( $user_id, '_np_cap_guest_author_migration_data', true );
		$this->assertSame( 'Original Name', $backup['display_name'] );
		$this->assertSame( 'linked123', $backup['user_nicename'] );
	}

	public function test_live_run_keeps_the_linked_user_name_when_the_guest_name_is_blank() {
		$user_id = self::factory()->user->create(
			[
				'user_login'   => 'linked123',
				'display_name' => 'Original Name',
			]
		);
		wp_insert_term( 'jane-doe', 'author', [ 'slug' => 'cap-jane-doe' ] );
		$this->create_guest_author(
			'jane-doe',
			[
				'linked_account' => 'linked123',
				'display_name'   => ' ',
			]
		);

		$this->migrate( [ 'live' => true ] );

		$this->assertSame( 'Original Name', get_userdata( $user_id )->display_name );
	}

	public function test_live_run_keeps_backslashes_for_a_linked_user() {
		$user_id = self::factory()->user->create( [ 'user_login' => 'linked123' ] );
		update_user_meta( $user_id, 'newspack_job_title', wp_slash( 'Old A\\B' ) );
		wp_insert_term( 'jane-doe', 'author', [ 'slug' => 'cap-jane-doe' ] );
		$this->create_guest_author(
			'jane-doe',
			[
				'linked_account'     => 'linked123',
				'display_name'       => 'Jane C\\D',
				'newspack_job_title' => 'New E\\F',
			]
		);

		$this->migrate( [ 'live' => true ] );

		$this->assertSame( 'Jane C\\D', get_userdata( $user_id )->display_name );
		$this->assertSame( 'New E\\F', get_user_meta( $user_id, 'newspack_job_title', true ) );
		$this->assertSame( 'Old A\\B', get_user_meta( $user_id, '_np_original_newspack_job_title', true ) );
		$this->assertSame( 'Old A\\B', get_user_meta( $user_id, '_np_cap_guest_author_migration_data', true )['newspack_job_title'] );
	}

	public function test_dry_run_stores_no_backup_for_an_existing_user() {
		$user_id = self::factory()->user->create( [ 'user_nicename' => 'jane-doe' ] );
		$this->create_guest_author( 'jane-doe' );

		$this->migrate();

		$this->assertSame( [], get_user_meta( $user_id, '_np_cap_guest_author_migration_data', false ) );
	}

	public function test_updating_existing_user_keeps_values_the_guest_author_lacks() {
		$user_id = self::factory()->user->create(
			[
				'user_nicename' => 'jane-doe',
				'user_url'      => 'https://existing.example/',
			]
		);
		update_user_meta( $user_id, 'newspack_job_title', 'Existing title' );
		$this->create_guest_author( 'jane-doe', [ 'newspack_job_title' => '' ] );

		$this->migrate( [ 'live' => true ] );

		$this->assertSame( 'https://existing.example/', get_userdata( $user_id )->user_url );
		$this->assertSame( 'Existing title', get_user_meta( $user_id, 'newspack_job_title', true ) );
	}

	public function test_updating_existing_user_backs_up_values_it_replaces() {
		$user_id = self::factory()->user->create(
			[
				'user_nicename' => 'jane-doe',
				'user_url'      => 'https://existing.example/',
			]
		);
		update_user_meta( $user_id, 'newspack_job_title', 'Existing title' );
		$this->create_guest_author(
			'jane-doe',
			[
				'website'            => 'https://guest.example/',
				'newspack_job_title' => 'Guest title',
			]
		);

		$this->migrate( [ 'live' => true ] );

		$this->assertSame( 'https://guest.example/', get_userdata( $user_id )->user_url );
		$this->assertSame( 'Guest title', get_user_meta( $user_id, 'newspack_job_title', true ) );
		$backup = get_user_meta( $user_id, '_np_cap_guest_author_migration_data', true );
		$this->assertSame( 'https://existing.example/', $backup['user_url'] );
		$this->assertSame( 'Existing title', $backup['newspack_job_title'] );
	}

	public function test_live_run_keeps_backslashes_for_new_and_updated_users() {
		$user_id = self::factory()->user->create( [ 'user_nicename' => 'jane-doe' ] );
		update_user_meta( $user_id, 'description', wp_slash( 'Old C:\\bio' ) );
		$this->create_guest_author( 'jane-doe', [ 'description' => 'Guest D:\\bio' ] );
		$this->create_guest_author( 'new-writer', [ 'newspack_job_title' => 'Editor E:\\desk' ] );

		$this->migrate( [ 'live' => true ] );

		$this->assertSame( 'Guest D:\\bio', get_user_meta( $user_id, 'description', true ) );
		$this->assertSame( 'Old C:\\bio', get_user_meta( $user_id, '_np_cap_guest_author_migration_data', true )['description'] );
		$this->assertSame( 'Editor E:\\desk', get_user_meta( get_user_by( 'login', 'new-writer' )->ID, 'newspack_job_title', true ) );
	}

	public function test_verbose_dry_run_describes_only_what_a_live_run_would_do() {
		self::factory()->user->create( [ 'user_nicename' => 'jane-doe' ] );
		$this->create_guest_author( 'jane-doe', [ 'display_name' => ' ' ] );

		$this->migrate( [ 'verbose' => true ] );

		$logs = implode( "\n", WP_CLI::$logs );
		$this->assertStringContainsString( 'would be stored in _np_cap_guest_author_migration_data', $logs );
		$this->assertStringNotContainsString( 'Display name will be updated', $logs );
	}
}
