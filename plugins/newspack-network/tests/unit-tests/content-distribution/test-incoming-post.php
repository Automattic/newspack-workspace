<?php
/**
 * Class TestIncomingPost
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

use Newspack_Network\Content_Distribution\Incoming_Post;

/**
 * Test the Incoming_Post class.
 */
class TestIncomingPost extends \WP_UnitTestCase {
	/**
	 * URL for node that distributes posts.
	 *
	 * @var string
	 */
	protected $node_1 = 'https://node1.test';

	/**
	 * URL for node that receives posts.
	 *
	 * @var string
	 */
	protected $node_2 = 'https://node2.test';

	/**
	 * A user with the editor role.
	 *
	 * @var \WP_User
	 */
	protected $some_editor;

	/**
	 * A linked post.
	 *
	 * @var Incoming_Post
	 */
	protected $incoming_post;

	/**
	 * Get sample post payload.
	 */
	private function get_sample_payload() {
		return get_sample_payload( $this->node_1, $this->node_2 );
	}

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		// Set the site URL for the node that receives posts.
		update_option( 'siteurl', $this->node_2 );
		update_option( 'home', $this->node_2 );

		$this->some_editor = $this->factory->user->create_and_get( [ 'role' => 'editor' ] );

		$this->incoming_post = new Incoming_Post( $this->get_sample_payload() );
	}

	/**
	 * Test get payload error
	 */
	public function test_validate_payload() {
		$payload = $this->get_sample_payload();
		$error = Incoming_Post::get_payload_error( $payload );
		$this->assertFalse( is_wp_error( $error ) );

		// Assert with invalid post.
		$error = Incoming_Post::get_payload_error( [] );
		$this->assertTrue( is_wp_error( $error ) );
		$this->assertSame( 'invalid_post', $error->get_error_code() );

		// Assert with invalid site.
		update_option( 'siteurl', $this->node_1 );
		update_option( 'home', $this->node_1 );
		$error = Incoming_Post::get_payload_error( $payload );
		$this->assertTrue( is_wp_error( $error ) );
		$this->assertSame( 'not_distributed_to_site', $error->get_error_code() );
	}

	/**
	 * Test insert linked post.
	 */
	public function test_insert() {
		$this->assertEmpty( $this->incoming_post->ID );

		$post_id = $this->incoming_post->insert();

		$this->assertNotEmpty( $this->incoming_post->ID );

		$this->assertFalse( is_wp_error( $post_id ) );
		$this->assertGreaterThan( 0, $post_id );

		$payload = $this->get_sample_payload();

		// Assert post data.
		$this->assertSame( $payload['post_data']['date_gmt'], get_the_date( 'Y-m-d H:i:s', $post_id ) );
		$this->assertSame( $payload['post_data']['title'], get_the_title( $post_id ) );
		$this->assertSame( $payload['post_data']['raw_content'], get_post_field( 'post_content', $post_id ) );

		// Assert featured image.
		$this->assertNotEmpty( get_post_thumbnail_id( $post_id ) );
		$this->assertSame( 'Caption', wp_get_attachment_caption( get_post_thumbnail_id( $post_id ) ) );
		$this->assertSame( 'Credit', get_post_meta( get_post_thumbnail_id( $post_id ), '_media_credit', true ) );
		$this->assertSame( 'https://credit.url', get_post_meta( get_post_thumbnail_id( $post_id ), '_media_credit_url', true ) );
		$this->assertSame( 'Alt', get_post_meta( get_post_thumbnail_id( $post_id ), '_wp_attachment_image_alt', true ) );

		// Assert taxonomy terms.
		$terms = wp_get_post_terms( $post_id, [ 'category', 'post_tag' ] );
		$this->assertSame( [ 'Category 1', 'Category 2', 'Tag 1', 'Tag 2' ], wp_list_pluck( $terms, 'name' ) );
		$this->assertSame( [ 'category-1', 'category-2', 'tag-1', 'tag-2' ], wp_list_pluck( $terms, 'slug' ) );

		// Assert post meta.
		$this->assertSame( 'value', get_post_meta( $post_id, 'single', true ) );
		$this->assertSame( [ 'a' => 'b', 'c' => 'd' ], get_post_meta( $post_id, 'array', true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( [ 'value 1', 'value 2' ], get_post_meta( $post_id, 'multiple', false ) );
	}

	/**
	 * Test instantiation with post ID.
	 */
	public function test_instantiation_with_post_id() {
		$this->incoming_post->insert();

		$incoming_post = new Incoming_Post( $this->incoming_post->ID );

		$this->assertInstanceOf( Incoming_Post::class, $incoming_post );
		$this->assertSame( $this->incoming_post->ID, $incoming_post->ID );
	}

	/**
	 * Test insert existing linked post.
	 */
	public function test_insert_existing_post() {
		// Insert the linked post for the first time.
		$post_id = $this->incoming_post->insert();

		// Modify the post payload to simulate an update.
		$payload = $this->get_sample_payload();
		$payload['post_data']['title'] = 'Updated Title';
		$payload['post_data']['content'] = 'Updated Content';
		$payload['post_data']['raw_content'] = 'Updated Content';

		// Insert the updated linked post.
		$new_linked_post = new Incoming_Post( $payload );
		$updated_post_id = $new_linked_post->insert();

		// Assert that the updated post has the same ID as the original post.
		$this->assertSame( $post_id, $updated_post_id );

		// Assert that the updated post has the updated title and content.
		$incoming_post = get_post( $post_id );
		$this->assertSame( 'Updated Title', $incoming_post->post_title );
		$this->assertSame( 'Updated Content', $incoming_post->post_content );
	}

	/**
	 * Test insert post when unlinked.
	 */
	public function test_insert_post_when_unlinked() {
		$post_id = $this->incoming_post->insert();

		// Unlink the post.
		$this->incoming_post->set_unlinked();

		// Update linked post with custom content.
		$this->factory->post->update_object(
			$post_id,
			[
				'post_title'   => 'Custom Title',
				'post_content' => 'Custom Content',
			]
		);

		// Modify the post payload for an update.
		$payload = $this->get_sample_payload();
		$payload['post_data']['title'] = 'Updated Title';
		$payload['post_data']['content'] = 'Updated Content';
		$payload['post_data']['raw_content'] = 'Updated Content';

		// Insert the updated linked post.
		$this->incoming_post->insert( $payload );

		// Assert that the custom content was preserved.
		$incoming_post = get_post( $post_id );
		$this->assertSame( 'Custom Title', $incoming_post->post_title );
		$this->assertSame( 'Custom Content', $incoming_post->post_content );
	}

	/**
	 * Test get original post URL.
	 */
	public function test_get_original_post_url() {
		$post_id = $this->incoming_post->insert();
		$original_url = $this->incoming_post->get_original_post_url();
		$payload = $this->get_sample_payload();
		$this->assertSame( $payload['post_url'], $original_url );
	}

	/**
	 * Test get original site URL.
	 */
	public function test_get_original_site_url() {
		$post_id = $this->incoming_post->insert();
		$original_url = $this->incoming_post->get_original_site_url();
		$payload = $this->get_sample_payload();
		$this->assertSame( $payload['site_url'], $original_url );
	}

	/**
	 * Test get original post edit URL.
	 */
	public function test_get_original_post_edit_url() {
		$this->incoming_post->insert();
		$this->assertSame(
			'https://node1.test/wp-admin/post.php?post=1&action=edit',
			$this->incoming_post->get_original_post_edit_url()
		);
	}

	/**
	 * Test relink post.
	 */
	public function test_relink_post() {
		$post_id = $this->incoming_post->insert();

		// Unlink the post.
		$this->incoming_post->set_unlinked();

		// Update linked post with custom content.
		$this->factory->post->update_object(
			$post_id,
			[
				'post_title'   => 'Custom Title',
				'post_content' => 'Custom Content',
			]
		);

		// Relink the post.
		$this->incoming_post->set_unlinked( false );

		// Assert that the post is linked and distributed content restored.
		$payload = $this->get_sample_payload();
		$this->assertSame( $payload['post_data']['title'], get_the_title( $post_id ) );
		$this->assertSame( $payload['post_data']['raw_content'], get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Test insert post with old modified date.
	 */
	public function test_insert_post_with_old_modified_date() {
		// Insert the linked post for the first time.
		$post_id = $this->incoming_post->insert();

		// Modify the post payload to simulate an update with an old modified date.
		$payload = $this->get_sample_payload();
		$payload['post_data']['title'] = 'Old Title';
		$payload['post_data']['modified_gmt'] = '2020-01-01 00:00:00';

		// Insert the updated linked post.
		$error = $this->incoming_post->insert( $payload );

		// Assert that the insertion returned an error.
		$this->assertTrue( is_wp_error( $error ) );
		$this->assertSame( 'old_modified_date', $error->get_error_code() );

		// Assert that the linked post kept the most recent title.
		$incoming_post = get_post( $post_id );
		$this->assertSame( 'Title', $incoming_post->post_title );
	}

	/**
	 * Test update post thumbnail.
	 */
	public function test_update_post_thumbnail() {
		$post_id = $this->incoming_post->insert();

		$thumbnail_id = get_post_thumbnail_id( $post_id );

		// Set a different thumbnail URL.
		$payload = $this->get_sample_payload();
		$payload['post_data']['thumbnail_url'] = 'https://picsum.photos/id/2/300/300.jpg';

		// Insert the linked post with the updated thumbnail.
		$this->incoming_post->insert( $payload );

		// Assert that the thumbnail was updated.
		$new_thumbnail_id = get_post_thumbnail_id( $post_id );

		$this->assertNotEmpty( $new_thumbnail_id );
		$this->assertNotEquals( $thumbnail_id, $new_thumbnail_id );
	}

	/**
	 * Test remove post thumbnail.
	 */
	public function test_remove_post_thumbnail() {
		$post_id = $this->incoming_post->insert();

		// Remove the thumbnail.
		$payload = $this->get_sample_payload();
		$payload['post_data']['thumbnail_url'] = false;

		// Insert the linked post with the removed thumbnail.
		$this->incoming_post->insert( $payload );

		// Assert that the thumbnail was removed.
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		$this->assertEmpty( $thumbnail_id );
	}

	/**
	 * Test post meta sync.
	 */
	public function test_post_meta_sync() {
		$post_id = $this->incoming_post->insert();

		// Unlink the post.
		$this->incoming_post->set_unlinked();

		// Update the post meta.
		update_post_meta( $post_id, 'custom', 'new value' );

		// Relink the post.
		$this->incoming_post->set_unlinked( false );

		// Assert that the custom post meta was removed on relink.
		$this->assertEmpty( get_post_meta( $post_id, 'custom', true ) );
	}

	/**
	 * Test adding and deleting post meta.
	 */
	public function test_add_and_delete_multiple_post_meta() {
		$post_id = $this->incoming_post->insert();

		$payload = $this->get_sample_payload();

		$payload['post_data']['post_meta']['multiple'] = [ 'value 2', 'value 3' ];
		$this->incoming_post->insert( $payload );
		$this->assertSame( [ 'value 2', 'value 3' ], get_post_meta( $post_id, 'multiple', false ) );

		$payload['post_data']['post_meta']['multiple'] = [ 'value 3', 'value 3' ];
		$this->incoming_post->insert( $payload );
		$this->assertSame( [ 'value 3', 'value 3' ], get_post_meta( $post_id, 'multiple', false ) );
	}

	/**
	 * Test status changes.
	 */
	public function test_status_changes() {
		$post_id = $this->incoming_post->insert();

		// Assert that the default post status is draft.
		$this->assertSame( 'draft', get_post_status( $post_id ) );

		// Publish the linked post.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'publish',
			]
		);

		$payload = $this->get_sample_payload();

		// Assert that the post status updates to draft.
		$payload['post_data']['post_status'] = 'draft';
		$this->incoming_post->insert( $payload );
		$this->assertSame( 'draft', get_post_status( $post_id ) );

		// Assert that the post status does NOT update to publish.
		$payload['post_data']['post_status'] = 'publish';
		$this->incoming_post->insert( $payload );
		$this->assertSame( 'draft', get_post_status( $post_id ) );

		// Assert that the post status updates to trash.
		$payload['post_data']['post_status'] = 'trash';
		$this->incoming_post->insert( $payload );
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	/**
	 * NPPM-2871: An origin `publish` re-sync must not unschedule a node post
	 * that has been locally rescheduled to `future`. Content still flows.
	 */
	public function test_origin_publish_preserves_node_future_schedule() {
		// Linked post arrives and is created (sample payload is post_status=publish, status_on_publish=draft → draft).
		$post_id = $this->incoming_post->insert();
		$this->assertSame( 'draft', get_post_status( $post_id ) );

		// Node editor reschedules locally to a future date.
		$future_gmt = '2099-12-31 10:16:00';
		wp_update_post(
			[
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => $future_gmt,
				'post_date_gmt' => $future_gmt,
			]
		);
		$this->assertSame( 'future', get_post_status( $post_id ) );

		// Origin edits and re-syncs as `publish` with its own (past) date_gmt.
		$payload = $this->get_sample_payload();
		$payload['post_data']['post_status'] = 'publish';
		$payload['post_data']['title']       = 'Edited By Origin';
		$payload['post_data']['date_gmt']    = '2021-01-01 00:00:00';
		$this->incoming_post->insert( $payload );

		// Node schedule must be preserved (status + both date fields coherent).
		$this->assertSame( 'future', get_post_status( $post_id ), 'Node post must stay scheduled.' );
		$this->assertSame( $future_gmt, get_post_field( 'post_date_gmt', $post_id ), 'Node GMT date must be preserved.' );
		$this->assertSame( $future_gmt, get_post_field( 'post_date', $post_id ), 'Node local date must be preserved.' );

		// Content still flows through.
		$this->assertSame( 'Edited By Origin', get_the_title( $post_id ) );
	}

	/**
	 * NPPM-2871: The future-schedule guard is scoped to incoming `publish`.
	 * An origin-side removal (`trash`) must still propagate to a locally
	 * scheduled node post — a retracted story must not stay scheduled.
	 */
	public function test_origin_trash_still_propagates_to_scheduled_node() {
		$post_id = $this->incoming_post->insert();

		// Node editor reschedules locally to a future date.
		$future_gmt = '2099-12-31 10:16:00';
		wp_update_post(
			[
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => $future_gmt,
				'post_date_gmt' => $future_gmt,
			]
		);
		$this->assertSame( 'future', get_post_status( $post_id ) );

		// Origin trashes the story.
		$payload = $this->get_sample_payload();
		$payload['post_data']['post_status'] = 'trash';
		$this->incoming_post->insert( $payload );

		// Removal propagates despite the local schedule.
		$this->assertSame( 'trash', get_post_status( $post_id ) );
	}

	/**
	 * NPPM-2871: An origin-side unpublish to `draft` must also reach a locally
	 * scheduled node post. `draft` takes a different WP path than `trash`
	 * (a `future`→`draft` transition through `wp_update_post`), so cover it
	 * explicitly to lock the guard's `publish`-only scope.
	 */
	public function test_origin_draft_unpublish_propagates_to_scheduled_node() {
		$post_id = $this->incoming_post->insert();

		// Node editor reschedules locally to a future date.
		$future_gmt = '2099-12-31 10:16:00';
		wp_update_post(
			[
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => $future_gmt,
				'post_date_gmt' => $future_gmt,
			]
		);
		$this->assertSame( 'future', get_post_status( $post_id ) );

		// Origin unpublishes the story back to draft.
		$payload = $this->get_sample_payload();
		$payload['post_data']['post_status'] = 'draft';
		$this->incoming_post->insert( $payload );

		// Unpublish propagates despite the local schedule.
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * Test delete.
	 */
	public function test_delete() {
		$post_id = $this->incoming_post->insert();

		$this->incoming_post->delete();

		// Assert that the post was trashed and the payload was removed.
		$this->assertSame( 'trash', get_post_status( $post_id ) );
		$this->assertEmpty( get_post_meta( $post_id, Incoming_Post::PAYLOAD_META, true ) );
	}

	/**
	 * Test delete trashed post.
	 */
	public function test_delete_trashed_post() {
		$post_id = $this->incoming_post->insert();

		wp_trash_post( $post_id );

		$this->incoming_post->delete();

		// Assert that the post remained trashed and the payload was removed.
		$this->assertSame( 'trash', get_post_status( $post_id ) );
		$this->assertEmpty( get_post_meta( $post_id, Incoming_Post::PAYLOAD_META, true ) );
	}

	/**
	 * Test delete unlinked.
	 */
	public function test_delete_unlinked() {
		$post_id = $this->incoming_post->insert();

		$this->assertNotEmpty( get_post( $post_id ) );

		$this->incoming_post->set_unlinked();
		$this->incoming_post->delete();

		// Assert that the post remained as draft and the payload was removed.
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertEmpty( get_post_meta( $post_id, Incoming_Post::PAYLOAD_META, true ) );
	}

	/**
	 * Test ignored taxonomies.
	 */
	public function test_ignored_taxonomies() {
		$payload = $this->get_sample_payload();
		$taxonomy = 'author';

		// Register an ignored taxonomy.
		register_taxonomy( $taxonomy, 'post', [ 'public' => true ] );

		$payload['post_data']['taxonomy']['author'] = [
			[
				'name' => 'Author 1',
				'slug' => 'author-1',
			],
		];

		// Insert the linked post.
		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post does not have the ignored taxonomy term.
		$terms = wp_get_post_terms( $post_id, $taxonomy );
		$this->assertEmpty( $terms );
	}

	/**
	 * Test comment and ping statuses.
	 */
	public function test_comment_and_ping_statuses() {
		$payload = $this->get_sample_payload();

		// Insert the linked post with comment and ping statuses.
		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post has the comment and ping statuses.
		$this->assertSame( 'open', get_post_field( 'comment_status', $post_id ) );
		$this->assertSame( 'open', get_post_field( 'ping_status', $post_id ) );

		// Update the comment and ping statuses.
		$payload['post_data']['comment_status'] = 'closed';
		$payload['post_data']['ping_status'] = 'closed';

		// Insert the updated linked post.
		$this->incoming_post->insert( $payload );

		// Assert that the post has the updated comment and ping statuses.
		$this->assertSame( 'closed', get_post_field( 'comment_status', $post_id ) );
		$this->assertSame( 'closed', get_post_field( 'ping_status', $post_id ) );
	}

	/**
	 * Test distributing draft post.
	 */
	public function test_draft_distribution() {
		$payload = $this->get_sample_payload();

		$payload['post_data']['post_status'] = 'draft';
		$payload['status_on_publish'] = 'pending';

		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post is draft.
		$this->assertSame( 'draft', get_post_status( $post_id ) );

		// Insert 'publish' status.
		$payload['post_data']['post_status'] = 'publish';
		$this->incoming_post->insert( $payload );

		// Assert that the post is published.
		$this->assertSame( 'pending', get_post_status( $post_id ) );
	}

	/**
	 * Test distributing "pending" status post.
	 */
	public function test_pending_distribution() {
		$payload = $this->get_sample_payload();

		$payload['post_data']['post_status'] = 'pending';
		$payload['status_on_publish'] = 'publish';

		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post is pending.
		$this->assertSame( 'pending', get_post_status( $post_id ) );

		// Insert 'publish' status.
		$payload['post_data']['post_status'] = 'publish';
		$this->incoming_post->insert( $payload );

		// Assert that the post is published.
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * Test that a scheduled (future) hub post does not auto-publish on the node
	 * when status_on_publish is set to a non-publish status.
	 *
	 * Regression test for: hub distributes a draft, then schedules it shortly
	 * after. The scheduling sync sends post_status='future' to the node. If the
	 * node applies that status directly, WordPress cron will find a future-status
	 * post and auto-publish it via wp_publish_post(), bypassing the
	 * status_on_publish setting entirely.
	 *
	 * The node should keep the post in the status_on_publish state, such as draft,
	 * so that WP cron never has a chance to publish it.
	 */
	public function test_future_status_with_non_publish_status_on_publish() {
		$payload = $this->get_sample_payload();

		// Simulate Event 1: hub distributes the post as a draft.
		// status_on_publish='draft' means the node should never auto-publish.
		$payload['post_data']['post_status'] = 'draft';
		$payload['status_on_publish']        = 'draft';

		$post_id = $this->incoming_post->insert( $payload );
		$this->assertSame( 'draft', get_post_status( $post_id ) );

		// Simulate Event 2: hub schedules the post, syncing post_status='future'.
		// The node must not apply 'future', as a future-status post with a past date
		// is auto-published by WP cron, bypassing status_on_publish entirely.
		$payload['post_data']['post_status'] = 'future';
		$this->incoming_post->insert( $payload );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * Test that a scheduled (future) hub post mirrors the future status on the
	 * node when status_on_publish is 'publish'.
	 *
	 * When a publisher wants the node to publish in sync with the hub, the node
	 * should mirror the 'future' status so that WP cron fires on both sites at
	 * the same scheduled time.
	 */
	public function test_future_status_with_publish_status_on_publish() {
		$payload = $this->get_sample_payload();

		$payload['post_data']['post_status'] = 'draft';
		$payload['status_on_publish']        = 'publish';

		$post_id = $this->incoming_post->insert( $payload );

		// Hub schedules the post. date_gmt must be in the future or WordPress
		// will immediately publish the post rather than storing it as 'future'.
		$payload['post_data']['post_status'] = 'future';
		$payload['post_data']['date_gmt']    = gmdate( 'Y-m-d H:i:s', strtotime( '+1 week' ) );
		$this->incoming_post->insert( $payload );

		// Node should mirror the hub's scheduled status so both publish together.
		$this->assertSame( 'future', get_post_status( $post_id ) );
	}

	/**
	 * Test that a scheduled (future) hub post mirrors the future status on the
	 * node when status_on_publish is absent from the payload.
	 *
	 * The sample payload includes status_on_publish by default, so it is
	 * explicitly unset here to simulate a payload that omits the key.
	 * The node should fall back to mirroring the hub's future status.
	 */
	public function test_future_status_with_unset_status_on_publish() {
		$payload = $this->get_sample_payload();

		// Remove status_on_publish to simulate a payload that omits the key.
		unset( $payload['status_on_publish'] );

		$payload['post_data']['post_status'] = 'draft';
		$post_id                             = $this->incoming_post->insert( $payload );

		// Hub schedules the post. date_gmt must be in the future or WordPress
		// will immediately publish the post rather than storing it as 'future'.
		$payload['post_data']['post_status'] = 'future';
		$payload['post_data']['date_gmt']    = gmdate( 'Y-m-d H:i:s', strtotime( '+1 week' ) );
		$this->incoming_post->insert( $payload );

		// With no status_on_publish set, the node should mirror the hub's schedule.
		$this->assertSame( 'future', get_post_status( $post_id ) );
	}

	/**
	 * Test that "status on publish" only applies once.
	 */
	public function test_status_on_publish_only_applies_once() {
		$payload = $this->get_sample_payload();

		$payload['post_data']['post_status'] = 'draft';
		$payload['status_on_publish'] = 'publish';

		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the "status on publish" is stored in the post meta.
		$this->assertSame( 'publish', get_post_meta( $post_id, Incoming_Post::STATUS_ON_PUBLISH_META, true ) );

		// Insert 'publish' status.
		$payload['post_data']['post_status'] = 'publish';
		$this->incoming_post->insert( $payload );

		// Assert that the "status on publish" meta is gone.
		$this->assertEmpty( get_post_meta( $post_id, Incoming_Post::STATUS_ON_PUBLISH_META, true ) );

		// Assert that the post is published.
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		// Move post back to draft and reinsert the payload.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);
		$this->incoming_post->insert( $payload );

		// Assert that the post is still draft.
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * Test status on publish.
	 */
	public function test_status_on_publish() {
		$payload = $this->get_sample_payload();

		$payload['status_on_publish'] = 'publish';

		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post is published.
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		// Place the post back to draft.
		wp_update_post(
			[
				'ID'          => $post_id,
				'post_status' => 'draft',
			]
		);

		// Assert that distributing again will not publish it.
		$this->incoming_post->insert( $payload );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
	}

	/**
	 * Test partial post payload on insert.
	 */
	public function test_partial_payload_insert() {
		$post_id = $this->incoming_post->insert();

		// Make the payload a partial.
		$payload              = $this->get_sample_payload();
		$payload['partial']   = true;
		$payload['post_data'] = [
			'title'        => 'Updated Title',
			'date_gmt'     => $payload['post_data']['date_gmt'],
			'modified_gmt' => $payload['post_data']['modified_gmt'],
		];

		$this->incoming_post->insert( $payload );

		// Assert that the post title was updated and the content was not.
		$this->assertSame( 'Updated Title', get_the_title( $post_id ) );
		$this->assertSame( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->', get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Test partial post payload on instantiation.
	 */
	public function test_partial_payload_instantiation() {
		$post_id = $this->incoming_post->insert();

		// Make the payload a partial.
		$payload              = $this->get_sample_payload();
		$payload['partial']   = true;
		$payload['post_data'] = [
			'title'        => 'Updated Title',
			'date_gmt'     => $payload['post_data']['date_gmt'],
			'modified_gmt' => $payload['post_data']['modified_gmt'],
		];

		$incoming_post = new Incoming_Post( $payload );
		$incoming_post->insert();

		// Assert that the post title was updated and the content was not.
		$this->assertSame( 'Updated Title', get_the_title( $post_id ) );
		$this->assertSame( '<!-- wp:paragraph --><p>Content</p><!-- /wp:paragraph -->', get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Test partial payload on missing post.
	 */
	public function test_partial_payload_missing_post() {
		$payload = $this->get_sample_payload();

		// Make the payload a partial.
		$payload['partial']   = true;
		$payload['post_data'] = [
			'title'        => 'Updated Title',
			'date_gmt'     => $payload['post_data']['date_gmt'],
			'modified_gmt' => $payload['post_data']['modified_gmt'],
		];

		// Assert that instantiating a partial payload will throw an exception.
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Partial payload requires an existing post.' );
		new Incoming_Post( $payload );
	}

	/**
	 * Test existing terms only taxonomies.
	 */
	public function test_existing_terms_only_taxonomies() {
		$payload = $this->get_sample_payload();
		$taxonomy = 'existing_terms_only_tax';

		// Register a taxonomy that should only use existing terms.
		register_taxonomy( $taxonomy, 'post', [ 'public' => true ] );

		// Add the taxonomy to the existing terms only list.
		add_filter(
			'newspack_network_content_distribution_existing_terms_only_taxonomies',
			function( $taxonomies ) use ( $taxonomy ) {
				$taxonomies[] = $taxonomy;
				return $taxonomies;
			}
		);

		// Add a non-existent term to the payload.
		$payload['post_data']['taxonomy'][ $taxonomy ] = [
			[
				'name' => 'Non-existent Term',
				'slug' => 'non-existent-term',
			],
		];

		// Insert the linked post.
		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post does not have the term since it doesn't exist.
		$terms = wp_get_post_terms( $post_id, $taxonomy );
		$this->assertEmpty( $terms );

		// Now create the term and try again.
		$term = wp_insert_term( 'Non-existent Term', $taxonomy );
		$this->assertFalse( is_wp_error( $term ) );

		// Insert the linked post again.
		$this->incoming_post->insert( $payload );

		// Assert that the post now has the term since it exists.
		$terms = wp_get_post_terms( $post_id, $taxonomy );
		$this->assertNotEmpty( $terms );
		$this->assertSame( 'Non-existent Term', $terms[0]->name );
	}

	/**
	 * Test updating the post modified date.
	 */
	public function test_update_post_modified_date() {

		$payload_old_dates = $this->get_sample_payload();

		$payload_old_dates['post_data']['modified_gmt'] = '2020-01-01 00:00:00';
		$payload_old_dates['post_data']['date_gmt'] = '2020-01-01 00:00:00';

		$incoming_post = new Incoming_Post( $payload_old_dates );

		$post_id = $incoming_post->insert();

		$this->assertSame( '2020-01-01 00:00:00', get_post_field( 'post_modified_gmt', $post_id ) );
		$this->assertSame( '2020-01-01 00:00:00', get_post_field( 'post_date_gmt', $post_id ) );

		// Modify the post payload to simulate an update.
		$payload_old_dates['post_data']['title'] = 'New Title';
		$payload_old_dates['post_data']['modified_gmt'] = '2020-10-01 00:00:00';

		// Insert the updated linked post.
		$post_id = $this->incoming_post->insert( $payload_old_dates );

		$this->assertSame( 'New Title', get_the_title( $post_id ) );
		$this->assertSame( '2020-10-01 00:00:00', get_post_field( 'post_modified_gmt', $post_id ) );
	}

	/**
	 * Test toggling Jetpack Photon.
	 */
	public function test_toggle_jetpack_photon() {
		$payload = $this->get_sample_payload();

		$payload['post_data']['thumbnail_url'] = 'https://i0.wp.com/newspack.com/wp-content/uploads/2025/02/newspack-logo.png?fit=948%2C192&ssl=1';

		$post_id = $this->incoming_post->insert( $payload );

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		$this->assertNotEmpty( $thumbnail_id );

		// Update the payload to use the same image without Photon.
		$payload['post_data']['thumbnail_url'] = 'https://newspack.com/wp-content/uploads/2025/02/newspack-logo.png';
		$this->incoming_post->insert( $payload );

		// Assert that the thumbnail is unchanged.
		$this->assertSame( $thumbnail_id, get_post_thumbnail_id( $post_id ) );
	}

	/**
	 * Test removing all terms from a taxonomy with empty array.
	 */
	public function test_remove_all_terms_with_empty_array() {
		$payload = $this->get_sample_payload();

		// Insert the linked post with categories.
		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post has categories.
		$terms = wp_get_post_terms( $post_id, 'category' );
		$this->assertNotEmpty( $terms );
		$this->assertCount( 2, $terms );

		// Update the payload to have an empty array for categories.
		$payload['post_data']['taxonomy']['category'] = [];

		// Insert the updated linked post.
		$this->incoming_post->insert( $payload );

		// Assert that all categories have been removed.
		$terms = wp_get_post_terms( $post_id, 'category' );
		$this->assertEmpty( $terms );

		// Assert that tags are still present.
		$tags = wp_get_post_terms( $post_id, 'post_tag' );
		$this->assertNotEmpty( $tags );
		$this->assertCount( 2, $tags );
	}

	/**
	 * Test removing all terms from multiple taxonomies with empty arrays.
	 */
	public function test_remove_all_terms_from_multiple_taxonomies() {
		$payload = $this->get_sample_payload();

		// Insert the linked post with categories and tags.
		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post has categories and tags.
		$categories = wp_get_post_terms( $post_id, 'category' );
		$tags = wp_get_post_terms( $post_id, 'post_tag' );
		$this->assertNotEmpty( $categories );
		$this->assertNotEmpty( $tags );

		// Update the payload to have empty arrays for both taxonomies.
		$payload['post_data']['taxonomy']['category'] = [];
		$payload['post_data']['taxonomy']['post_tag'] = [];

		// Insert the updated linked post.
		$this->incoming_post->insert( $payload );

		// Assert that all categories and tags have been removed.
		$categories = wp_get_post_terms( $post_id, 'category' );
		$tags = wp_get_post_terms( $post_id, 'post_tag' );
		$this->assertEmpty( $categories );
		$this->assertEmpty( $tags );
	}

	/**
	 * Test empty taxonomy array creates post without terms.
	 */
	public function test_insert_post_with_empty_taxonomy_array() {
		$payload = $this->get_sample_payload();

		// Set taxonomies to empty arrays before inserting.
		$payload['post_data']['taxonomy']['category'] = [];
		$payload['post_data']['taxonomy']['post_tag'] = [];

		// Insert the linked post.
		$post_id = $this->incoming_post->insert( $payload );

		// Assert that the post was created without any terms.
		$categories = wp_get_post_terms( $post_id, 'category' );
		$tags = wp_get_post_terms( $post_id, 'post_tag' );
		$this->assertEmpty( $categories );
		$this->assertEmpty( $tags );
	}
}
