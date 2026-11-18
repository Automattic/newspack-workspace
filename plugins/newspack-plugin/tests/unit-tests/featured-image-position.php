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
		 * These tests have to run on newspack-block-theme itself: the filters
		 * only run on a block theme at all, and one test reads the shipped
		 * `templates/single.html`. The test WordPress install has its own
		 * themes directory holding only core's themes, so the monorepo's
		 * themes directory has to be registered. It is mounted at a different
		 * path inside the container than it sits at on disk, so try both.
		 */
		$this->block_theme = 'newspack-block-theme';

		foreach ( [ '/newspack-themes', dirname( NEWSPACK_ABSPATH, 3 ) . '/themes' ] as $candidate ) {
			if ( is_dir( $candidate . '/' . $this->block_theme ) ) {
				register_theme_directory( $candidate );
				wp_clean_themes_cache();
				break;
			}
		}

		if ( ! wp_get_theme( $this->block_theme )->exists() ) {
			$this->fail( 'newspack-block-theme was not found; the monorepo themes directory is not visible to the tests.' );
		}

		switch_theme( $this->block_theme );
		$this->assertTrue( wp_is_block_theme(), 'The tests must run against a block theme.' );

		$this->post_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->give_thumbnail( $this->post_id );

		remove_theme_mod( 'featured_image_default' );
		remove_theme_mod( 'page_featured_image_default' );
	}

	/**
	 * Put the theme back, so a switch here cannot colour another test file.
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
	 * A parsed wrapper block containing a featured image, as the templates have.
	 *
	 * @param string $block_name Wrapper block name.
	 * @return array
	 */
	private function wrapper_block( $block_name = 'core/group' ) {
		return [
			'blockName'   => $block_name,
			'attrs'       => [ 'metadata' => [ 'name' => 'Post Image' ] ],
			'innerBlocks' => [
				[
					'blockName'   => 'core/group',
					'attrs'       => [],
					'innerBlocks' => [
						[
							'blockName'   => 'core/post-featured-image',
							'attrs'       => [],
							'innerBlocks' => [],
						],
						[
							'blockName'   => 'newspack/featured-image-caption',
							'attrs'       => [],
							'innerBlocks' => [],
						],
					],
				],
			],
		];
	}

	/**
	 * Find the first parsed block matching a test, at any depth.
	 *
	 * @param array    $blocks Parsed blocks.
	 * @param callable $test   Receives a parsed block, returns bool.
	 * @return array|null
	 */
	private function find_block( $blocks, $test ) {
		foreach ( $blocks as $block ) {
			if ( $test( $block ) ) {
				return $block;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = $this->find_block( $block['innerBlocks'], $test );
				if ( $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Call the class's private containment test.
	 *
	 * @param array $block Parsed block.
	 * @return bool
	 */
	private function contains_featured_image( $block ) {
		$method = new ReflectionMethod( Featured_Image_Position::class, 'contains_featured_image' );
		$method->setAccessible( true );
		return $method->invoke( null, $block );
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
	 * nothing to hide — otherwise the wrapper gets dropped from posts the
	 * feature has no business touching.
	 */
	public function test_post_without_a_thumbnail_is_never_hidden() {
		$bare_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		set_theme_mod( 'featured_image_default', 'hidden' );
		update_post_meta( $bare_id, Featured_Image_Position::META_KEY, 'hidden' );

		$this->go_to_singular( $bare_id );

		$this->assertFalse( Featured_Image_Position::is_hidden( $bare_id ) );
		$this->assertSame(
			'<div>kept</div>',
			Featured_Image_Position::filter_image_wrapper( '<div>kept</div>', $this->wrapper_block() ),
			'A post with no featured image keeps its wrapper.'
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
	 * Nothing is suppressed away from a singular view.
	 */
	public function test_archives_are_untouched() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( Featured_Image_Position::is_hidden( $this->post_id ) );
	}

	/**
	 * An emptied wrapper goes — whatever block it is.
	 *
	 * Six of the block theme's single templates wrap the image in a group and
	 * one uses a column, so the filter must not care which.
	 */
	public function test_wrapper_dropped_for_group_and_column() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		foreach ( [ 'core/group', 'core/column' ] as $block_name ) {
			$this->assertSame(
				'',
				Featured_Image_Position::filter_image_wrapper(
					'<div class="wp-block-group"><div class="wp-block-group"></div></div>',
					$this->wrapper_block( $block_name )
				),
				sprintf( 'A %s left holding nothing should be dropped.', $block_name )
			);
		}
	}

	/**
	 * A block that still renders something is never dropped.
	 *
	 * Every ancestor of the image contains the image, the article wrapper
	 * included. What separates the image's own container from the rest of the
	 * page is that it has nothing left in it.
	 */
	public function test_block_still_rendering_content_is_kept() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$cases = [
			'text'           => '<main class="wp-block-group"><h1>Headline</h1><p>The article.</p></main>',
			'an image'       => '<div class="wp-block-group"><img src="https://example.com/other.jpg" alt="" /></div>',
			'an embed'       => '<div class="wp-block-group"><iframe src="https://example.com/"></iframe></div>',
			'a background'   => '<div class="wp-block-group" style="background-image:url(x.jpg)"></div>',
			'a form control' => '<div class="wp-block-group"><input type="email" /></div>',
		];

		foreach ( $cases as $label => $content ) {
			$this->assertSame(
				$content,
				Featured_Image_Position::filter_image_wrapper( $content, $this->wrapper_block() ),
				sprintf( 'A wrapper still rendering %s must be kept.', $label )
			);
		}
	}

	/**
	 * A publisher's own block is never dropped, however it is named.
	 *
	 * The wrapper is identified by containing a featured image, not by its
	 * label — "Post Image" is an editable name that can appear on a group in
	 * post content.
	 */
	public function test_unrelated_blocks_are_left_alone() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$impostor = [
			'blockName'   => 'core/group',
			'attrs'       => [ 'metadata' => [ 'name' => 'Post Image' ] ],
			'innerBlocks' => [
				[
					'blockName'   => 'core/paragraph',
					'attrs'       => [],
					'innerBlocks' => [],
				],
			],
		];

		$this->assertSame(
			'<div>a publisher wrote this</div>',
			Featured_Image_Position::filter_image_wrapper( '<div>a publisher wrote this</div>', $impostor ),
			'A group named "Post Image" that holds no featured image is a publisher\'s own content.'
		);
	}

	/**
	 * The real template, parsed: the article survives, the image's box does not.
	 *
	 * `templates/single.html` wraps the whole article in a group with
	 * `tagName: main`, which therefore contains the featured image just as the
	 * image's own container does. Containment alone cannot tell them apart, and
	 * a filter that tried would delete the article. Hand-built fixtures never
	 * caught that, so this test reads the shipped template.
	 */
	public function test_real_single_template_keeps_the_article() {
		$template = get_theme_file_path( 'templates/single.html' );
		if ( ! file_exists( $template ) ) {
			$this->fail( 'templates/single.html was not found in the block theme.' );
		}

		$blocks = parse_blocks( file_get_contents( $template ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local template file in the active theme, read once in a test.

		$main = $this->find_block(
			$blocks,
			function ( $block ) {
				return 'core/group' === $block['blockName'] && 'main' === ( $block['attrs']['tagName'] ?? '' );
			}
		);
		$this->assertNotNull( $main, 'The template should wrap the article in a group with tagName "main".' );

		$image_box = $this->find_block(
			$blocks,
			function ( $block ) {
				return 'Post Image' === ( $block['attrs']['metadata']['name'] ?? '' );
			}
		);
		$this->assertNotNull( $image_box, 'The template should carry a block holding the featured image.' );

		// Both contain the image, which is why containment alone is not enough.
		$this->assertTrue( $this->contains_featured_image( $main ), 'The article wrapper contains the featured image.' );
		$this->assertTrue( $this->contains_featured_image( $image_box ), 'The image box contains the featured image.' );

		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$article = '<main class="wp-block-group"><h1>Headline</h1><p>The article body.</p></main>';
		$this->assertSame(
			$article,
			Featured_Image_Position::filter_image_wrapper( $article, $main ),
			'The article wrapper must survive on a post whose image is hidden.'
		);

		$this->assertSame(
			'',
			Featured_Image_Position::filter_image_wrapper( '<div class="wp-block-group alignfull post-image"><div class="wp-block-group"></div></div>', $image_box ),
			'The image box, left holding nothing, is dropped.'
		);
	}

	/**
	 * End to end through the filters: the image goes, the article stays.
	 *
	 * `WP_Block::render()` renders inner blocks before the block itself, which
	 * is what makes the emptiness test safe — by the time an ancestor is
	 * filtered, the image beneath it has already gone. This renders the real
	 * stack rather than asserting that it behaves that way.
	 */
	public function test_render_drops_the_image_box_and_keeps_the_article() {
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
		$this->assertStringContainsString( 'post-image', $visible, 'The image box renders while the image is visible.' );

		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );

		$hidden = ( new WP_Block( $parsed[0], $context ) )->render();
		$this->assertStringNotContainsString( '<img', $hidden, 'A hidden featured image renders nothing.' );
		$this->assertStringContainsString( 'The article body.', $hidden, 'The article survives.' );
		$this->assertStringContainsString( '<main', $hidden, 'The article wrapper survives.' );
		$this->assertStringNotContainsString( 'post-image', $hidden, 'The emptied image box is gone.' );
	}

	/**
	 * Every filter the feature needs is actually registered.
	 *
	 * The behaviour tests call the callbacks directly, so they would all still
	 * pass if a registration were dropped — and a dropped caption filter is
	 * exactly the kind of regression that leaves a caption floating under no
	 * image.
	 */
	public function test_filters_are_registered() {
		$expected = [
			'render_block_core/post-featured-image'        => 'suppress_when_hidden',
			'render_block_newspack/featured-image-caption' => 'suppress_when_hidden',
			'render_block_core/group'                      => 'filter_image_wrapper',
			'render_block_core/column'                     => 'filter_image_wrapper',
		];

		foreach ( $expected as $hook => $method ) {
			$this->assertNotFalse(
				has_filter( $hook, [ Featured_Image_Position::class, $method ] ),
				sprintf( '%s should be filtered by %s().', $hook, $method )
			);
		}

		$this->assertNotFalse(
			has_action( 'after_switch_theme', [ Featured_Image_Position::class, 'migrate_position_defaults' ] ),
			'The theme-mod migration should run on a theme switch.'
		);
	}

	/**
	 * The site-wide defaults survive a theme switch.
	 *
	 * Theme mods are stored per theme, so without this the default is lost on
	 * the switch to the block theme — silently, and for every post.
	 */
	public function test_defaults_migrate_across_a_theme_switch() {
		// A theme other than the active one, which is what a real switch means.
		$old_theme = wp_get_theme( 'newspack-theme' );
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
