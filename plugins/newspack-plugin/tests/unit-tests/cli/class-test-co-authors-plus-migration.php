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
			update_post_meta( $guest_author_id, 'cap-' . $key, $value );
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
}
