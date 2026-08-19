<?php
/**
 * Test_Accessibility_Statement_Page class.
 *
 * @package Newspack
 */

use Newspack\Accessibility_Statement_Page;

/**
 * Class Test_Accessibility_Statement_Page
 *
 * @group accessibility-statement
 */
class Test_Accessibility_Statement_Page extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		delete_option( Accessibility_Statement_Page::OPTION_NAME );
		delete_option( Accessibility_Statement_Page::MIGRATION_FLAG );
		remove_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD );
	}

	/**
	 * Count every accessibility statement page on the site, trash included.
	 *
	 * `post_status => any` omits statuses flagged as internal, and trash is one
	 * of them, so the statuses are listed out.
	 *
	 * @return int
	 */
	private function count_pages() {
		return count(
			get_posts(
				[
					'post_type'      => 'page',
					'post_status'    => array_keys( get_post_stati() ),
					'posts_per_page' => 100,
					'title'          => 'Accessibility Statement',
					'fields'         => 'ids',
				]
			)
		);
	}

	/**
	 * Make a page that stands in for one a site created before this upgrade.
	 *
	 * @param string $status Post status.
	 * @return int The page ID.
	 */
	private function make_page( $status = 'draft' ) {
		return $this->factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => $status,
				'post_title'  => 'Accessibility Statement',
			]
		);
	}

	/**
	 * The stylesheet of a theme that is installed but not active.
	 *
	 * @return string
	 */
	private function inactive_stylesheet() {
		$inactive = array_values( array_diff( array_keys( wp_get_themes() ), [ get_stylesheet() ] ) );
		$this->assertNotEmpty( $inactive, 'Needs a second installed theme to test against.' );

		return $inactive[0];
	}

	/**
	 * Reading the page must never write one. This is the front-end footer's
	 * entry point, so a write here runs on every render, for logged-out
	 * visitors included.
	 */
	public function test_get_page_does_not_create_anything() {
		$before = $this->count_pages();

		$this->assertFalse( Accessibility_Statement_Page::get_page() );
		$this->assertSame( $before, $this->count_pages() );
	}

	/**
	 * Repeated reads must stay free of side effects.
	 */
	public function test_repeated_reads_create_nothing() {
		$before = $this->count_pages();

		for ( $i = 0; $i < 5; $i++ ) {
			Accessibility_Statement_Page::get_page();
		}

		$this->assertSame( $before, $this->count_pages() );
	}

	/**
	 * A stored page is returned with the fields the wizard and theme read.
	 */
	public function test_get_page_returns_the_stored_page() {
		$created = Accessibility_Statement_Page::create_page();
		$page    = Accessibility_Statement_Page::get_page();

		$this->assertIsArray( $page );
		$this->assertSame( 'draft', $page['status'] );
		$this->assertSame( $created['editUrl'], $page['editUrl'] );
		$this->assertNotEmpty( $page['pageUrl'] );
		$this->assertSame( 'Accessibility Statement', $page['title'] );
	}

	/**
	 * A trashed page reads as absent, and reading it still writes nothing.
	 */
	public function test_get_page_returns_false_when_the_stored_page_is_trashed() {
		Accessibility_Statement_Page::create_page();
		wp_trash_post( get_option( Accessibility_Statement_Page::OPTION_NAME ) );

		$before = $this->count_pages();

		$this->assertFalse( Accessibility_Statement_Page::get_page() );
		$this->assertSame( $before, $this->count_pages() );
	}

	/**
	 * Creating stores the pointer and yields exactly one draft.
	 */
	public function test_create_page_creates_a_single_draft() {
		$before = $this->count_pages();
		$result = Accessibility_Statement_Page::create_page();

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( $before + 1, $this->count_pages() );

		$page_id = get_option( Accessibility_Statement_Page::OPTION_NAME );
		$this->assertSame( 'page', get_post_type( $page_id ) );
		$this->assertNotEmpty( get_post( $page_id )->post_content );
	}

	/**
	 * A second create hands back the page that already exists, rather than
	 * adding another.
	 */
	public function test_create_page_returns_the_existing_page_instead_of_a_duplicate() {
		$first  = Accessibility_Statement_Page::create_page();
		$before = $this->count_pages();
		$second = Accessibility_Statement_Page::create_page();

		$this->assertSame( $first['editUrl'], $second['editUrl'] );
		$this->assertSame( $before, $this->count_pages() );
	}

	/**
	 * A published page is equally protected from being replaced.
	 */
	public function test_create_page_does_not_replace_a_published_page() {
		Accessibility_Statement_Page::create_page();
		$page_id = get_option( Accessibility_Statement_Page::OPTION_NAME );
		wp_update_post(
			[
				'ID'          => $page_id,
				'post_status' => 'publish',
			]
		);

		$before = $this->count_pages();
		$result = Accessibility_Statement_Page::create_page();

		$this->assertSame( $before, $this->count_pages() );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( $page_id, get_option( Accessibility_Statement_Page::OPTION_NAME ) );
	}

	/**
	 * Once the stored page is gone, creating gives the site a fresh one, and
	 * the trashed original stays where the publisher left it.
	 */
	public function test_create_page_replaces_a_trashed_page() {
		Accessibility_Statement_Page::create_page();
		$trashed_id = get_option( Accessibility_Statement_Page::OPTION_NAME );
		wp_trash_post( $trashed_id );

		$before = $this->count_pages();
		$result = Accessibility_Statement_Page::create_page();
		$new_id = get_option( Accessibility_Statement_Page::OPTION_NAME );

		$this->assertIsArray( $result );
		$this->assertNotEquals( $trashed_id, $new_id );
		$this->assertSame( 'draft', get_post_status( $new_id ) );
		$this->assertSame( 'trash', get_post_status( $trashed_id ) );
		$this->assertSame( $before + 1, $this->count_pages() );
	}

	/**
	 * The pointer is site-wide, so changing theme or style variation keeps it.
	 */
	public function test_the_stored_page_survives_a_theme_switch() {
		$created = Accessibility_Statement_Page::create_page();
		$before  = $this->count_pages();

		switch_theme( $this->inactive_stylesheet() );

		$page = Accessibility_Statement_Page::get_page();

		$this->assertIsArray( $page );
		$this->assertSame( $created['editUrl'], $page['editUrl'] );
		$this->assertSame( $before, $this->count_pages() );
	}

	/**
	 * Before the upgrade has run, the page is still found through the theme
	 * mod. Without this, a site loses its footer link and its content-gate
	 * exemption for the whole window between updating and the next admin request.
	 */
	public function test_the_legacy_theme_mod_is_read_before_migration_runs() {
		$page_id = $this->make_page( 'publish' );
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, $page_id );

		$this->assertSame( $page_id, Accessibility_Statement_Page::get_page_id() );

		$page = Accessibility_Statement_Page::get_page();
		$this->assertIsArray( $page );
		$this->assertSame( 'publish', $page['status'] );
	}

	/**
	 * The ID the content gate compares against is an integer, so its strict
	 * comparison with a post ID holds.
	 */
	public function test_the_stored_id_is_an_integer_for_the_content_gate() {
		$page_id = $this->make_page();
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, (string) $page_id );
		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$stored = Accessibility_Statement_Page::get_page_id();

		$this->assertIsInt( $stored );
		$this->assertTrue( get_post( $page_id )->ID === $stored );
	}

	/**
	 * Sites that stored the pointer as a theme mod keep their existing page.
	 * The legacy mod is deliberately left in place, so rolling the plugin back
	 * finds the page where the old code looks for it.
	 */
	public function test_a_legacy_theme_mod_is_adopted() {
		$page_id = $this->make_page();
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, $page_id );

		$before = $this->count_pages();
		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( $page_id, (int) get_option( Accessibility_Statement_Page::OPTION_NAME ) );
		$this->assertSame( $page_id, (int) get_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD ) );
		$this->assertSame( $before, $this->count_pages() );

		$page = Accessibility_Statement_Page::get_page();
		$this->assertIsArray( $page );
		$this->assertSame( get_permalink( $page_id ), $page['pageUrl'] );
	}

	/**
	 * A site that switched theme after creating its page left the ID behind in
	 * the mods of the theme it was using at the time, so migration has to look
	 * past the active theme to find it.
	 */
	public function test_a_theme_mod_from_an_inactive_theme_is_adopted() {
		$page_id  = $this->make_page();
		$inactive = $this->inactive_stylesheet();

		update_option(
			'theme_mods_' . $inactive,
			[ Accessibility_Statement_Page::LEGACY_THEME_MOD => $page_id ]
		);

		$before = $this->count_pages();
		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( $page_id, Accessibility_Statement_Page::get_page_id() );
		$this->assertSame( $before, $this->count_pages() );

		delete_option( 'theme_mods_' . $inactive );
	}

	/**
	 * Where a site accumulated duplicates across themes, the published page is
	 * the statement the publisher actually maintains.
	 */
	public function test_a_published_candidate_wins_over_a_draft() {
		$draft     = $this->make_page();
		$published = $this->make_page( 'publish' );
		$inactive  = $this->inactive_stylesheet();

		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, $draft );
		update_option(
			'theme_mods_' . $inactive,
			[ Accessibility_Statement_Page::LEGACY_THEME_MOD => $published ]
		);

		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( $published, Accessibility_Statement_Page::get_page_id() );

		delete_option( 'theme_mods_' . $inactive );
	}

	/**
	 * A theme mod pointing at a trashed page is skipped, and the scan carries
	 * on to the other themes rather than giving up.
	 */
	public function test_a_trashed_candidate_falls_through_to_another_theme() {
		$trashed  = $this->make_page();
		$usable   = $this->make_page();
		$inactive = $this->inactive_stylesheet();
		wp_trash_post( $trashed );

		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, $trashed );
		update_option(
			'theme_mods_' . $inactive,
			[ Accessibility_Statement_Page::LEGACY_THEME_MOD => $usable ]
		);

		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( $usable, Accessibility_Statement_Page::get_page_id() );

		delete_option( 'theme_mods_' . $inactive );
	}

	/**
	 * Deleting a theme leaves its mods stranded in the options table, where an
	 * installed-theme scan cannot reach them. Reading the options table directly
	 * still finds the page, so the publisher is not invited to duplicate it.
	 */
	public function test_a_theme_mod_from_a_deleted_theme_is_adopted() {
		$page_id = $this->make_page( 'publish' );
		update_option(
			'theme_mods_a-theme-that-is-no-longer-installed',
			[ Accessibility_Statement_Page::LEGACY_THEME_MOD => $page_id ]
		);

		$before = $this->count_pages();
		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( $page_id, Accessibility_Statement_Page::get_page_id() );
		$this->assertSame( $before, $this->count_pages() );

		delete_option( 'theme_mods_a-theme-that-is-no-longer-installed' );
	}

	/**
	 * A site with no pointer anywhere must not adopt an unrelated page that
	 * happens to sit at the reserved slug.
	 */
	public function test_an_unrelated_page_at_the_slug_is_not_adopted() {
		$this->factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Our accessibility commitments',
				'post_name'   => Accessibility_Statement_Page::PAGE_SLUG,
			]
		);

		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( 0, Accessibility_Statement_Page::get_page_id() );
		$this->assertFalse( Accessibility_Statement_Page::get_page() );
	}

	/**
	 * A page sitting in the trash when the upgrade runs keeps its pointer, so
	 * restoring it brings the link back instead of the site being told it never
	 * had a page and invited to create a second one.
	 */
	public function test_a_trashed_page_keeps_its_pointer_through_migration() {
		$page_id = $this->make_page( 'publish' );
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, $page_id );
		wp_trash_post( $page_id );

		$before = $this->count_pages();
		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( $page_id, Accessibility_Statement_Page::get_page_id() );
		$this->assertFalse( Accessibility_Statement_Page::get_page() );
		$this->assertSame( 'missing', Accessibility_Statement_Page::api_get_page()->get_data()['status'] );

		wp_untrash_post( $page_id );
		wp_update_post(
			[
				'ID'          => $page_id,
				'post_status' => 'publish',
			]
		);

		$page = Accessibility_Statement_Page::get_page();
		$this->assertIsArray( $page );
		$this->assertSame( 'publish', $page['status'] );
		$this->assertSame( $before, $this->count_pages() );
	}

	/**
	 * Creating leaves the legacy pointer behind too, so a plugin rolled back to
	 * before this change finds the page instead of making another.
	 */
	public function test_create_page_leaves_a_rollback_breadcrumb() {
		$result = Accessibility_Statement_Page::create_page();

		$this->assertIsArray( $result );
		$this->assertSame(
			(int) get_option( Accessibility_Statement_Page::OPTION_NAME ),
			(int) get_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD )
		);
	}

	/**
	 * A theme mod pointing at a page that no longer exists is not adopted, and
	 * is cleared: get_post() does not cache a miss, so leaving it would send
	 * every render, gate check and list-table row back to the database.
	 */
	public function test_a_theme_mod_pointing_at_a_deleted_page_is_ignored() {
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, 999999 );

		$before = $this->count_pages();
		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( 0, Accessibility_Statement_Page::get_page_id() );
		$this->assertFalse( get_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD ) );
		$this->assertSame( $before, $this->count_pages() );
	}

	/**
	 * A page that still exists beats a stored pointer that no longer resolves,
	 * which is the state a rollback leaves behind when the publisher deleted
	 * the original and the older plugin made a replacement.
	 */
	public function test_a_live_legacy_page_beats_a_stored_pointer_that_is_gone() {
		$deleted     = $this->make_page();
		$replacement = $this->make_page( 'publish' );
		update_option( Accessibility_Statement_Page::OPTION_NAME, $deleted );
		wp_delete_post( $deleted, true );
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, $replacement );

		$this->assertSame( $replacement, Accessibility_Statement_Page::get_page_id() );

		$page = Accessibility_Statement_Page::get_page();
		$this->assertIsArray( $page );
		$this->assertSame( 'publish', $page['status'] );
	}

	/**
	 * The post-state filter tolerates a caller that passes something other than
	 * a post object, rather than fatalling the posts list screen.
	 */
	public function test_post_state_tolerates_an_unexpected_argument() {
		Accessibility_Statement_Page::create_page();

		$this->assertSame( [], Accessibility_Statement_Page::post_status( [], 123 ) );
		$this->assertSame( [], Accessibility_Statement_Page::post_status( null, null ) );
	}

	/**
	 * Migration must not overwrite a pointer the site already has.
	 */
	public function test_migration_leaves_an_existing_pointer_alone() {
		Accessibility_Statement_Page::create_page();
		$page_id = (int) get_option( Accessibility_Statement_Page::OPTION_NAME );
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, 999999 );

		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( $page_id, (int) get_option( Accessibility_Statement_Page::OPTION_NAME ) );
	}

	/**
	 * Rolling the plugin back and forward again leaves a pointer on the active
	 * theme. It is adopted rather than ignored, or the wizard would offer to
	 * create a second page beside the one the rollback just made.
	 */
	public function test_a_pointer_written_after_migration_is_adopted() {
		Accessibility_Statement_Page::migrate_legacy_theme_mod();
		$this->assertSame( 0, Accessibility_Statement_Page::get_page_id() );

		$page_id = $this->make_page( 'publish' );
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, $page_id );

		$this->assertSame( $page_id, Accessibility_Statement_Page::get_page_id() );

		Accessibility_Statement_Page::migrate_legacy_theme_mod();
		$this->assertSame( $page_id, (int) get_option( Accessibility_Statement_Page::OPTION_NAME ) );
	}

	/**
	 * A pointer left behind for a page that has since been deleted is still
	 * ignored, so the site is not left claiming a page it does not have.
	 */
	public function test_a_pointer_at_a_deleted_page_stays_ignored_after_migration() {
		Accessibility_Statement_Page::migrate_legacy_theme_mod();
		set_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD, 999999 );

		$this->assertSame( 0, Accessibility_Statement_Page::get_page_id() );
		$this->assertFalse( Accessibility_Statement_Page::get_page() );
	}

	/**
	 * The completion flag is recorded even when there was nothing to adopt, and
	 * is autoloaded so the debug reset sweeps it along with the pointer.
	 */
	public function test_the_migration_flag_is_recorded_and_autoloaded() {
		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertNotFalse( get_option( Accessibility_Statement_Page::MIGRATION_FLAG ) );
		$this->assertArrayHasKey(
			Accessibility_Statement_Page::MIGRATION_FLAG,
			wp_load_alloptions()
		);
	}

	/**
	 * Adopting a page from an inactive theme leaves the breadcrumb on the
	 * active one, where a rolled-back plugin looks for it.
	 */
	public function test_migration_leaves_a_rollback_breadcrumb() {
		$page_id  = $this->make_page( 'publish' );
		$inactive = $this->inactive_stylesheet();
		update_option(
			'theme_mods_' . $inactive,
			[ Accessibility_Statement_Page::LEGACY_THEME_MOD => $page_id ]
		);

		Accessibility_Statement_Page::migrate_legacy_theme_mod();

		$this->assertSame( $page_id, (int) get_theme_mod( Accessibility_Statement_Page::LEGACY_THEME_MOD ) );

		delete_option( 'theme_mods_' . $inactive );
	}

	/**
	 * The wizard needs to tell a site that never had a page from one whose page
	 * was deleted, because the two need different copy.
	 */
	public function test_api_response_separates_never_created_from_deleted() {
		$never = Accessibility_Statement_Page::api_get_page();
		$this->assertSame( 'none', $never->get_data()['status'] );

		Accessibility_Statement_Page::create_page();
		wp_delete_post( get_option( Accessibility_Statement_Page::OPTION_NAME ), true );

		$deleted = Accessibility_Statement_Page::api_get_page();
		$this->assertSame( 'missing', $deleted->get_data()['status'] );
	}

	/**
	 * The admin list marks the page the site actually uses.
	 */
	public function test_post_state_marks_the_stored_page() {
		Accessibility_Statement_Page::create_page();
		$page_id = (int) get_option( Accessibility_Statement_Page::OPTION_NAME );
		$other   = $this->factory()->post->create( [ 'post_type' => 'page' ] );

		$this->assertArrayHasKey(
			'accessibility_statement',
			Accessibility_Statement_Page::post_status( [], get_post( $page_id ) )
		);
		$this->assertArrayNotHasKey(
			'accessibility_statement',
			Accessibility_Statement_Page::post_status( [], get_post( $other ) )
		);
	}
}
