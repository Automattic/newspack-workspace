<?php
/**
 * Tests the Performance optmisations.
 *
 * @package Newspack\Tests
 */

use Newspack\Newspack_Image_Credits;

/**
 * Tests the performance optmisations.
 */
class Newspack_Test_Media_Meta_Search extends WP_UnitTestCase {

	/**
	 * Test search on attachment meta.
	 */
	public function test_search() {
		$media1 = wp_insert_post(
			[
				'post_type'    => 'attachment',
				'post_title'   => 'Test Media 1',
				'post_content' => 'nanana',
				'post_status'  => 'inherit',
			]
		);
		add_post_meta( $media1, '_wp_attached_file', 'test/file.jpg' );

		$media2 = wp_insert_post(
			[
				'post_type'    => 'attachment',
				'post_title'   => 'Test Media 2',
				'post_content' => 'nonono',
				'post_status'  => 'inherit',
			]
		);
		add_post_meta( $media2, '_wp_attached_file', 'test/file.jpg' );


		$media3 = wp_insert_post(
			[
				'post_type'   => 'attachment',
				'post_title'  => 'Test Media 3',
				'post_status' => 'inherit',
			]
		);
		add_post_meta( $media3, '_wp_attached_file', 'test/file.jpg' );
		add_post_meta( $media3, Newspack_Image_Credits::MEDIA_CREDIT_ORG_META, 'ninini' );

		// should never be returned.
		$post = wp_insert_post(
			[
				'post_title'   => 'Test Post',
				'post_content' => 'nanana',
			]
		);

		$base_query = [
			's'              => 'ana',
			'post_type'      => 'attachment',
			'post_status'    => 'inherit,private',
			'posts_per_page' => 80,
			'paged'          => 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		];

		// simulates the ajax query.
		add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
		$base_query = apply_filters( 'ajax_query_attachments_args', $base_query );

		$wp_query = new WP_Query( $base_query );
		$this->assertSame( 1, $wp_query->found_posts );
		$this->assertSame( $media1, $wp_query->posts[0]->ID );

		add_post_meta( $media2, Newspack_Image_Credits::MEDIA_CREDIT_META, 'nanana' );

		$wp_query = new WP_Query( $base_query );
		$this->assertSame( 2, $wp_query->found_posts );
		$this->assertSame( $media1, $wp_query->posts[0]->ID );
		$this->assertSame( $media2, $wp_query->posts[1]->ID );

		wp_update_post(
			[
				'ID'           => $media3,
				'post_content' => 'asd nanana asd',
			]
		);

		$wp_query = new WP_Query( $base_query );
		$this->assertSame( 3, $wp_query->found_posts );
		$this->assertSame( $media1, $wp_query->posts[0]->ID );
		$this->assertSame( $media2, $wp_query->posts[1]->ID );
		$this->assertSame( $media3, $wp_query->posts[2]->ID );
	}
}
