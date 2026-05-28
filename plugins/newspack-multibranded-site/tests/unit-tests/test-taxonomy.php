<?php
/**
 * Class TestTaxonomy
 *
 * @package Newspack_Multibranded_Site
 */

use Newspack_Multibranded_Site\Taxonomy;
use Newspack_Multibranded_Site\Meta\Show_Page_On_Front;

/**
 * Sample test case.
 */
class TestTaxonomy extends WP_UnitTestCase {

	/**
	 * A simple test to see if the taxonomy was registered.
	 */
	public function test_taxonomy_exists() {
		$this->assertTrue( taxonomy_exists( Taxonomy::SLUG ), 'The taxonomy is not registered' );
	}

	/**
	 * Test get_current_brand_for_post
	 */
	public function test_get_current_brand_for_post() {
		$brand1 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$brand2 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		$post     = $this->factory->post->create_and_get( array( 'post_title' => 'Post 1' ) );
		$page     = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Post 2',
				'post_type'  => 'page',
			)
		);
		$other_pt = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Post 3',
				'post_type'  => 'nav_menu',
			)
		);

		$this->assertSame( null, Taxonomy::get_current_brand_for_post( $post->ID ), 'Null should be returned if none is set' );

		wp_set_post_terms( $post->ID, $brand1->term_id, Taxonomy::SLUG );
		$this->assertSame( $brand1->term_id, Taxonomy::get_current_brand_for_post( $post->ID )->term_id, 'Related brand should be returned if ony one is added' );

		wp_set_post_terms( $post->ID, [ $brand1->term_id, $brand2->term_id ], Taxonomy::SLUG );
		$this->assertSame( null, Taxonomy::get_current_brand_for_post( $post->ID ), 'Null should be returned if more than on brand is set' );

		wp_set_post_terms( $page->ID, $brand2->term_id, Taxonomy::SLUG );
		$this->assertSame( $brand2->term_id, Taxonomy::get_current_brand_for_post( $page->ID )->term_id, 'Related brand should be returned if ony one is added' );

		wp_set_post_terms( $other_pt->ID, $brand1->term_id, Taxonomy::SLUG );
		$this->assertSame( null, Taxonomy::get_current_brand_for_post( $other_pt->ID ), 'Null should be returned for other post types' );
	}

	/**
	 * Test fallback logic for posts that are in a branded category but don't have a brand assigned.
	 */
	public function test_get_current_brand_for_post_fallback() {
		$brand  = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$brand2 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );

		$category_with_brand = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );
		add_term_meta( $category_with_brand->term_id, Taxonomy::PRIMARY_META_KEY, $brand->term_id );

		$category_with_brand2 = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );
		add_term_meta( $category_with_brand2->term_id, Taxonomy::PRIMARY_META_KEY, $brand2->term_id );

		$category_with_brand2_2 = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );
		add_term_meta( $category_with_brand2_2->term_id, Taxonomy::PRIMARY_META_KEY, $brand2->term_id );

		$category_with_parent_branded = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'category',
				'parent'   => $brand2->term_id,
			)
		);

		$category_without_brand = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );

		$post = $this->factory->post->create_and_get( array( 'post_title' => 'Post 1' ) );
		wp_set_post_categories( $post->ID, [ $category_with_brand->term_id ] );

		$post2 = $this->factory->post->create_and_get( array( 'post_title' => 'Post 2' ) );
		wp_set_post_categories( $post2->ID, [ $category_without_brand->term_id ] );

		$post_one_branded_one_unbranded = $this->factory->post->create_and_get( array( 'post_title' => 'Post one branded one unbranded' ) );
		wp_set_post_categories( $post_one_branded_one_unbranded->ID, [ $category_without_brand->term_id, $category_with_brand->term_id ] );

		$post_with_two_branded_cats = $this->factory->post->create_and_get( array( 'post_title' => 'Post 3' ) );
		wp_set_post_categories( $post_with_two_branded_cats->ID, [ $category_with_brand->term_id, $category_with_brand2->term_id ] );

		$post_with_parent_and_child_branded = $this->factory->post->create_and_get( array( 'post_title' => 'Post with parent and child branded cats' ) );
		wp_set_post_categories( $post_with_parent_and_child_branded->ID, [ $category_with_brand2->term_id, $category_with_parent_branded->term_id ] );

		$post_with_two_cats_in_the_same_brand = $this->factory->post->create_and_get( array( 'post_title' => 'Post with two cats related to the same brand' ) );
		wp_set_post_categories( $post_with_two_cats_in_the_same_brand->ID, [ $category_with_brand2->term_id, $category_with_brand2_2->term_id ] );

		$this->assertSame( $brand->term_id, Taxonomy::get_current_brand_for_post( $post->ID )->term_id, 'Related brand should be returned for posts in a branded category that are not explicitly branded' );
		$this->assertSame( null, Taxonomy::get_current_brand_for_post( $post2->ID ), 'Null should be returned for unbranded posts in an unbranded category' );
		$this->assertSame( $brand->term_id, Taxonomy::get_current_brand_for_post( $post_one_branded_one_unbranded->ID )->term_id, 'Related brand should be returned for posts in multiple categories but when only one is branded' );
		$this->assertSame( null, Taxonomy::get_current_brand_for_post( $post_with_two_branded_cats->ID ), 'Null should be returned if post is assigned to more than one branded category' );
		$this->assertSame( $brand2->term_id, Taxonomy::get_current_brand_for_post( $post_with_parent_and_child_branded->ID )->term_id, 'Brand should be returned if post is assigned to more than one branded category, but if they all are related to the same brand' );
		$this->assertSame( $brand2->term_id, Taxonomy::get_current_brand_for_post( $post_with_two_cats_in_the_same_brand->ID )->term_id, 'Brand should be returned if post is assigned to more than one branded category, but if they all are related to the same brand' );
	}

	/**
	 * Test get_current_brand_for_term
	 */
	public function test_get_current_brand_for_term() {
		$brand1 = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$brand2 = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );

		$this->assertSame( $brand1->term_id, Taxonomy::get_current_brand_for_term( $brand1->term_id )->term_id, 'Term should be returned if is a brand' );
		$this->assertSame( null, Taxonomy::get_current_brand_for_term( $brand2->term_id ), 'Null should be returned if other taxonomy' );
	}

	/**
	 * Tests get current brand and determine current brand methods
	 */
	public function test_determine_current_brand() {
		$author_wo_brand             = $this->factory->user->create_and_get();
		$author_with_brand           = $this->factory->user->create_and_get();
		$author_with_invalid_brand   = $this->factory->user->create_and_get();
		$author_with_invalid_brand_2 = $this->factory->user->create_and_get();
		$brand1                      = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$brand2                      = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$brand_with_page_on_front    = $this->factory->term->create_and_get( array( 'taxonomy' => Taxonomy::SLUG ) );
		$simple_category             = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );
		$category_with_brand         = $this->factory->term->create_and_get( array( 'taxonomy' => 'category' ) );
		$simple_category_child       = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'category',
				'parent'   => $simple_category->term_id,
			)
		);
		$category_with_brand_child   = $this->factory->term->create_and_get(
			array(
				'taxonomy' => 'category',
				'parent'   => $category_with_brand->term_id,
			)
		);
		$simple_tag                  = $this->factory->term->create_and_get( array( 'taxonomy' => 'post_tag' ) );
		$tag_with_brand              = $this->factory->term->create_and_get( array( 'taxonomy' => 'post_tag' ) );
		$post                        = $this->factory->post->create_and_get(
			array(
				'post_title'  => 'Post 1',
				'post_author' => $author_wo_brand->ID,
			)
		);
		$post_2_brands               = $this->factory->post->create_and_get(
			array(
				'post_title'  => 'Post 2',
				'post_author' => $author_wo_brand->ID,
			)
		);
		$post_2_brands_and_primary   = $this->factory->post->create_and_get(
			array(
				'post_title'  => 'Post 2 and primary',
				'post_author' => $author_wo_brand->ID,
			)
		);
		$page                        = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Post 2',
				'post_type'  => 'page',
			)
		);
		$page2                       = $this->factory->post->create_and_get(
			array(
				'post_title' => 'Page 2',
				'post_type'  => 'page',
			)
		);

		wp_set_post_terms( $post->ID, $brand1->term_id, Taxonomy::SLUG );
		wp_set_post_terms( $post->ID, $simple_category->term_id, 'category' );
		wp_set_post_terms( $post_2_brands->ID, [ $brand1->term_id, $brand2->term_id ], Taxonomy::SLUG );
		wp_set_post_terms( $post_2_brands_and_primary->ID, [ $brand1->term_id, $brand2->term_id ], Taxonomy::SLUG );
		add_post_meta( $post_2_brands_and_primary->ID, Taxonomy::PRIMARY_META_KEY, $brand2->term_id );
		wp_set_post_terms( $post_2_brands->ID, $simple_category->term_id, 'category' );

		add_user_meta( $author_with_brand->ID, Taxonomy::PRIMARY_META_KEY, $brand1->term_id );
		add_user_meta( $author_with_invalid_brand->ID, Taxonomy::PRIMARY_META_KEY, 999999 );
		add_user_meta( $author_with_invalid_brand_2->ID, Taxonomy::PRIMARY_META_KEY, $simple_category->term_id );

		add_term_meta( $category_with_brand->term_id, Taxonomy::PRIMARY_META_KEY, $brand1->term_id );
		add_term_meta( $tag_with_brand->term_id, Taxonomy::PRIMARY_META_KEY, $brand1->term_id );

		add_term_meta( $brand_with_page_on_front->term_id, Show_Page_On_Front::get_key(), $page2->ID );

		// home.
		$this->go_to( '/' );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on home' );

		// search.
		$this->go_to( '/?s=asd' );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on search' );

		// Brand archive.
		$this->go_to( get_term_link( $brand1 ) );
		$this->assertSame( $brand1->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on brand archive' );

		// Post with one brand.
		$this->go_to( get_permalink( $post->ID ) );
		$this->assertSame( $brand1->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on post with one brand' );

		// Post with two brands.
		$this->go_to( get_permalink( $post_2_brands->ID ) );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on post with two brands' );

		// Post with two brands and primary.
		$this->go_to( get_permalink( $post_2_brands_and_primary->ID ) );
		$this->assertSame( $brand2->term_id, Taxonomy::get_current()->term_id, 'Primary brand should be returned if on post with two brands and primary' );

		// author archive.
		$this->go_to( get_author_posts_url( $author_wo_brand->ID ) );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on author archive' );

		$this->go_to( get_author_posts_url( $author_with_brand->ID ) );
		$this->assertSame( $brand1->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on author archive when primary brand is set' );

		$this->go_to( get_author_posts_url( $author_with_invalid_brand->ID ) );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on author archive when primary brand is invalid' );

		$this->go_to( get_author_posts_url( $author_with_invalid_brand_2->ID ) );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on author archive when primary brand is invalid' );

		// categories and tags.
		$this->go_to( get_term_link( $simple_category ) );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on category without primary brand' );

		$this->go_to( get_term_link( $simple_tag ) );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on tag without primary brand' );

		// category with brand.
		$this->go_to( get_term_link( $category_with_brand ) );
		$this->assertSame( $brand1->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on category with brand' );

		// child category of a category with brand.
		$this->go_to( get_term_link( $category_with_brand_child ) );
		$this->assertSame( $brand1->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on child category of a category with brand' );

		// child category of a category without brand.
		$this->go_to( get_term_link( $simple_category_child ) );
		$this->assertSame( null, Taxonomy::get_current(), 'Null should be returned if on child category of a category without brand' );

		// tag with brand.
		$this->go_to( get_term_link( $tag_with_brand ) );
		$this->assertSame( $brand1->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on tag with brand' );

		// Brand with page on front.
		$this->go_to( get_term_link( $brand_with_page_on_front ) );
		$this->assertSame( $brand_with_page_on_front->term_id, Taxonomy::get_current()->term_id, 'Brand should be returned if on brand with page on front' );

		// Page set to be the front page of a brand.
		$this->go_to( get_permalink( $page2->ID ) );
		$this->assertSame( $brand_with_page_on_front->term_id, Taxonomy::get_current()->term_id, 'Page that is set to be used as front page of a brand should load that brand' );
	}
}
