<?php
/**
 * Test_Featured_Image_Position class.
 *
 * @package Newspack
 */

use Newspack\Featured_Image_Position;

/**
 * Class Test_Featured_Image_Position
 *
 * @group featured-image-position
 */
class Test_Featured_Image_Position extends WP_UnitTestCase {
	/**
	 * A published post carrying a featured image.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * The block theme these tests run against.
	 *
	 * @var string
	 */
	private $block_theme;

	/**
	 * The theme active before the tests switched away from it.
	 *
	 * @var string
	 */
	private $original_theme;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_theme = get_stylesheet();

		/*
		 * The filters only run on a block theme, so the tests have to be on one
		 * too — otherwise they exercise the guard rather than the behavior.
		 * Any block theme does: none of this reads the active theme's files.
		 *
		 * Deliberately not newspack-block-theme. Its `style.css` is generated
		 * by the webpack build and not tracked, so `WP_Theme::exists()` is
		 * false in a fresh checkout and CI never builds it — requiring it here
		 * would fail every test in this file on the PR while passing locally,
		 * where the container mounts an already-built copy. The one test that
		 * needs our template reads the tracked file from disk instead.
		 */
		$this->block_theme = '';
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( $theme->is_block_theme() ) {
				$this->block_theme = $slug;
				break;
			}
		}

		if ( ! $this->block_theme ) {
			$this->fail( 'No block theme is available, so these filters cannot be exercised.' );
		}

		switch_theme( $this->block_theme );
		$this->assertTrue( wp_is_block_theme(), 'The tests must run against a block theme.' );

		$this->post_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->give_thumbnail( $this->post_id );

		remove_theme_mod( 'featured_image_default' );
		remove_theme_mod( 'page_featured_image_default' );
	}

	/**
	 * Put the theme back, so a switch here cannot color another test file.
	 */
	public function tear_down() {
		if ( $this->original_theme && get_stylesheet() !== $this->original_theme ) {
			switch_theme( $this->original_theme );
		}

		parent::tear_down();
	}

	/**
	 * Attach a featured image to a post.
	 *
	 * The position only means anything for a post that has an image to hide,
	 * so every fixture needs one. The metadata is what lets core resolve a real
	 * `<img>`, which the render tests depend on.
	 *
	 * @param int $post_id Post to give a thumbnail.
	 * @return int Attachment ID.
	 */
	private function give_thumbnail( $post_id ) {
		$attachment_id = $this->factory()->attachment->create_object(
			[
				'file'           => 'image.jpg',
				'post_parent'    => $post_id,
				'post_mime_type' => 'image/jpeg',
			]
		);
		wp_update_attachment_metadata(
			$attachment_id,
			[
				'file'   => 'image.jpg',
				'width'  => 1200,
				'height' => 800,
				'sizes'  => [],
			]
		);
		set_post_thumbnail( $post_id, $attachment_id );
		return $attachment_id;
	}

	/**
	 * Put the main query on a given singular post.
	 *
	 * @param int $post_id Post to query.
	 */
	private function go_to_singular( $post_id ) {
		$this->go_to( get_permalink( $post_id ) );
		setup_postdata( get_post( $post_id ) );
	}

	/**
	 * How many arguments a hook passes to one of this class's callbacks.
	 *
	 * @param string $hook   Hook name.
	 * @param string $method Method on this class.
	 * @return int|null
	 */
	private function accepted_args( $hook, $method ) {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return null;
		}

		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( [ Featured_Image_Position::class, $method ] === $callback['function'] ) {
					return (int) $callback['accepted_args'];
				}
			}
		}

		return null;
	}

	/**
	 * The per-post meta suppresses the image.
	 */
	public function test_hidden_meta_hides_the_image() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$this->assertTrue( Featured_Image_Position::is_hidden( $this->post_id ) );
		$this->assertSame( '', Featured_Image_Position::suppress_when_hidden( '<img />', [] ) );
	}

	/**
	 * The caption and photo credit go with the image.
	 *
	 * They render from the attachment, not from the image block, so removing
	 * the image alone leaves them captioning nothing.
	 */
	public function test_caption_is_suppressed_with_the_image() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$caption = '<figcaption>A caption<cite>Photo credit</cite></figcaption>';

		$this->assertSame(
			'',
			Featured_Image_Position::suppress_when_hidden( $caption, [ 'blockName' => 'newspack/featured-image-caption' ] ),
			'The caption block is suppressed alongside the image.'
		);

		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'large' );
		$this->assertSame(
			$caption,
			Featured_Image_Position::suppress_when_hidden( $caption, [ 'blockName' => 'newspack/featured-image-caption' ] ),
			'A visible image keeps its caption.'
		);
	}

	/**
	 * Any other position leaves the image alone.
	 */
	public function test_other_positions_leave_the_image() {
		foreach ( [ 'large', 'small', 'beside', 'behind', 'above' ] as $position ) {
			update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, $position );
			$this->go_to_singular( $this->post_id );

			$this->assertFalse(
				Featured_Image_Position::is_hidden( $this->post_id ),
				sprintf( 'Position "%s" should not hide the image.', $position )
			);
		}
	}

	/**
	 * With no per-post meta, the site default decides.
	 */
	public function test_site_default_applies_when_no_meta() {
		$this->go_to_singular( $this->post_id );
		$this->assertFalse( Featured_Image_Position::is_hidden( $this->post_id ), 'Default is not hidden.' );

		set_theme_mod( 'featured_image_default', 'hidden' );
		$this->assertTrue( Featured_Image_Position::is_hidden( $this->post_id ), 'Site default of hidden applies.' );
	}

	/**
	 * A post with no featured image has no position, whatever the default says.
	 *
	 * Classic returns an empty position before it reads the default, so a
	 * site-wide `hidden` must not make `is_hidden()` true for a post that has
	 * nothing to hide.
	 */
	public function test_post_without_a_thumbnail_is_never_hidden() {
		$bare_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		set_theme_mod( 'featured_image_default', 'hidden' );
		update_post_meta( $bare_id, Featured_Image_Position::META_KEY, 'hidden' );

		$this->go_to_singular( $bare_id );

		$this->assertFalse( Featured_Image_Position::is_hidden( $bare_id ) );
		$this->assertSame(
			'<img />',
			Featured_Image_Position::suppress_when_hidden( '<img />', [] ),
			'A post with no featured image keeps whatever it renders.'
		);
	}

	/**
	 * Pages read their own default, not the post one.
	 */
	public function test_pages_use_the_page_default() {
		$page_id = $this->factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);
		$this->give_thumbnail( $page_id );

		set_theme_mod( 'featured_image_default', 'hidden' );
		set_theme_mod( 'page_featured_image_default', 'large' );

		$this->go_to_singular( $page_id );

		$this->assertFalse(
			Featured_Image_Position::is_hidden( $page_id ),
			'A page should follow page_featured_image_default, not the post default.'
		);
	}

	/**
	 * A hidden post keeps its thumbnail inside someone else's loop.
	 *
	 * Nothing reads block context to get this right: `core/group` and
	 * `core/column` declare no `usesContext`, so their `WP_Block::$context` is
	 * always empty. It holds because `render_block_core_post_template()` calls
	 * `$query->the_post()` for each item, so `get_the_ID()` is the loop's post
	 * and the queried-object comparison in `is_hidden()` bails.
	 */
	public function test_other_posts_in_a_loop_keep_their_image() {
		$other_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->give_thumbnail( $other_id );
		update_post_meta( $other_id, Featured_Image_Position::META_KEY, 'hidden' );

		$this->go_to_singular( $this->post_id );

		$this->assertFalse(
			Featured_Image_Position::is_hidden( $other_id ),
			'A hidden post rendered inside another post\'s loop keeps its image.'
		);
	}

	/**
	 * A real query loop on a hidden post's page keeps the other posts' images.
	 *
	 * The sibling test checks `is_hidden()` directly, which only re-tests the
	 * queried-object comparison. This renders `core/query` and
	 * `core/post-template` for real, so it exercises the thing that actually
	 * makes it work: `render_block_core_post_template()` calls `the_post()` per
	 * item, so `get_the_ID()` is the loop's post and the comparison bails.
	 */
	public function test_a_rendered_query_loop_keeps_other_posts_images() {
		$other_id = $this->factory()->post->create(
			[
				'post_status' => 'publish',
				'post_title'  => 'A neighbour with a picture',
			]
		);
		$this->give_thumbnail( $other_id );

		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$parsed = parse_blocks(
			'<!-- wp:query {"queryId":42,"query":{"postType":"post","perPage":10,"offset":0}} --><div class="wp-block-query">'
			. '<!-- wp:post-template --><!-- wp:post-featured-image /--><!-- /wp:post-template -->'
			. '</div><!-- /wp:query -->'
		);

		$rendered = ( new WP_Block( $parsed[0] ) )->render();

		$this->assertStringContainsString(
			'wp-block-post-featured-image',
			$rendered,
			'A loop on a hidden post\'s page still renders other posts\' featured images.'
		);

		/*
		 * And the hidden post's own card in that loop is still suppressed, which
		 * is what `is_hidden()` documents. Asserting only that some image
		 * survives would also pass if suppression had stopped working entirely.
		 *
		 * Two posts in this transaction carry a thumbnail — the queried one and
		 * its neighbour — and the queried one is hidden, so exactly one image
		 * survives. A post without a thumbnail renders nothing either way, so
		 * any the suite installed do not affect the count.
		 */
		$this->assertSame(
			1,
			substr_count( $rendered, 'wp-block-post-featured-image' ),
			'The neighbour keeps its image and the hidden post does not.'
		);
	}

	/**
	 * Nothing is suppressed away from a singular view.
	 */
	public function test_archives_are_untouched() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( Featured_Image_Position::is_hidden( $this->post_id ) );
	}

	/**
	 * End to end through the filters: the image goes, the article stays.
	 *
	 * The container is deliberately still there afterwards. The theme collapses
	 * it in CSS once it holds no `.wp-block-post-featured-image`, which is the
	 * same rule that already handles a post with no thumbnail, so this asserts
	 * the state that rule expects rather than removing the container twice.
	 */
	public function test_render_removes_the_image_and_keeps_everything_else() {
		$this->go_to_singular( $this->post_id );

		$parsed = parse_blocks(
			'<!-- wp:group {"tagName":"main","metadata":{"name":"Post"}} --><main class="wp-block-group">'
			. '<!-- wp:group {"metadata":{"name":"Post Image"},"className":"post-image"} --><div class="wp-block-group post-image">'
			. '<!-- wp:post-featured-image /-->'
			. '</div><!-- /wp:group -->'
			. '<!-- wp:paragraph --><p>The article body.</p><!-- /wp:paragraph -->'
			. '</main><!-- /wp:group -->'
		);

		$context = [
			'postId'   => $this->post_id,
			'postType' => 'post',
		];

		$visible = ( new WP_Block( $parsed[0], $context ) )->render();
		$this->assertStringContainsString( '<img', $visible, 'A visible featured image renders an image.' );
		$this->assertStringContainsString( 'The article body.', $visible );

		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );

		$hidden = ( new WP_Block( $parsed[0], $context ) )->render();
		$this->assertStringNotContainsString( '<img', $hidden, 'A hidden featured image renders nothing.' );
		$this->assertStringNotContainsString(
			'wp-block-post-featured-image',
			$hidden,
			"Nothing with the image's class may remain, or the theme's CSS will not collapse the container."
		);
		$this->assertStringContainsString( 'The article body.', $hidden, 'The article survives.' );
		$this->assertStringContainsString( '<main', $hidden, 'The article wrapper survives.' );
		$this->assertStringContainsString( 'post-image', $hidden, 'The container stays; the theme hides it.' );
	}

	/**
	 * A post type the classic theme exempts is never hidden.
	 *
	 * Classic's `large`-to-`small` downgrade is an `||` whose second branch
	 * overwrites any position, `hidden` included, for a post type outside
	 * `newspack_theme_featured_image_post_types`. So on classic a site-wide
	 * `hidden` still shows a small image on such a post type, and stripping it
	 * here would be a regression rather than parity.
	 */
	public function test_unsupported_post_types_are_never_hidden() {
		register_post_type(
			'fip_widget',
			[
				'public'   => true,
				'supports' => [ 'title', 'editor', 'thumbnail' ],
			]
		);

		$widget_id = $this->factory()->post->create(
			[
				'post_type'   => 'fip_widget',
				'post_status' => 'publish',
			]
		);
		$this->give_thumbnail( $widget_id );
		update_post_meta( $widget_id, Featured_Image_Position::META_KEY, 'hidden' );

		$this->go_to_singular( $widget_id );

		$this->assertFalse(
			Featured_Image_Position::is_hidden( $widget_id ),
			'A post type outside the supported list keeps its image, as it does on classic.'
		);

		// Until a plugin adds it, exactly as newspack-newsletters does.
		$add = function ( $types ) {
			$types[] = 'fip_widget';
			return $types;
		};
		add_filter( 'newspack_theme_featured_image_post_types', $add );

		$this->assertTrue(
			Featured_Image_Position::is_hidden( $widget_id ),
			'A post type added through the theme filter does honor the position.'
		);

		remove_filter( 'newspack_theme_featured_image_post_types', $add );
		unregister_post_type( 'fip_widget' );
	}

	/**
	 * Every filter the feature needs is actually registered.
	 *
	 * The behavior tests call the callbacks directly, so they would all still
	 * pass if a registration were dropped — and a dropped caption filter is
	 * exactly the kind of regression that leaves a photo credit in the markup
	 * for an image nobody can see.
	 */
	public function test_filters_are_registered() {
		$expected = [
			'render_block_core/post-featured-image'        => 'suppress_when_hidden',
			'render_block_newspack/featured-image-caption' => 'suppress_when_hidden',
		];

		foreach ( $expected as $hook => $method ) {
			$this->assertNotFalse(
				has_filter( $hook, [ Featured_Image_Position::class, $method ] ),
				sprintf( '%s should be filtered by %s().', $hook, $method )
			);

			/*
			 * Three arguments, or the block instance never arrives, `$post_id`
			 * is null, and the callback silently falls back to the page's post
			 * instead of the block's own.
			 */
			$this->assertSame(
				3,
				$this->accepted_args( $hook, $method ),
				sprintf( '%s needs the block instance, so three arguments.', $hook )
			);
		}

		$this->assertNotFalse(
			has_action( 'after_switch_theme', [ Featured_Image_Position::class, 'migrate_position_defaults' ] ),
			'The theme-mod migration should run on a theme switch.'
		);
	}

	/**
	 * On a classic theme the filters pass everything through untouched.
	 *
	 * The classic theme handles the position itself, and post content there can
	 * perfectly well hold a featured image block. Without this guard a hidden
	 * position would blank it twice over.
	 */
	public function test_classic_themes_are_untouched() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );

		switch_theme( 'default' );
		if ( wp_is_block_theme() ) {
			$this->fail( 'The fallback theme is a block theme, so this guard cannot be exercised.' );
		}

		$this->go_to_singular( $this->post_id );

		$this->assertTrue(
			Featured_Image_Position::is_hidden( $this->post_id ),
			'The position still resolves; it is the filters that stand down.'
		);
		$this->assertSame(
			'<figure><img /></figure>',
			Featured_Image_Position::suppress_when_hidden( '<figure><img /></figure>', [] ),
			'A classic theme keeps its own markup.'
		);
	}

	/**
	 * A real theme switch runs the migration.
	 *
	 * The other two migration tests call the callback directly, so they would
	 * pass even if the hook name, priority or argument count were wrong. This
	 * one goes through `switch_theme()` and the `check_theme_switched()` that
	 * fires `after_switch_theme` on the next load.
	 */
	public function test_a_real_theme_switch_carries_the_defaults() {
		switch_theme( 'default' );
		set_theme_mod( 'featured_image_default', 'hidden' );
		$classic = get_stylesheet();

		switch_theme( $this->block_theme );
		$this->assertSame( $classic, get_option( 'theme_switched' ), 'The switch should be pending.' );

		remove_theme_mod( 'featured_image_default' );
		check_theme_switched();

		$this->assertSame(
			'hidden',
			get_theme_mod( 'featured_image_default' ),
			'The default should survive a switch driven through WordPress itself.'
		);
	}

	/**
	 * The migration survives a previous theme whose files are gone.
	 *
	 * `check_theme_switched()` still passes a `WP_Theme` for a missing theme,
	 * but one whose `exists()` is false. Its stored mods are still readable and
	 * still worth carrying.
	 */
	public function test_migration_handles_a_missing_previous_theme() {
		$ghost = wp_get_theme( 'a-theme-that-was-deleted' );
		$this->assertFalse( $ghost->exists(), 'The fixture must be a theme that is not installed.' );

		update_option(
			'theme_mods_a-theme-that-was-deleted',
			[ 'featured_image_default' => 'hidden' ]
		);
		remove_theme_mod( 'featured_image_default' );

		Featured_Image_Position::migrate_position_defaults( 'a-theme-that-was-deleted', $ghost );

		$this->assertSame( 'hidden', get_theme_mod( 'featured_image_default' ) );
	}

	/**
	 * Nothing fatals if WordPress ever passes the old name alone.
	 */
	public function test_migration_tolerates_a_single_argument() {
		Featured_Image_Position::migrate_position_defaults( 'some-theme' );
		$this->assertTrue( true, 'Called with one argument without fataling.' );
	}

	/**
	 * Only a position the themes understand survives sanitizing.
	 */
	public function test_unknown_positions_are_rejected() {
		$this->assertSame( 'hidden', Featured_Image_Position::sanitize_position( 'hidden' ) );
		$this->assertSame( 'beside', Featured_Image_Position::sanitize_position( 'beside' ) );
		$this->assertSame( '', Featured_Image_Position::sanitize_position( '' ) );
		$this->assertSame( '', Featured_Image_Position::sanitize_position( 'HIDDEN' ) );
		$this->assertSame( '', Featured_Image_Position::sanitize_position( 'anything-else' ) );
		$this->assertSame( '', Featured_Image_Position::sanitize_position( [ 'hidden' ] ) );
		$this->assertSame( '', Featured_Image_Position::sanitize_position( null ) );
	}

	/**
	 * The migration guard survives a hardcoded theme mod.
	 *
	 * This is the whole reason the guard reads the stored option instead of
	 * `get_theme_mod()`: a theme or plugin filtering `theme_mod_*` would make
	 * `get_theme_mod()` report a value as set, the copy would be skipped, and
	 * the publisher's default would be lost on the switch.
	 */
	public function test_migration_survives_a_filtered_theme_mod() {
		$old_theme = wp_get_theme( 'newspack-theme' );
		update_option(
			'theme_mods_' . $old_theme->get_stylesheet(),
			[ 'featured_image_default' => 'hidden' ]
		);
		remove_theme_mod( 'featured_image_default' );

		$hardcoded = function () {
			return 'large';
		};
		add_filter( 'theme_mod_featured_image_default', $hardcoded );

		Featured_Image_Position::migrate_position_defaults( 'Newspack', $old_theme );

		remove_filter( 'theme_mod_featured_image_default', $hardcoded );

		$this->assertSame(
			'hidden',
			get_theme_mod( 'featured_image_default' ),
			'The copy must happen even while a filter hardcodes the value.'
		);
	}

	/**
	 * Junk already in the new theme's row does not block the migration.
	 *
	 * "Never overwrite" is about not clobbering a publisher's choice. A value
	 * the themes cannot recognize is not one, and treating it as though it were
	 * would keep the real setting out for good.
	 */
	public function test_migration_replaces_an_unrecognized_new_value() {
		$old_theme = wp_get_theme( 'newspack-theme' );
		update_option(
			'theme_mods_' . $old_theme->get_stylesheet(),
			[ 'featured_image_default' => 'hidden' ]
		);
		set_theme_mod( 'featured_image_default', 'show-it-please' );

		Featured_Image_Position::migrate_position_defaults( 'Newspack', $old_theme );

		$this->assertSame( 'hidden', get_theme_mod( 'featured_image_default' ) );
	}

	/**
	 * The migration refuses to carry a value the themes would not recognize.
	 */
	public function test_migration_skips_an_unknown_old_value() {
		$old_theme = wp_get_theme( 'newspack-theme' );
		update_option(
			'theme_mods_' . $old_theme->get_stylesheet(),
			[ 'featured_image_default' => 'show-it-please' ]
		);
		remove_theme_mod( 'featured_image_default' );

		Featured_Image_Position::migrate_position_defaults( 'Newspack', $old_theme );

		$this->assertFalse(
			get_theme_mod( 'featured_image_default', false ),
			'Junk in the old row should not be carried forward.'
		);
	}

	/**
	 * The site-wide defaults survive a theme switch.
	 *
	 * Theme mods are stored per theme, so without this the default is lost on
	 * the switch to the block theme — silently, and for every post.
	 */
	public function test_defaults_migrate_across_a_theme_switch() {
		/*
		 * A theme other than the active one, which is what a real switch means.
		 * It does not have to be installed: the migration only ever reads the
		 * object's stylesheet to build the option name, and the monorepo nests
		 * the classic theme a level deeper than a theme root anyway.
		 */
		$old_theme = wp_get_theme( 'newspack-theme' );
		$this->assertSame( 'newspack-theme', $old_theme->get_stylesheet() );
		update_option(
			'theme_mods_' . $old_theme->get_stylesheet(),
			[
				'featured_image_default'      => 'hidden',
				'page_featured_image_default' => 'large',
			]
		);

		Featured_Image_Position::migrate_position_defaults( 'Newspack', $old_theme );

		$this->assertSame( 'hidden', get_theme_mod( 'featured_image_default' ) );
		$this->assertSame( 'large', get_theme_mod( 'page_featured_image_default' ) );
	}

	/**
	 * The migration never overwrites a value the new theme already has.
	 */
	public function test_migration_does_not_overwrite_existing_values() {
		$old_theme = wp_get_theme( 'newspack-theme' );
		update_option(
			'theme_mods_' . $old_theme->get_stylesheet(),
			[ 'featured_image_default' => 'hidden' ]
		);

		set_theme_mod( 'featured_image_default', 'large' );

		Featured_Image_Position::migrate_position_defaults( 'Newspack', $old_theme );

		$this->assertSame( 'large', get_theme_mod( 'featured_image_default' ) );
	}
}
