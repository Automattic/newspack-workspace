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
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		/*
		 * The filters only run on a block theme, so the tests have to be on one
		 * too — otherwise they exercise the guard rather than the behaviour.
		 * The monorepo's themes sit beside the plugins directory; register it so
		 * newspack-block-theme is visible, and fall back to any block theme the
		 * environment already has.
		 */
		$monorepo_themes = dirname( NEWSPACK_ABSPATH, 2 ) . '/themes';
		if ( is_dir( $monorepo_themes ) ) {
			register_theme_directory( $monorepo_themes );
			wp_clean_themes_cache();
		}

		$block_theme = '';
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( $theme->is_block_theme() ) {
				$block_theme = $slug;
				if ( 'newspack-block-theme' === $slug ) {
					break;
				}
			}
		}

		if ( ! $block_theme ) {
			$this->fail( 'No block theme is available, so these filters cannot be exercised.' );
		}

		switch_theme( $block_theme );
		$this->assertTrue( wp_is_block_theme(), 'The tests must run against a block theme.' );

		$this->post_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		$this->give_thumbnail( $this->post_id );

		remove_theme_mod( 'featured_image_default' );
		remove_theme_mod( 'page_featured_image_default' );
	}

	/**
	 * Attach a featured image to a post.
	 *
	 * The position only means anything for a post that has an image to hide,
	 * so every fixture needs one.
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
	 * The per-post meta suppresses the image.
	 */
	public function test_hidden_meta_hides_the_image() {
		update_post_meta( $this->post_id, Featured_Image_Position::META_KEY, 'hidden' );
		$this->go_to_singular( $this->post_id );

		$this->assertTrue( Featured_Image_Position::is_hidden( $this->post_id ) );
		$this->assertSame( '', Featured_Image_Position::filter_featured_image_block( '<img />', [] ) );
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
	 * The wrapper goes when the image is hidden — whatever block it is.
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
				Featured_Image_Position::filter_image_wrapper( '<div>dropped</div>', $this->wrapper_block( $block_name ) ),
				sprintf( 'A %s wrapping the featured image should be dropped.', $block_name )
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
