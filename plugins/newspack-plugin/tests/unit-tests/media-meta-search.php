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

	/**
	 * Run a media library search the way wp_ajax_query_attachments() does.
	 *
	 * @param array $args Query args to merge over the media library defaults.
	 * @return int[] IDs of the posts found, in order.
	 */
	private function media_library_search( $args ) {
		$query = array_merge(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit,private',
				'posts_per_page' => 80,
				'paged'          => 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			],
			$args
		);
		add_filter( 'wp_allow_query_attachment_by_filename', '__return_true' );
		$wp_query = new WP_Query( apply_filters( 'ajax_query_attachments_args', $query ) );
		return wp_list_pluck( $wp_query->posts, 'ID' );
	}

	/**
	 * Create an attachment whose only match for a search is its media credit.
	 *
	 * @param string $credit The credit to store.
	 * @param array  $args   Post fields to override.
	 * @return int Attachment ID.
	 */
	private function create_credited_attachment( $credit, $args = [] ) {
		$id = wp_insert_post(
			array_merge(
				[
					'post_type'      => 'attachment',
					'post_title'     => 'Untitled',
					'post_status'    => 'inherit',
					'post_mime_type' => 'image/jpeg',
				],
				$args
			)
		);
		add_post_meta( $id, '_wp_attached_file', 'test/file.jpg' );
		add_post_meta( $id, Newspack_Image_Credits::MEDIA_CREDIT_META, $credit );
		return $id;
	}

	/**
	 * A credit match is an alternative to the text match only: the media type,
	 * status and post type constraints still apply to it.
	 *
	 * The credit match used to be appended as `... OR ID IN (...)` to the end of
	 * the WHERE clause, and AND binds tighter than OR, so credited items bypassed
	 * every other constraint.
	 */
	public function test_credit_match_does_not_bypass_query_filters() {
		$image   = $this->create_credited_attachment( 'Zzqq Photo Agency' );
		$pdf     = $this->create_credited_attachment( 'Zzqq Photo Agency', [ 'post_mime_type' => 'application/pdf' ] );
		$trashed = $this->create_credited_attachment( 'Zzqq Photo Agency', [ 'post_status' => 'trash' ] );
		$post    = wp_insert_post(
			[
				'post_title'  => 'Unrelated post',
				'post_status' => 'publish',
			]
		);
		add_post_meta( $post, Newspack_Image_Credits::MEDIA_CREDIT_META, 'Zzqq Photo Agency' );

		$found = $this->media_library_search(
			[
				's'              => 'zzqq',
				'post_mime_type' => 'image',
			]
		);

		$this->assertSame( [ $image ], $found );
		$this->assertNotContains( $pdf, $found );
		$this->assertNotContains( $trashed, $found );
		$this->assertNotContains( $post, $found );
	}

	/**
	 * The credit search applies only to the media library query that asked for it.
	 *
	 * The filter used to be registered on `posts_clauses` and never removed, so
	 * every later search in the request matched on credits too, including
	 * attachment searches the media library never made (e.g. REST or front end).
	 */
	public function test_credit_search_only_applies_to_the_media_library_query() {
		$image = $this->create_credited_attachment( 'Zzqq Photo Agency' );
		$this->assertSame( [ $image ], $this->media_library_search( [ 's' => 'zzqq' ] ) );

		$later_attachment_search = new WP_Query(
			[
				's'           => 'zzqq',
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			]
		);

		$this->assertSame( [], $later_attachment_search->posts );
	}

	/**
	 * Users limited to their own media don't find other people's media through
	 * its credit.
	 */
	public function test_credit_search_respects_the_own_media_restriction() {
		// Authors can upload but not edit others' posts, so Patches limits them to their own media.
		$author        = self::factory()->user->create( [ 'role' => 'author' ] );
		$other_author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$own           = $this->create_credited_attachment( 'Zzqq Photo Agency', [ 'post_author' => $author ] );
		$someone_elses = $this->create_credited_attachment( 'Zzqq Photo Agency', [ 'post_author' => $other_author ] );
		wp_set_current_user( $author );

		$found = $this->media_library_search( [ 's' => 'zzqq' ] );

		$this->assertSame( [ $own ], $found );
		$this->assertNotContains( $someone_elses, $found );
	}
}
