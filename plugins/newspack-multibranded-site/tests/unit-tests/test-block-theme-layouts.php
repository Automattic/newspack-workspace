<?php
/**
 * Class TestBlockThemeLayouts
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Block_Theme\Block_Theme;
use Newspack_Multibranded_Site\Block_Theme\Editor_Notices;
use Newspack_Multibranded_Site\Block_Theme\Layouts;
use Newspack_Multibranded_Site\Meta\Block_Theme_Menus;
use Newspack_Multibranded_Site\Meta\Block_Theme_Parts;
use Newspack_Multibranded_Site\Taxonomy;

/**
 * Brand menus and template parts on block themes.
 */
class TestBlockThemeLayouts extends WP_UnitTestCase {

	/**
	 * A brand.
	 *
	 * @var WP_Term
	 */
	private $brand;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->brand = $this->factory->term->create_and_get(
			[
				'taxonomy' => Taxonomy::SLUG,
				'name'     => 'Brand A',
				'slug'     => 'brand-a',
			]
		);
	}

	/**
	 * Saves a template part for the active theme.
	 *
	 * @param string $slug    Slug.
	 * @param string $content Content.
	 * @param string $area    Area.
	 * @return int Post ID.
	 */
	private function make_part( $slug, $content = '<!-- wp:paragraph --><p>Part</p><!-- /wp:paragraph -->', $area = 'header' ) {
		$id = $this->factory->post->create(
			[
				'post_type'    => 'wp_template_part',
				'post_name'    => $slug,
				'post_title'   => $slug,
				'post_content' => $content,
				'post_status'  => 'publish',
			]
		);
		wp_set_post_terms( $id, get_stylesheet(), 'wp_theme' );
		wp_set_post_terms( $id, $area, 'wp_template_part_area' );
		return $id;
	}

	/**
	 * Saves a menu.
	 *
	 * @param string $title Title.
	 * @return int Post ID.
	 */
	private function make_nav( $title ) {
		return $this->factory->post->create(
			[
				'post_type'   => 'wp_navigation',
				'post_title'  => $title,
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * A template part block in a slot.
	 *
	 * @param string $slug Part slug.
	 * @param string $slot Slot class.
	 * @return array
	 */
	private function part_block( $slug, $slot ) {
		return [
			'blockName' => 'core/template-part',
			'attrs'     => [
				'slug'      => $slug,
				'className' => $slot,
			],
		];
	}

	/**
	 * A Navigation block.
	 *
	 * @param int $ref Menu ID.
	 * @return array
	 */
	private function nav_block( $ref ) {
		return [
			'blockName'   => 'core/navigation',
			'attrs'       => [ 'ref' => $ref ],
			'innerBlocks' => [],
		];
	}

	/**
	 * The slot comes from the block's class, so it survives Replace in the Site Editor.
	 */
	public function test_slot_of() {
		$this->assertSame( 'header-desktop', Block_Theme::slot_of( [ 'className' => 'foo header-desktop' ] ) );
		$this->assertSame( 'sidebar', Block_Theme::slot_of( [ 'className' => 'sidebar' ] ) );
		$this->assertSame( '', Block_Theme::slot_of( [ 'className' => 'post-footer' ] ) );
		$this->assertSame( '', Block_Theme::slot_of( [] ) );
	}

	/**
	 * A brand's chosen part replaces the main one in that slot, on the brand's pages only.
	 */
	public function test_part_swap() {
		$this->make_part( 'header-desktop-brand-a' );
		update_term_meta( $this->brand->term_id, Block_Theme_Parts::get_key(), [ 'header-desktop' => 'header-desktop-brand-a' ] );

		$this->go_to( home_url( '/' ) );
		$block = Layouts::filter_block_data( $this->part_block( 'header-desktop', 'header-desktop' ) );
		Layouts::leave_part( '', $block );
		$this->assertSame( 'header-desktop', $block['attrs']['slug'] );

		$this->go_to( get_term_link( $this->brand ) );
		$block = Layouts::filter_block_data( $this->part_block( 'header-desktop', 'header-desktop' ) );
		Layouts::leave_part( '', $block );
		$this->assertSame( 'header-desktop-brand-a', $block['attrs']['slug'] );

		// Other slots are untouched.
		$block = Layouts::filter_block_data( $this->part_block( 'footer-desktop', 'footer-desktop' ) );
		Layouts::leave_part( '', $block );
		$this->assertSame( 'footer-desktop', $block['attrs']['slug'] );
	}

	/**
	 * A chosen part that doesn't exist (e.g. after a theme switch) falls back to the main one.
	 */
	public function test_missing_part_falls_back() {
		update_term_meta( $this->brand->term_id, Block_Theme_Parts::get_key(), [ 'header-desktop' => 'not-a-part' ] );
		$this->go_to( get_term_link( $this->brand ) );
		$block = Layouts::filter_block_data( $this->part_block( 'header-desktop', 'header-desktop' ) );
		Layouts::leave_part( '', $block );
		$this->assertSame( 'header-desktop', $block['attrs']['slug'] );
	}

	/**
	 * Brand menu picks apply in main parts, but not inside a part the brand chose: that part
	 * holds the menu the brand wants.
	 */
	public function test_menu_swap_only_in_main_parts() {
		$main_nav  = $this->make_nav( 'Main' );
		$brand_nav = $this->make_nav( 'Brand' );
		$this->make_part( 'header-desktop-brand-a' );
		update_term_meta( $this->brand->term_id, Block_Theme_Menus::get_key(), [ $main_nav => $brand_nav ] );
		update_term_meta( $this->brand->term_id, Block_Theme_Parts::get_key(), [ 'header-desktop' => 'header-desktop-brand-a' ] );

		$this->go_to( get_term_link( $this->brand ) );

		// Inside the main footer: swapped.
		$footer = Layouts::filter_block_data( $this->part_block( 'footer-desktop', 'footer-desktop' ) );
		$nav    = Layouts::filter_block_data( $this->nav_block( $main_nav ) );
		Layouts::leave_part( '', $footer );
		$this->assertSame( $brand_nav, $nav['attrs']['ref'] );

		// Inside the brand's own header: left alone.
		$header = Layouts::filter_block_data( $this->part_block( 'header-desktop', 'header-desktop' ) );
		$nav    = Layouts::filter_block_data( $this->nav_block( $main_nav ) );
		Layouts::leave_part( '', $header );
		$this->assertSame( $main_nav, $nav['attrs']['ref'] );

		// After the header closes, swaps apply again.
		$nav = Layouts::filter_block_data( $this->nav_block( $main_nav ) );
		$this->assertSame( $brand_nav, $nav['attrs']['ref'] );
	}

	/**
	 * A picked menu that's been deleted or unpublished isn't swapped in.
	 */
	public function test_unpublished_menu_not_used() {
		$main_nav  = $this->make_nav( 'Main' );
		$brand_nav = $this->make_nav( 'Brand' );
		update_term_meta( $this->brand->term_id, Block_Theme_Menus::get_key(), [ $main_nav => $brand_nav ] );
		wp_update_post(
			[
				'ID'          => $brand_nav,
				'post_status' => 'draft',
			]
		);
		$this->go_to( get_term_link( $this->brand ) );
		$nav = Layouts::filter_block_data( $this->nav_block( $main_nav ) );
		$this->assertSame( $main_nav, $nav['attrs']['ref'] );
	}

	/**
	 * Only a menu can be swapped in for a menu, so a stale or edited choice can't render another post.
	 */
	public function test_non_menu_choice_not_used() {
		$main_nav = $this->make_nav( 'Main' );
		$post     = $this->factory->post->create( [ 'post_status' => 'publish' ] );
		update_term_meta( $this->brand->term_id, Block_Theme_Menus::get_key(), [ $main_nav => $post ] );
		$this->go_to( get_term_link( $this->brand ) );
		$nav = Layouts::filter_block_data( $this->nav_block( $main_nav ) );
		$this->assertSame( $main_nav, $nav['attrs']['ref'] );
	}

	/**
	 * Copies are regular parts named "… [Brand]"; a second copy reuses the earlier one when asked.
	 */
	public function test_copy_part() {
		$this->make_part( 'header-desktop', '<!-- wp:paragraph --><p>Main v1</p><!-- /wp:paragraph -->' );

		$copy = Layouts::copy_part( $this->brand, 'header-desktop' );
		$this->assertInstanceOf( WP_Block_Template::class, $copy );
		$this->assertSame( 'header-desktop-brand-a', $copy->slug );
		$this->assertSame( 'header-desktop [Brand A]', $copy->title );
		$this->assertSame( 'header', $copy->area );

		// A second plain copy gets a numbered slug.
		$this->assertSame( 'header-desktop-brand-a-2', Layouts::copy_part( $this->brand, 'header-desktop' )->slug );

		// Replace overwrites only the named copy, with the main part's current content.
		$main = get_page_by_path( 'header-desktop', OBJECT, 'wp_template_part' );
		wp_update_post(
			[
				'ID'           => $main->ID,
				'post_content' => '<!-- wp:paragraph --><p>Main v2</p><!-- /wp:paragraph -->',
			]
		);
		$replaced = Layouts::copy_part( $this->brand, 'header-desktop', 'header-desktop-brand-a-2' );
		$this->assertStringContainsString( 'Main v2', $replaced->content );
		$this->assertStringContainsString( 'Main v1', Block_Theme::get_part( 'header-desktop-brand-a' )->content );

		// The main part itself is never a copy to replace.
		$this->assertWPError( Layouts::copy_part( $this->brand, 'header-desktop', 'header-desktop' ) );
	}

	/**
	 * A copy is tagged with the brand and slot it was made for, and only that brand can replace it.
	 */
	public function test_copy_tagged_with_its_brand() {
		$this->make_part( 'header-desktop' );
		$other = $this->factory->term->create_and_get(
			[
				'taxonomy' => Taxonomy::SLUG,
				'name'     => 'Brand A 2',
				'slug'     => 'brand-a-2',
			]
		);

		// This copy's slug, header-desktop-brand-a-2, starts with Brand A's.
		$copy = Layouts::copy_part( $other, 'header-desktop' );
		$this->assertSame( $other->term_id . ':header-desktop', get_post_meta( $copy->wp_id, Layouts::COPY_META_KEY, true ) );
		$this->assertWPError( Layouts::copy_part( $this->brand, 'header-desktop', 'header-desktop-brand-a-2' ) );
	}

	/**
	 * A copy takes its slot's area, whatever the main part's saved area is.
	 */
	public function test_copy_part_uses_slot_area() {
		$this->make_part( 'sidebar-contents', '<!-- wp:paragraph --><p>Sidebar</p><!-- /wp:paragraph -->', 'uncategorized' );
		$this->assertSame( 'sidebar', Layouts::copy_part( $this->brand, 'sidebar' )->area );
	}

	/**
	 * Copied menus are back-dated so they never become Core's fallback menu.
	 */
	public function test_copy_nav_is_backdated() {
		$source = $this->make_nav( 'Main' );
		$copy   = Layouts::copy_nav( $this->brand, $source );
		$this->assertSame( 'Main [Brand A]', $copy->post_title );
		$this->assertSame( Layouts::BACKDATE, $copy->post_date );

		$empty = Layouts::copy_nav( $this->brand, $source, true );
		$this->assertSame( '', $empty->post_content );

		$this->assertWPError( Layouts::copy_nav( $this->brand, 0 ) );
	}

	/**
	 * The Header notice says which slots it still loads.
	 */
	public function test_wrapper_notice() {
		$part          = new WP_Block_Template();
		$part->content = '<!-- wp:template-part {"slug":"header-desktop","className":"header-desktop"} /--><!-- wp:template-part {"slug":"header-mobile","className":"header-mobile"} /-->';
		$this->assertSame( 'info', Editor_Notices::wrapper_notice( $part, 'header' )['status'] );

		$part->content = '<!-- wp:template-part {"slug":"header-mobile","className":"header-mobile"} /-->';
		$notice        = Editor_Notices::wrapper_notice( $part, 'header' );
		$this->assertSame( 'warning', $notice['status'] );
		$this->assertStringContainsString( 'put a desktop header part back', $notice['text'] );

		$part->content = '<!-- wp:paragraph --><p>Rebuilt</p><!-- /wp:paragraph -->';
		$this->assertStringContainsString( 'put those parts back', Editor_Notices::wrapper_notice( $part, 'footer' )['text'] );
	}

	/**
	 * Usage notices name the brands a shared item affects.
	 */
	public function test_usage_notice() {
		$shared = Editor_Notices::usage_notice(
			[
				'main'   => true,
				'brands' => [
					1 => 'A',
					2 => 'B',
				],
			],
			'Header'
		);
		$this->assertSame( 'warning', $shared['status'] );
		$this->assertStringContainsString( 'A and B brands', $shared['text'] );

		$only = Editor_Notices::usage_notice(
			[
				'main'   => false,
				'brands' => [ 1 => 'A' ],
			],
			'Header [A]'
		);
		$this->assertSame( 'info', $only['status'] );
		$this->assertStringContainsString( 'only affect A pages', $only['text'] );
	}
}
