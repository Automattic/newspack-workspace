<?php
/**
 * Class TestApi
 *
 * @package Newspack_Network
 */

namespace Test\Content_Distribution;

require_once __DIR__ . '/mock-data-events.php';

use Newspack\Data_Events;
use Newspack_Network\Content_Distribution as Content_Distribution_Class;
use Newspack_Network\Content_Distribution\Admin;
use Newspack_Network\Content_Distribution\API;
use Newspack_Network\Content_Distribution\Incoming_Post;
use Newspack_Network\Content_Distribution\Outgoing_Post;
use Newspack_Network\Hub\Node as Hub_Node;
use WP_REST_Request;

/**
 * Test the content-distribution REST API.
 *
 * @group content-distribution-api
 */
class TestApi extends \WP_UnitTestCase {
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
	];

	/**
	 * The 'distribute' route's permission callback.
	 *
	 * @var callable
	 */
	private $permission_callback;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Hub_Node::HUB_NODES_SYNCED_OPTION, $this->network );
		Data_Events::$mock_dispatch_return = true;

		// Clear any existing routes.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();

		API::register_routes();

		$routes = rest_get_server()->get_routes();
		$route  = $routes['/newspack-network/v1/content-distribution/distribute/(?P<post_id>\d+)'][0];

		$this->permission_callback = $route['permission_callback'];
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		Data_Events::$mock_dispatch_return = true;
		// Discard the server this class registered its routes on, so no later test inherits it.
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Build a distributable post and a distribute request for it.
	 *
	 * @return array The post ID and the WP_REST_Request.
	 */
	private function make_distribute_request() {
		$author  = $this->factory->user->create( [ 'role' => 'editor' ] );
		$post_id = $this->factory->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_author' => $author,
			]
		);

		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/content-distribution/distribute/' . $post_id );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'urls', [ $this->network[0]['url'] ] );
		$request->set_param( 'status_on_publish', 'draft' );

		return [ $post_id, $request ];
	}

	/**
	 * Build a bare distribute request for the given post ID.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return WP_REST_Request
	 */
	private function make_request( $post_id ) {
		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/content-distribution/distribute/' . $post_id );
		$request->set_param( 'post_id', $post_id );

		return $request;
	}

	/**
	 * An author cannot distribute a post authored by another user, even
	 * though the 'author' role is granted the distribute capability by
	 * default; the capability check alone doesn't guard against posting
	 * someone else's post ID.
	 */
	public function test_author_cannot_distribute_others_post() {
		$author       = $this->factory->user->create( [ 'role' => 'author' ] );
		$other_author = $this->factory->user->create( [ 'role' => 'author' ] );
		$post         = $this->factory->post->create( [ 'post_author' => $other_author ] );

		wp_set_current_user( $author );

		$this->assertFalse( ( $this->permission_callback )( $this->make_request( $post ) ) );
	}

	/**
	 * An author can distribute their own post.
	 */
	public function test_author_can_distribute_own_post() {
		$author = $this->factory->user->create( [ 'role' => 'author' ] );
		$post   = $this->factory->post->create( [ 'post_author' => $author ] );

		wp_set_current_user( $author );

		$this->assertTrue( ( $this->permission_callback )( $this->make_request( $post ) ) );
	}

	/**
	 * The other half of the '&&': edit rights on the post are not enough on
	 * their own, the distribute capability is still required.
	 */
	public function test_edit_rights_without_capability_cannot_distribute() {
		$author = $this->factory->user->create( [ 'role' => 'author' ] );
		$post   = $this->factory->post->create( [ 'post_author' => $author ] );

		// A user-level deny overrides the role grant.
		get_userdata( $author )->add_cap( Admin::CAPABILITY, false );

		wp_set_current_user( $author );

		$this->assertTrue( current_user_can( 'edit_post', $post ) );
		$this->assertFalse( ( $this->permission_callback )( $this->make_request( $post ) ) );
	}

	/**
	 * A post ID that resolves to no post is refused, so the handler is never
	 * reached with nothing to distribute.
	 */
	public function test_missing_post_cannot_be_distributed() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertFalse( ( $this->permission_callback )( $this->make_request( 0 ) ) );
		$this->assertFalse( ( $this->permission_callback )( $this->make_request( PHP_INT_MAX ) ) );
	}

	/**
	 * The UI hides distribution for syndicated copies; the route must refuse
	 * them too, or a direct request would give the copy a second lineage.
	 */
	public function test_incoming_post_cannot_be_distributed() {
		$post = $this->factory->post->create();
		update_post_meta( $post, Incoming_Post::PAYLOAD_META, [ 'post_id' => 1 ] );

		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$request = $this->make_request( $post );
		$request->set_param( 'urls', [ 'https://node.test' ] );

		$response = API::distribute( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'A post received from the network cannot be distributed.', $response->get_error_message() );
	}

	/**
	 * The handler refuses a missing post on its own rather than leaning on the
	 * permission callback having returned do_not_allow first.
	 */
	public function test_distribute_returns_404_for_a_missing_post() {
		$request = $this->make_request( PHP_INT_MAX );
		$request->set_param( 'urls', [ $this->network[0]['url'] ] );
		$request->set_param( 'status_on_publish', 'draft' );

		$result = API::distribute( $request );

		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * A failed dispatch must be surfaced as an error, not a 200 response.
	 */
	public function test_distribute_returns_error_when_dispatch_fails() {
		Data_Events::$mock_dispatch_return = new \WP_Error(
			'newspack_data_events_action_not_registered',
			'Action not registered.'
		);

		list( , $request ) = $this->make_distribute_request();
		$result            = API::distribute( $request );

		$this->assertWPError(
			$result,
			'distribute() must return a WP_Error when Data_Events::dispatch() fails.'
		);
		$this->assertSame(
			500,
			$result->get_error_data()['status'],
			'A failed dispatch is a server-side condition and must return HTTP 500.'
		);
	}

	/**
	 * A failed dispatch must not write the payload hash, and must leave the destination
	 * recorded in distribution meta, so the next post update retries distribution.
	 */
	public function test_distribute_does_not_store_payload_hash_when_dispatch_fails() {
		Data_Events::$mock_dispatch_return = new \WP_Error(
			'newspack_data_events_action_not_registered',
			'Action not registered.'
		);

		list( $post_id, $request ) = $this->make_distribute_request();
		API::distribute( $request );

		$this->assertEmpty(
			get_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, true ),
			'The payload hash must not be stored when dispatch fails.'
		);
		$this->assertNotEmpty(
			get_post_meta( $post_id, Outgoing_Post::DISTRIBUTED_POST_META, true ),
			'The destination must stay recorded so the next save retries distribution.'
		);
	}

	/**
	 * The happy path is unaffected: a successful dispatch stores the payload hash.
	 */
	public function test_distribute_stores_payload_hash_on_success() {
		Data_Events::$mock_dispatch_return = null; // Real dispatch() returns void on success.

		list( $post_id, $request ) = $this->make_distribute_request();
		$result                    = API::distribute( $request );

		$this->assertNotWPError( $result, 'distribute() must succeed when dispatch succeeds.' );
		$this->assertNotEmpty(
			get_post_meta( $post_id, Content_Distribution_Class::PAYLOAD_HASH_META, true ),
			'The payload hash must be stored on a successful dispatch.'
		);
	}

	/**
	 * Build an /insert request carrying the given content.
	 *
	 * @param string $content     The rendered content.
	 * @param string $raw_content The block-serialized content.
	 *
	 * @return WP_REST_Request
	 */
	private function make_insert_request( $content, $raw_content = 'plain text, no blocks' ) {
		$payload = get_sample_payload( 'https://origin.test', get_bloginfo( 'url' ) );

		$payload['post_data']['content']       = $content;
		$payload['post_data']['raw_content']   = $raw_content;
		$payload['post_data']['thumbnail_url'] = ''; // Avoid a sideload attempt in tests.

		$request = new WP_REST_Request( 'POST', '/newspack-network/v1/content-distribution/insert' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'payload' => $payload ] ) );

		return $request;
	}

	/**
	 * Dispatch an /insert request and return the stored post content.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return string The stored post_content.
	 */
	private function insert_and_get_content( $request ) {
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), 'The insert request should succeed.' );

		return get_post_field( 'post_content', $response->get_data()['post_id'] );
	}

	/**
	 * The 'author' role holds the distribute capability but not
	 * 'unfiltered_html', so content arriving through /insert must be filtered
	 * the same way it would be if that user wrote the post by hand.
	 */
	public function test_insert_strips_unsafe_html_for_author() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );

		$content = $this->insert_and_get_content(
			$this->make_insert_request( '<script>alert(1)</script>hello' )
		);

		$this->assertStringNotContainsString(
			'<script>',
			$content,
			'An author must not be able to store a raw script tag through /insert.'
		);
		$this->assertStringContainsString( 'hello', $content, 'The safe part of the content must survive.' );
	}

	/**
	 * An editor holds 'unfiltered_html', so their content is stored as-is.
	 * This is what keeps Story Budget pulls working for editors and above.
	 */
	public function test_insert_preserves_unsafe_html_for_editor() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );

		// The whole test rests on the editor holding unfiltered_html, which is a
		// single-site fact: multisite and DISALLOW_UNFILTERED_HTML deny it to
		// everyone below super admin. Skip there rather than fail — the fix is
		// behaving correctly on those installs, it just filters everyone.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$this->markTestSkipped( 'This install denies unfiltered_html to editors; the route filters every role there.' );
		}

		$content = $this->insert_and_get_content(
			$this->make_insert_request( '<script>alert(1)</script>hello' )
		);

		$this->assertStringContainsString(
			'<script>',
			$content,
			'An editor holds unfiltered_html, so their content must not be filtered.'
		);
	}

	/**
	 * Filtering must not disturb ordinary block markup: block delimiters are
	 * HTML comments, which kses preserves.
	 */
	public function test_insert_preserves_ordinary_block_markup_for_author() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );

		$blocks = '<!-- wp:paragraph --><p>Hello <strong>world</strong></p><!-- /wp:paragraph -->';

		$content = $this->insert_and_get_content(
			$this->make_insert_request( '<p>Hello <strong>world</strong></p>', $blocks )
		);

		$this->assertStringContainsString( 'wp:paragraph', $content, 'Block delimiters must survive filtering.' );
		$this->assertStringContainsString( '<strong>world</strong>', $content, 'Inline markup must survive filtering.' );
	}

	/**
	 * The scoping guard. Distribution between sites arrives as a Data Event,
	 * not through /insert, and carries network credentials. That path must
	 * keep storing content verbatim, or every network loses its embeds.
	 *
	 * If this test fails, the filtering has been pushed down into
	 * Incoming_Post::insert() and is no longer scoped to the REST route.
	 */
	public function test_event_path_content_is_not_filtered() {
		$payload = get_sample_payload( 'https://origin.test', get_bloginfo( 'url' ) );

		$payload['post_data']['content']       = '<script>alert(1)</script>hello';
		$payload['post_data']['raw_content']   = 'plain text, no blocks';
		$payload['post_data']['thumbnail_url'] = '';

		$incoming_post = new Incoming_Post( $payload );
		$post_id       = $incoming_post->insert();

		$this->assertNotWPError( $post_id, 'The event path insert should succeed.' );
		$this->assertStringContainsString(
			'<script>',
			get_post_field( 'post_content', $post_id ),
			'Content arriving on the event path must not be filtered.'
		);
	}

	/**
	 * Inserting disables kses globally for the rest of the request. It must put
	 * the filters back, or an unrelated save later in the same request stores
	 * unfiltered content. Nothing in the current after-save chain writes a post,
	 * so this guards the next thing that does.
	 */
	public function test_insert_restores_content_filters() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );
		kses_init();

		$this->assertNotFalse(
			has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
			'Precondition: kses must be filtering content for this user before the insert.'
		);

		$this->insert_and_get_content( $this->make_insert_request( 'safe content' ) );

		// Assert the priority, not just presence: the restore loop's whole job is
		// reproducing priority and accepted_args, and a presence-only check passes
		// even if everything came back at priority 10.
		$this->assertSame(
			10,
			has_filter( 'content_save_pre', 'wp_filter_post_kses' ),
			'The insert must restore kses at its original priority.'
		);
		$this->assertSame(
			9,
			has_filter( 'content_save_pre', 'wp_filter_global_styles_post' ),
			'The insert must restore the other content_save_pre callbacks too, not just kses.'
		);
		if ( function_exists( 'wp_strip_custom_css_from_blocks' ) ) {
			$this->assertSame(
				8,
				has_filter( 'content_save_pre', 'wp_strip_custom_css_from_blocks' ),
				'Priority 8 is the custom-CSS sink this fix also covers, so pin it explicitly.'
			);
		}

		$later_post = wp_insert_post(
			[
				'post_title'   => 'Saved after the insert',
				'post_content' => '<script>alert(1)</script>hello',
				'post_status'  => 'draft',
			]
		);

		$this->assertStringNotContainsString(
			'<script>',
			get_post_field( 'post_content', $later_post ),
			'A later save in the same request must still be filtered.'
		);
	}

	/**
	 * The cost of filtering, pinned deliberately rather than left to be
	 * discovered. An author pulling a story that carries a Custom HTML block
	 * gets the block's body dropped, because `iframe` is not in the kses
	 * post allowlist. The block delimiter survives as an empty shell, with no
	 * notice in the response.
	 *
	 * An author cannot hand-write an iframe either, so this matches what they
	 * would get writing the post themselves. Editors and above keep the embed
	 * (see test_insert_preserves_unsafe_html_for_editor).
	 */
	public function test_insert_drops_custom_html_block_body_for_author() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );

		$embed = '<!-- wp:html --><iframe src="https://example.test/chart/1/"></iframe><!-- /wp:html -->';

		$content = $this->insert_and_get_content( $this->make_insert_request( '<p>Story</p>', $embed ) );

		$this->assertStringNotContainsString( '<iframe', $content, 'kses drops iframes; the embed body does not survive.' );
		$this->assertStringContainsString( 'wp:html', $content, 'The block delimiter itself survives, so the loss is silent.' );
	}

	/**
	 * Block custom CSS is the second sink inside post content. Core strips it on
	 * a normal save for anyone without edit_css, which maps to unfiltered_html,
	 * so a caller who cannot add it by hand must not get it through this route.
	 */
	public function test_insert_strips_block_custom_css_for_author() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );

		// className carries \u002d escapes, so a surviving attribute forces the
		// re-encode branch of wp_strip_custom_css_from_blocks() and pins the
		// wp_slash/wp_unslash round trip. Without it both assertions below pass
		// even with that wrapping removed.
		$attrs  = serialize_block_attributes(
			[
				'className' => 'a--b',
				'style'     => [ 'css' => 'body{display:none}' ],
			]
		);
		$blocks = '<!-- wp:paragraph ' . $attrs . ' --><p>Story</p><!-- /wp:paragraph -->';

		$content = $this->insert_and_get_content( $this->make_insert_request( '<p>Story</p>', $blocks ) );

		$this->assertStringNotContainsString( '"css"', $content, 'An author must not be able to store block custom CSS.' );
		$this->assertStringContainsString( 'a\u002d\u002db', $content, 'Escaped attribute JSON must survive the slash round trip intact.' );
		$this->assertStringContainsString( '<p>Story</p>', $content, 'The block body itself must survive.' );
	}

	/**
	 * The other side of it: an editor holds unfiltered_html, so their custom CSS
	 * is stored, exactly as it would be on a normal save.
	 */
	public function test_insert_preserves_block_custom_css_for_editor() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'editor' ] ) );

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$this->markTestSkipped( 'This install denies unfiltered_html to editors; the route filters every role there.' );
		}

		$attrs  = serialize_block_attributes(
			[
				'className' => 'a--b',
				'style'     => [ 'css' => 'body{display:none}' ],
			]
		);
		$blocks = '<!-- wp:paragraph ' . $attrs . ' --><p>Story</p><!-- /wp:paragraph -->';

		$content = $this->insert_and_get_content( $this->make_insert_request( '<p>Story</p>', $blocks ) );

		$this->assertStringContainsString( '"css"', $content, 'An editor holds unfiltered_html, so their custom CSS is kept.' );
	}

	/**
	 * The strip is ours rather than core's, so its recursion needs pinning: custom
	 * CSS on a nested block must go too, not just on a top-level one.
	 */
	public function test_insert_strips_block_custom_css_from_nested_blocks_for_author() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );

		$attrs  = serialize_block_attributes( [ 'style' => [ 'css' => 'body{display:none}' ] ] );
		$blocks = '<!-- wp:group --><div><!-- wp:paragraph ' . $attrs . ' --><p>Inner</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

		$content = $this->insert_and_get_content( $this->make_insert_request( '<p>Inner</p>', $blocks ) );

		$this->assertStringNotContainsString( '"css"', $content, 'Custom CSS on a nested block must be stripped too.' );
		$this->assertStringContainsString( 'wp:group', $content, 'The surrounding block structure must survive.' );
		$this->assertStringContainsString( '<p>Inner</p>', $content, 'The inner block body must survive.' );
	}

	/**
	 * The strip is ours rather than core's, so the claim that it behaves like
	 * core's needs a test rather than an assertion. Core returns the content
	 * untouched when no block carries custom CSS; a naive round trip would
	 * normalize attribute JSON the sender wrote by hand.
	 *
	 * Scope of the claim, because two of these cases do strip and still assert
	 * equality: they agree because their inputs are canonical single-attribute
	 * blocks, not because equality holds after a strip in general. It does not.
	 * Core splices only the attribute it changed while this re-serializes the
	 * document, so a non-canonically-written sibling alongside a stripped block
	 * normalizes and the two diverge. Matching core there would mean
	 * reimplementing its token scanner for a property get_post_content() discards.
	 */
	public function test_block_custom_css_strip_matches_core() {
		if ( ! function_exists( 'wp_strip_custom_css_from_blocks' ) ) {
			$this->markTestSkipped( 'Core comparison requires WordPress 7.0.' );
		}

		$css = serialize_block_attributes( [ 'style' => [ 'css' => 'body{display:none}' ] ] );

		$cases = [
			'non-canonical attributes, no custom CSS' => '<!-- wp:paragraph {"align": "left"} --><p>Hi</p><!-- /wp:paragraph -->',
			'canonical attributes, no custom CSS'     => '<!-- wp:paragraph {"align":"left"} --><p>Hi</p><!-- /wp:paragraph -->',
			'no attributes at all'                    => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
			'not block content'                       => '<p>plain <a href="https://example.test?a=1&b=2">link</a></p>',
			'custom CSS present'                      => '<!-- wp:paragraph ' . $css . ' --><p>Hi</p><!-- /wp:paragraph -->',
			'custom CSS nested'                       => '<!-- wp:group --><div><!-- wp:paragraph ' . $css . ' --><p>In</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
		];

		$method = new \ReflectionMethod( API::class, 'strip_block_custom_css' );

		foreach ( $cases as $label => $content ) {
			$this->assertSame(
				wp_unslash( wp_strip_custom_css_from_blocks( wp_slash( $content ) ) ),
				$method->invoke( null, $content ),
				"Our strip must match core's output: $label."
			);
		}
	}

	/**
	 * The widest claim this change makes is that the cleanup is not an author-only
	 * concern: multisite, and any site defining DISALLOW_UNFILTERED_HTML, denies
	 * unfiltered_html to everyone below super admin, so an administrator pulling a
	 * story loses the same markup an author would.
	 *
	 * The two editor tests skip in that configuration rather than covering it, so
	 * this forces the denial through map_meta_cap and asserts the positive. Without
	 * it the claim rests on reading core rather than on anything the suite runs.
	 */
	public function test_insert_filters_for_a_caller_denied_unfiltered_html_by_the_install() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertTrue(
			current_user_can( 'unfiltered_html' ),
			'Precondition: an administrator holds unfiltered_html before the install denies it.'
		);

		// What multisite and DISALLOW_UNFILTERED_HTML both do, via the same filter
		// core routes them through.
		add_filter(
			'map_meta_cap',
			function ( $caps, $cap ) {
				return 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps;
			},
			10,
			2
		);

		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'The filter must deny the capability.' );

		$content = $this->insert_and_get_content( $this->make_insert_request( '<script>alert(1)</script>hello' ) );

		$this->assertStringNotContainsString(
			'<script>',
			$content,
			'An administrator on an install that denies unfiltered_html is filtered like anyone else.'
		);
	}

	/**
	 * Re-linking replays the stored payload through insert() with `content_save_pre`
	 * still removed and no route filtering. That is safe only because the payload
	 * persisted at insert time was already filtered.
	 *
	 * If someone ever changes the route to persist the caller's original payload,
	 * every other test still passes and this one fails, which is the point.
	 */
	public function test_relinking_replays_the_filtered_payload() {
		wp_set_current_user( $this->factory->user->create( [ 'role' => 'author' ] ) );

		$response = rest_get_server()->dispatch( $this->make_insert_request( '<script>alert(1)</script>hello' ) );
		$this->assertSame( 200, $response->get_status() );
		$post_id = $response->get_data()['post_id'];

		$incoming_post = new Incoming_Post( $post_id );
		$incoming_post->set_unlinked( true );
		$incoming_post->set_unlinked( false );

		$this->assertStringNotContainsString(
			'<script>',
			get_post_field( 'post_content', $post_id ),
			'Re-linking must not restore markup the route filtered out.'
		);
	}
}
