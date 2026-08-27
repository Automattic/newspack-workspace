<?php
/**
 * Class TestOutgoingPost
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Hub\Node as Hub_Node;
use WP_User;

/**
 * Test the Outgoing_Post class.
 */
class TestOutgoingPost extends \WP_UnitTestCase {
	/**
	 * "Mocked" network nodes.
	 *
	 * @var array
	 */
	protected $network = [
		[
			'id'    => 1234,
			'title' => 'Test Node',
			'url'   => 'https://node.test',
		],
		[
			'id'    => 5678,
			'title' => 'Test Node 2',
			'url'   => 'https://other-node.test',
		],
	];

	/**
	 * A distributed post.
	 *
	 * @var Outgoing_Post
	 */
	protected $outgoing_post;

	/**
	 * An editor user.
	 *
	 * @var WP_User
	 */
	private WP_User $some_editor;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->some_editor = $this->factory->user->create_and_get( [ 'role' => 'editor' ] );

		// "Mock" the network node(s).
		update_option( Hub_Node::HUB_NODES_SYNCED_OPTION, $this->network );
		$post                = $this->factory->post->create_and_get(
			[
				'post_type'   => 'post',
				'post_author' => $this->some_editor->ID,
			]
		);
		$this->outgoing_post = new Outgoing_Post( $post );
		$this->outgoing_post->set_distribution( [ $this->network[0]['url'] ] );
	}

	/**
	 * Test adding a site URL to the config after already having added one.
	 */
	public function test_add_site_url() {
		$distribution = $this->outgoing_post->get_distribution();
		$this->assertTrue( in_array( $this->network[0]['url'], $distribution, true ) );
		$this->assertEquals( 1, count( $distribution ) );

		// Now add one more site URL.
		$this->outgoing_post->set_distribution( [ $this->network[1]['url'] ] );
		$distribution = $this->outgoing_post->get_distribution();
		// Check that both urls are there.
		$this->assertTrue( in_array( $this->network[0]['url'], $distribution, true ) );
		$this->assertTrue( in_array( $this->network[1]['url'], $distribution, true ) );
		// But no more than that.
		$this->assertEquals( 2, count( $distribution ) );
	}

	/**
	 * Test set post distribution.
	 */
	public function test_set_distribution() {
		$result = $this->outgoing_post->set_distribution( [ $this->network[1]['url'] ] );
		$this->assertFalse( is_wp_error( $result ) );
	}

	/**
	 * Distributing to sites already in the config is a no-op, not an error.
	 *
	 * WordPress returns false from update_post_meta() when the value is unchanged, which would
	 * otherwise surface as 'update_failed' on a retried request.
	 */
	public function test_set_distribution_is_idempotent() {
		$urls = [ $this->network[0]['url'], $this->network[1]['url'] ];

		$this->outgoing_post->set_distribution( $urls );

		$result = $this->outgoing_post->set_distribution( $urls );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertEqualSets( $urls, $this->outgoing_post->get_distribution() );
	}

	/**
	 * The other half of that distinction: a write that genuinely fails on a
	 * changed value must still report 'update_failed'.
	 */
	public function test_set_distribution_reports_a_genuine_write_failure() {
		$fail = function () {
			return false;
		};
		add_filter( 'update_post_metadata', $fail, 10, 0 );

		// A site not already in the config, so the write is a genuine change.
		$result = $this->outgoing_post->set_distribution( [ $this->network[1]['url'] ] );

		remove_filter( 'update_post_metadata', $fail, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'update_failed', $result->get_error_code() );
	}

	/**
	 * Slash variants of one site collapse to a single canonical entry, so the
	 * same site cannot be recorded twice.
	 */
	public function test_set_distribution_normalizes_trailing_slashes() {
		$this->outgoing_post->set_distribution( [ $this->network[1]['url'] . '/' ] );
		$this->outgoing_post->set_distribution( [ $this->network[1]['url'] ] );

		$this->assertSame(
			[ $this->network[0]['url'], $this->network[1]['url'] ],
			$this->outgoing_post->get_distribution()
		);
	}

	/**
	 * A post whose stored distribution meta was written before URLs were
	 * normalised, seeded directly so the shape is the legacy one on disk.
	 *
	 * @param string[] $urls The raw stored URLs.
	 *
	 * @return Outgoing_Post
	 */
	private function post_with_legacy_distribution( $urls ) {
		$post = $this->factory->post->create_and_get(
			[
				'post_type'   => 'post',
				'post_author' => $this->some_editor->ID,
			]
		);
		update_post_meta( $post->ID, Outgoing_Post::DISTRIBUTED_POST_META, $urls );
		return new Outgoing_Post( $post );
	}

	/**
	 * Distributing to a new site must not be blocked by a slashed URL already in
	 * the config.
	 *
	 * Rewriting the stored entries to their canonical form reads as a removal to
	 * Content_Distribution::maybe_short_circuit_distributed_meta(), which vetoes
	 * the write, so the post could never be distributed again.
	 */
	public function test_set_distribution_with_legacy_slashed_meta() {
		$outgoing_post = $this->post_with_legacy_distribution( [ $this->network[0]['url'] . '/' ] );

		$result = $outgoing_post->set_distribution( [ $this->network[1]['url'] ] );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertContains( $this->network[1]['url'], $outgoing_post->get_distribution() );
	}

	/**
	 * The same, for the shape where both slash variants of one site are stored.
	 */
	public function test_set_distribution_with_both_slash_variants_stored() {
		$outgoing_post = $this->post_with_legacy_distribution(
			[ $this->network[0]['url'] . '/', $this->network[0]['url'] ]
		);

		$result = $outgoing_post->set_distribution( [ $this->network[1]['url'] ] );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertContains( $this->network[1]['url'], $outgoing_post->get_distribution() );
	}

	/**
	 * A site already in the config under a slashed URL is not recorded a second
	 * time when it is distributed to again.
	 */
	public function test_set_distribution_does_not_duplicate_a_slashed_site() {
		$outgoing_post = $this->post_with_legacy_distribution( [ $this->network[0]['url'] . '/' ] );

		$result = $outgoing_post->set_distribution( [ $this->network[0]['url'] ] );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertCount( 1, $outgoing_post->get_distribution() );
	}

	/**
	 * Test remove distribution.
	 */
	public function test_remove_distribution() {
		$this->outgoing_post->set_distribution( [ $this->network[1]['url'] ] );
		$result = $this->outgoing_post->remove_distribution( $this->network[1]['url'] );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertFalse( in_array( $this->network[1]['url'], $this->outgoing_post->get_distribution(), true ) );
	}

	/**
	 * A site stored under a slashed URL is removable by its canonical URL, which
	 * is the form incoming post-deleted events carry.
	 */
	public function test_remove_distribution_matches_a_slashed_url() {
		$outgoing_post = $this->post_with_legacy_distribution( [ $this->network[0]['url'] . '/' ] );

		$result = $outgoing_post->remove_distribution( $this->network[0]['url'] );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertEmpty( $outgoing_post->get_distribution() );
	}

	/**
	 * Test get post distribution.
	 */
	public function test_get_distribution() {
		$distribution = $this->outgoing_post->get_distribution();
		$this->assertSame( [ $this->network[0]['url'] ], $distribution );
	}

	/**
	 * Test get distribution for non-distributed.
	 */
	public function test_get_distribution_for_non_distributed() {
		$post          = $this->factory->post->create_and_get( [ 'post_type' => 'post' ] );
		$outgoing_post = new Outgoing_Post( $post );
		$distribution  = $outgoing_post->get_distribution();
		$this->assertEmpty( $distribution );
	}

	/**
	 * Test is distributed.
	 */
	public function test_is_distributed() {
		// Assert regular post.
		$post          = $this->factory->post->create_and_get( [ 'post_type' => 'post' ] );
		$outgoing_post = new Outgoing_Post( $post );
		$this->assertFalse( $outgoing_post->is_distributed() );
	}

	/**
	 * Test get payload.
	 */
	public function test_get_payload() {
		$payload = $this->outgoing_post->get_payload();
		$this->assertNotEmpty( $payload );

		$distribution = $this->outgoing_post->get_distribution();

		$this->assertSame( get_bloginfo( 'url' ), $payload['site_url'] );
		$this->assertSame( $this->outgoing_post->get_post()->ID, $payload['post_id'] );
		$this->assertSame( get_permalink( $this->outgoing_post->get_post()->ID ), $payload['post_url'] );
		$this->assertSame( 32, strlen( $payload['network_post_id'] ) );
		$this->assertEquals( $distribution, $payload['sites'] );

		// Assert that 'post_data' only contains the expected keys.
		$post_data_keys = [
			'title',
			'post_status',
			'date_gmt',
			'modified_gmt',
			'slug',
			'post_type',
			'raw_content',
			'content',
			'excerpt',
			'comment_status',
			'ping_status',
			'thumbnail_url',
			'taxonomy',
			'post_meta',
			'media_data',
			'author',
		];
		$this->assertEmpty( array_diff( $post_data_keys, array_keys( $payload['post_data'] ) ) );
		$this->assertEmpty( array_diff( array_keys( $payload['post_data'] ), $post_data_keys ) );
	}

	/**
	 * A slashed URL kept raw in the meta is dispatched in its canonical form, so
	 * the recipient's strict match against its own url succeeds rather than
	 * rejecting the event as not_distributed_to_site.
	 */
	public function test_get_payload_normalizes_slashed_sites() {
		$outgoing_post = $this->post_with_legacy_distribution( [ $this->network[0]['url'] . '/' ] );

		$payload = $outgoing_post->get_payload();

		$this->assertSame( [ $this->network[0]['url'] . '/' ], $outgoing_post->get_distribution() );
		$this->assertSame( [ $this->network[0]['url'] ], $payload['sites'] );
	}

	/**
	 * Test that the author(s) are included in the payload.
	 */
	public function test_authors_data(): void {
		$payload = $this->outgoing_post->get_payload();
		$this->assertNotEmpty( $payload['post_data']['author'] );
		$this->assertEquals( $this->some_editor->user_email, $payload['post_data']['author']['user_email'] );
	}

	/**
	 * Test post meta.
	 */
	public function test_post_meta() {
		$post = $this->outgoing_post->get_post();
		$meta_key   = 'test_meta_key';
		$meta_value = 'test_meta_value';
		update_post_meta( $post->ID, $meta_key, $meta_value );

		$arr_meta_key = 'test_arr_meta_key';
		$arr_meta_value = [ 1, 2, 3 ];
		update_post_meta( $post->ID, $arr_meta_key, $arr_meta_value );

		$multiple_meta_key = 'test_multiple_meta_key';
		add_post_meta( $post->ID, $multiple_meta_key, 'a' );
		add_post_meta( $post->ID, $multiple_meta_key, 'b' );

		$payload = $this->outgoing_post->get_payload();
		$this->assertArrayHasKey( $meta_key, $payload['post_data']['post_meta'] );

		$this->assertSame( $meta_value, $payload['post_data']['post_meta'][ $meta_key ][0] );

		$this->assertArrayHasKey( $arr_meta_key, $payload['post_data']['post_meta'] );
		$this->assertSame( $arr_meta_value, $payload['post_data']['post_meta'][ $arr_meta_key ][0] );

		$this->assertArrayHasKey( $multiple_meta_key, $payload['post_data']['post_meta'] );
		$this->assertSame( 'a', $payload['post_data']['post_meta'][ $multiple_meta_key ][0] );
		$this->assertSame( 'b', $payload['post_data']['post_meta'][ $multiple_meta_key ][1] );
	}

	/**
	 * Test ignored taxonomies.
	 */
	public function test_ignored_taxonomies() {
		$post = $this->outgoing_post->get_post();
		$taxonomy = 'author';
		register_taxonomy( $taxonomy, 'post', [ 'public' => true ] );

		$term = $this->factory->term->create( [ 'taxonomy' => $taxonomy ] );
		wp_set_post_terms( $post->ID, [ $term ], $taxonomy );

		$payload = $this->outgoing_post->get_payload();
		$this->assertTrue( empty( $payload['post_data']['taxonomy'][ $taxonomy ] ) );
	}

	/**
	 * Test empty taxonomies are included in the payload.
	 */
	public function test_empty_taxonomies_included_in_payload() {
		// Create a post without any terms.
		$post = $this->factory->post->create_and_get(
			[
				'post_type'   => 'post',
				'post_author' => $this->some_editor->ID,
			]
		);
		$outgoing_post = new Outgoing_Post( $post );
		$outgoing_post->set_distribution( [ $this->network[0]['url'] ] );

		$payload = $outgoing_post->get_payload();

		// Assert that category and post_tag exist in the payload as empty arrays.
		$this->assertArrayHasKey( 'taxonomy', $payload['post_data'] );
		$this->assertArrayHasKey( 'post_tag', $payload['post_data']['taxonomy'] );
		$this->assertIsArray( $payload['post_data']['taxonomy']['post_tag'] );
		$this->assertEmpty( $payload['post_data']['taxonomy']['post_tag'] );
	}

	/**
	 * Test empty custom taxonomy is included in the payload.
	 */
	public function test_empty_custom_taxonomy_included_in_payload() {
		// Register a custom taxonomy.
		$custom_taxonomy = 'custom_taxonomy';
		register_taxonomy( $custom_taxonomy, 'post', [ 'public' => true ] );

		// Create a post without any terms in the custom taxonomy.
		$post = $this->factory->post->create_and_get(
			[
				'post_type'   => 'post',
				'post_author' => $this->some_editor->ID,
			]
		);
		$outgoing_post = new Outgoing_Post( $post );
		$outgoing_post->set_distribution( [ $this->network[0]['url'] ] );

		$payload = $outgoing_post->get_payload();

		// Assert that the custom taxonomy exists in the payload as an empty array.
		$this->assertArrayHasKey( 'taxonomy', $payload['post_data'] );
		$this->assertArrayHasKey( $custom_taxonomy, $payload['post_data']['taxonomy'] );
		$this->assertIsArray( $payload['post_data']['taxonomy'][ $custom_taxonomy ] );
		$this->assertEmpty( $payload['post_data']['taxonomy'][ $custom_taxonomy ] );
	}

	/**
	 * Test get partial payload.
	 */
	public function test_get_partial_payload() {
		$partial_payload = $this->outgoing_post->get_partial_payload( 'post_meta' );

		$payload = $this->outgoing_post->get_payload();
		$this->assertTrue( $partial_payload['partial'] );
		$this->assertSame( $payload['network_post_id'], $partial_payload['network_post_id'] );
		$this->assertSame( $payload['post_data']['post_meta'], $partial_payload['post_data']['post_meta'] );
		$this->assertSame( $payload['post_data']['date_gmt'], $partial_payload['post_data']['date_gmt'] );
		$this->assertSame( $payload['post_data']['modified_gmt'], $partial_payload['post_data']['modified_gmt'] );
		$this->assertArrayNotHasKey( 'title', $partial_payload['post_data'] );
		$this->assertArrayNotHasKey( 'content', $partial_payload['post_data'] );
		$this->assertArrayNotHasKey( 'taxonomy', $partial_payload['post_data'] );
	}

	/**
	 * Test get partial payload multiple keys.
	 */
	public function test_get_partial_payload_multiple_keys() {
		$partial_payload = $this->outgoing_post->get_partial_payload( [ 'post_meta', 'taxonomy' ] );

		$payload = $this->outgoing_post->get_payload();
		$this->assertTrue( $partial_payload['partial'] );
		$this->assertSame( $payload['network_post_id'], $partial_payload['network_post_id'] );
		$this->assertSame( $payload['post_data']['post_meta'], $partial_payload['post_data']['post_meta'] );
		$this->assertSame( $payload['post_data']['taxonomy'], $partial_payload['post_data']['taxonomy'] );
		$this->assertSame( $payload['post_data']['date_gmt'], $partial_payload['post_data']['date_gmt'] );
		$this->assertSame( $payload['post_data']['modified_gmt'], $partial_payload['post_data']['modified_gmt'] );
		$this->assertArrayNotHasKey( 'title', $partial_payload['post_data'] );
		$this->assertArrayNotHasKey( 'content', $partial_payload['post_data'] );
	}

	/**
	 * An attachment carrying dimensions, so media_data holds real sizes rather
	 * than the 'none' the node's lightbox falls back to.
	 *
	 * @param int $post_parent The post to attach it to.
	 * @param int $width       The image width.
	 * @param int $height      The image height.
	 *
	 * @return int The attachment ID.
	 */
	private function image_attachment( $post_parent = 0, $width = 1200, $height = 800 ) {
		$attachment_id = $this->factory->attachment->create_object(
			[
				'file'           => 'media-data-' . wp_rand() . '.png',
				'post_parent'    => $post_parent,
				'post_mime_type' => 'image/png',
			]
		);

		wp_update_attachment_metadata(
			$attachment_id,
			[
				'file'   => 'media-data.png',
				'width'  => $width,
				'height' => $height,
			]
		);

		return $attachment_id;
	}

	/**
	 * An image block in the shape the editor saves it.
	 *
	 * @param int $attachment_id The attachment ID.
	 *
	 * @return string The block markup.
	 */
	private function image_block( $attachment_id ) {
		return sprintf(
			'<!-- wp:image {"id":%1$d,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
			$attachment_id,
			wp_get_attachment_url( $attachment_id )
		);
	}

	/**
	 * A distributed post carrying the given content.
	 *
	 * @param string $content The post content.
	 *
	 * @return Outgoing_Post
	 */
	private function outgoing_post_with_content( $content ) {
		$post = $this->factory->post->create_and_get(
			[
				'post_type'    => 'post',
				'post_author'  => $this->some_editor->ID,
				'post_content' => $content,
			]
		);

		$outgoing_post = new Outgoing_Post( $post );
		$outgoing_post->set_distribution( [ $this->network[0]['url'] ] );

		return $outgoing_post;
	}

	/**
	 * Every image in the distributed content is described in media_data, with the
	 * dimensions the node needs to open it at full size.
	 */
	public function test_media_data_describes_the_content_images() {
		$attachment_id = $this->image_attachment( 0, 1200, 800 );
		$outgoing_post = $this->outgoing_post_with_content( $this->image_block( $attachment_id ) );

		$media_data = $outgoing_post->get_payload()['post_data']['media_data'];

		$this->assertArrayHasKey( $attachment_id, $media_data );
		$this->assertSame( 1200, $media_data[ $attachment_id ]['width'] );
		$this->assertSame( 800, $media_data[ $attachment_id ]['height'] );
	}

	/**
	 * The payload follows the content being distributed, so an image that exists
	 * only because a `the_content` filter injected it stays out of media_data.
	 */
	public function test_media_data_ignores_images_injected_by_filters() {
		$in_content = $this->image_attachment();
		$injected   = $this->image_attachment();

		$inject = function ( $content ) use ( $injected ) {
			return $content . '<img src="http://example.org/injected.png" class="wp-image-' . $injected . '"/>';
		};
		add_filter( 'the_content', $inject );

		$outgoing_post = $this->outgoing_post_with_content( $this->image_block( $in_content ) );
		$media_data    = $outgoing_post->get_payload()['post_data']['media_data'];

		remove_filter( 'the_content', $inject );

		$this->assertArrayHasKey( $in_content, $media_data );
		$this->assertArrayNotHasKey( $injected, $media_data );
	}

	/**
	 * The reported case. A WordPress 7.1 dynamic gallery stores no image IDs, so
	 * the images it resolves to were missing from media_data and the node opened
	 * them in its lightbox at unknown dimensions.
	 */
	public function test_media_data_covers_a_flattened_dynamic_gallery() {
		if ( ! function_exists( 'block_core_gallery_resolve_dynamic_source' ) ) {
			$this->markTestSkipped( 'Dynamic galleries require WordPress 7.1 or later; this suite runs ' . get_bloginfo( 'version' ) . '.' );
		}

		$outgoing_post = $this->outgoing_post_with_content(
			'<!-- wp:gallery {"dynamicContent":{"source":"core/attached-media"}} --><!-- /wp:gallery -->'
		);

		$attachment_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$attachment_ids[] = $this->image_attachment( $outgoing_post->get_post()->ID, 900, 600 );
		}

		$media_data = $outgoing_post->get_payload()['post_data']['media_data'];

		foreach ( $attachment_ids as $attachment_id ) {
			$this->assertArrayHasKey( $attachment_id, $media_data );
			$this->assertSame( 900, $media_data[ $attachment_id ]['width'] );
			$this->assertSame( 600, $media_data[ $attachment_id ]['height'] );
		}
	}
}
