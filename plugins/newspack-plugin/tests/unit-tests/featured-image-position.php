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
	 * Nothing is suppressed away from a singular view.
	 */
	public function test_archives_are_untouched() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to( home_url( '/' ) );

		$this->assertFalse( Featured_Image_Position::is_hidden( $this->post_id ) );
	}

	/**
	 * The image's container goes — whatever block it is.
	 *
	 * Five of the block theme's single templates wrap the image in a group and
	 * one uses a column, so the filter must not care which. Nor does it care
	 * what the container rendered: the decision is structural.
	 */
	public function test_image_box_dropped_for_group_and_column() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		foreach ( [ 'core/group', 'core/column' ] as $block_name ) {
			foreach ( [ '', '<div class="wp-block-group"></div>', '<div><img src="x.jpg" /></div>' ] as $rendered ) {
				$this->assertSame(
					'',
					Featured_Image_Position::filter_image_wrapper( $rendered, $this->wrapper_block( $block_name ) ),
					sprintf( 'A %s holding only the featured image is dropped.', $block_name )
				);
			}
		}
	}

	/**
	 * A container holding anything besides the image is never dropped.
	 *
	 * This is the case a rendered-emptiness test gets wrong. An ad slot renders
	 * a bare `<div id="div-gpt-ad-…">` and an embed loader renders only a
	 * script: no text, nothing that looks like content, yet dropping the
	 * container would take them with it. Someone moving the above-content ad
	 * slot into the image's group in the Site Editor is an ordinary edit.
	 */
	public function test_container_holding_anything_else_is_kept() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$neighbours = [
			'an ad slot'      => 'newspack-ads/ad-slot',
			'the content'     => 'core/post-content',
			'a paragraph'     => 'core/paragraph',
			'a cover'         => 'core/cover',
			'a pattern'       => 'core/pattern',
			'a synced block'  => 'core/block',
			'a template part' => 'core/template-part',
		];

		foreach ( $neighbours as $label => $block_name ) {
			$box                  = $this->wrapper_block();
			$box['innerBlocks'][] = [
				'blockName'   => $block_name,
				'attrs'       => [],
				'innerBlocks' => [],
			];

			$this->assertSame(
				'<div>kept</div>',
				Featured_Image_Position::filter_image_wrapper( '<div>kept</div>', $box ),
				sprintf( 'A container that also holds %s must be kept.', $label )
			);
		}
	}

	/**
	 * Markup between the blocks counts as content, unless it is whitespace.
	 *
	 * `parse_blocks()` gives the template's own indentation a nameless node, so
	 * blank ones have to be ignored — but a nameless node carrying actual
	 * markup is something a publisher put there.
	 */
	public function test_nameless_nodes_are_whitespace_only() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$indented                  = $this->wrapper_block();
		$indented['innerBlocks'][] = [
			'blockName'   => null,
			'innerHTML'   => "\n\t\t",
			'innerBlocks' => [],
		];
		$this->assertSame(
			'',
			Featured_Image_Position::filter_image_wrapper( '<div></div>', $indented ),
			'Indentation between blocks does not keep the container.'
		);

		$with_markup                  = $this->wrapper_block();
		$with_markup['innerBlocks'][] = [
			'blockName'   => null,
			'innerHTML'   => '<img src="https://example.com/pixel.gif" alt="" />',
			'innerBlocks' => [],
		];
		$this->assertSame(
			'<div>kept</div>',
			Featured_Image_Position::filter_image_wrapper( '<div>kept</div>', $with_markup ),
			'Raw markup between the blocks keeps the container.'
		);
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
		/*
		 * Read the tracked template straight off disk. It is checked in, unlike
		 * the theme's generated `style.css`, so this works in a fresh checkout
		 * whether or not newspack-block-theme is installable. Two candidate
		 * roots: the monorepo layout, and the path the dev container mounts.
		 */
		$template = '';
		foreach ( [ dirname( NEWSPACK_ABSPATH, 2 ) . '/themes', '/newspack-themes' ] as $root ) {
			$candidate = $root . '/newspack-block-theme/templates/single.html';
			if ( file_exists( $candidate ) ) {
				$template = $candidate;
				break;
			}
		}

		if ( ! $template ) {
			$this->fail( 'newspack-block-theme/templates/single.html was not found next to the plugin.' );
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

		/*
		 * Both of them contain the featured image somewhere beneath them, which
		 * is exactly why "contains the image" cannot be the test on its own.
		 */
		$finder = function ( $block ) {
			return 'core/post-featured-image' === $block['blockName'];
		};
		$this->assertNotNull( $this->find_block( $main['innerBlocks'], $finder ), 'The article wrapper contains the featured image.' );
		$this->assertNotNull( $this->find_block( $image_box['innerBlocks'], $finder ), 'The image box contains the featured image.' );

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
			'The image box is dropped.'
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
