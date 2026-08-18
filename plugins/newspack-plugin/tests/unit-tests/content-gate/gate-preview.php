<?php
/**
 * Tests for the Content Gate Preview.
 *
 * Verifies the in-editor gate preview: capability gating, that the force-render
 * filters only fire for a valid preview request, that autosaved content and
 * URL-borne meta overrides win over the saved layout, gate-post-id resolution,
 * and that the previewing admin's metering allowance is never consumed.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;
use Newspack\Metering;
use Newspack\Content_Gate\Gate_Preview;

/**
 * Test the Gate_Preview class.
 */
class Test_Gate_Preview extends \WP_UnitTestCase {

	/**
	 * Define the Content Gates feature flag for this test class only.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		// wp_create_post_autosave() lives in the admin include, not loaded by default in tests.
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}

	/**
	 * Query params set during a test, cleared in tear_down.
	 *
	 * @var string[]
	 */
	private $preview_query_params = [];

	/**
	 * Admin user ID (can preview).
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID (cannot preview).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * A published gate layout used as the previewed layout.
	 *
	 * @var int
	 */
	private $layout_id;

	/**
	 * A published post used as the preview canvas.
	 *
	 * @var int
	 */
	private $canvas_post_id;

	/**
	 * Test set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin_id      = $this->factory->user->create( [ 'role' => 'administrator' ] );
		$this->subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );

		$this->layout_id = $this->factory->post->create(
			[
				'post_type'    => Content_Gate::GATE_LAYOUT_CPT,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>STORED_GATE_PROMPT</p><!-- /wp:paragraph -->',
			]
		);

		$this->canvas_post_id = $this->factory->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => implode(
					'',
					[
						'<!-- wp:paragraph --><p>BODY_PARAGRAPH_ONE</p><!-- /wp:paragraph -->',
						'<!-- wp:paragraph --><p>BODY_PARAGRAPH_TWO</p><!-- /wp:paragraph -->',
						'<!-- wp:paragraph --><p>BODY_PARAGRAPH_THREE</p><!-- /wp:paragraph -->',
						'<!-- wp:paragraph --><p>BODY_PARAGRAPH_FOUR</p><!-- /wp:paragraph -->',
					]
				),
			]
		);
	}

	/**
	 * Test tear down.
	 */
	public function tear_down() {
		foreach ( $this->preview_query_params as $key ) {
			unset( $_GET[ $key ] );
		}
		$this->preview_query_params = [];
		wp_set_current_user( 0 );
		$this->reset_restriction_cache();
		$this->reset_gate_render_state();
		parent::tear_down();
	}

	/**
	 * Set a preview query param, tracked for cleanup.
	 *
	 * @param string $key   Query key.
	 * @param mixed  $value Query value.
	 */
	private function set_query_param( $key, $value ) {
		$_GET[ $key ]                 = $value;
		$this->preview_query_params[] = $key;
	}

	/**
	 * Reset Content_Restriction_Control's per-request caches.
	 */
	private function reset_restriction_cache() {
		foreach ( [ 'post_gate_id_map', 'post_gate_layout_id_map', 'post_gates_map', 'term_descendants_map' ] as $prop ) {
			$reflection = new \ReflectionProperty( Content_Restriction_Control::class, $prop );
			$reflection->setAccessible( true );
			$reflection->setValue( null, [] );
		}
	}

	/**
	 * Reset Content_Gate's render-time static flags.
	 */
	private function reset_gate_render_state() {
		foreach ( [ 'gate_rendered', 'is_gated', 'is_content_locked' ] as $prop ) {
			$reflection = new \ReflectionProperty( Content_Gate::class, $prop );
			$reflection->setAccessible( true );
			$reflection->setValue( null, false );
		}
	}

	/**
	 * Capability gating: only a user who can edit others' pages, with a valid
	 * layout id in the URL, makes a preview request.
	 */
	public function test_capability_gating() {
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );

		wp_set_current_user( 0 );
		$this->assertFalse( Gate_Preview::is_preview_request(), 'Anonymous visitor is not previewing.' );

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse( Gate_Preview::is_preview_request(), 'Subscriber cannot preview.' );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( Gate_Preview::is_preview_request(), 'Admin with a valid layout id is previewing.' );
	}

	/**
	 * A ngp_id that does not point at a gate layout is rejected.
	 */
	public function test_non_layout_id_is_rejected() {
		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->canvas_post_id ); // A regular post, not a layout.

		$this->assertSame( 0, Gate_Preview::previewed_layout_id(), 'A non-layout post id resolves to 0.' );
		$this->assertFalse( Gate_Preview::is_preview_request(), 'A non-layout id is not a preview request.' );
	}

	/**
	 * Admin previewing a published post sees it force-gated: the inline gate
	 * markup is injected and the body is truncated to the visible paragraphs.
	 */
	public function test_admin_preview_gates_the_post() {
		wp_set_current_user( $this->admin_id );

		// go_to() rebuilds $_GET from the URL, mirroring a real request: the
		// preview params must ride in the query string.
		$this->go_to( add_query_arg( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id, get_permalink( $this->canvas_post_id ) ) );
		$this->preview_query_params[] = Gate_Preview::PREVIEW_QUERY_PARAM;
		$this->reset_gate_render_state();

		$post = get_post( $this->canvas_post_id );
		Content_Gate::restrict_post( $post, $GLOBALS['wp_query'] );

		$this->assertStringContainsString( 'newspack-content-gate__inline-gate', $post->post_content, 'Inline gate markup is injected.' );
		$this->assertStringContainsString( 'STORED_GATE_PROMPT', $post->post_content, 'The gate layout content is rendered.' );
		$this->assertStringContainsString( 'BODY_PARAGRAPH_ONE', $post->post_content, 'The first body paragraph stays visible.' );
		$this->assertStringNotContainsString( 'BODY_PARAGRAPH_FOUR', $post->post_content, 'The body is truncated past the visible paragraphs.' );
	}

	/**
	 * The restriction is forced only for the queried (previewed) post. A call
	 * for an unrelated post id during a preview must pass through untouched, and
	 * an empty post id resolves to the queried object.
	 */
	public function test_only_queried_post_is_force_restricted() {
		wp_set_current_user( $this->admin_id );
		$other_post_id = $this->factory->post->create( [ 'post_status' => 'publish' ] );

		$this->go_to( add_query_arg( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id, get_permalink( $this->canvas_post_id ) ) );
		$this->preview_query_params[] = Gate_Preview::PREVIEW_QUERY_PARAM;

		$this->assertTrue( Gate_Preview::filter_is_post_restricted( false, $this->canvas_post_id ), 'The queried post is force-restricted.' );
		$this->assertTrue( Gate_Preview::filter_is_post_restricted( false, 0 ), 'An empty post id resolves to the queried post and is restricted.' );
		$this->assertFalse( Gate_Preview::filter_is_post_restricted( false, $other_post_id ), 'An unrelated post id is not force-restricted.' );
	}

	/**
	 * With no ngp_id, an admin viewing the post sees full, ungated content.
	 */
	public function test_no_param_leaves_content_untouched_for_admin() {
		wp_set_current_user( $this->admin_id );

		$this->assertFalse( Gate_Preview::is_preview_request(), 'No ngp_id means no preview request.' );

		$this->go_to( get_permalink( $this->canvas_post_id ) );
		$post     = get_post( $this->canvas_post_id );
		$original = $post->post_content;
		Content_Gate::restrict_post( $post, $GLOBALS['wp_query'] );

		$this->assertSame( $original, $post->post_content, 'Content is untouched without a preview request.' );
	}

	/**
	 * A subscriber hitting the preview URL is not a preview request and gets no
	 * forced gating.
	 */
	public function test_subscriber_with_param_is_not_gated() {
		wp_set_current_user( $this->subscriber_id );

		// Carry ngp_id in the URL so go_to() (which rebuilds $_GET) keeps it — the
		// render assertion must exercise a subscriber genuinely on the preview URL.
		$this->go_to( add_query_arg( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id, get_permalink( $this->canvas_post_id ) ) );
		$this->preview_query_params[] = Gate_Preview::PREVIEW_QUERY_PARAM;
		$this->reset_gate_render_state();

		$this->assertFalse( Gate_Preview::is_preview_request(), 'Subscriber with the param is not a preview request.' );

		$post     = get_post( $this->canvas_post_id );
		$original = $post->post_content;
		Content_Gate::restrict_post( $post, $GLOBALS['wp_query'] );

		$this->assertSame( $original, $post->post_content, 'Subscriber on the preview URL does not force-gate the post.' );
	}

	/**
	 * The layout's autosaved content is rendered in place of its saved content.
	 */
	public function test_autosave_content_is_rendered() {
		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );

		wp_create_post_autosave(
			[
				'post_ID'      => $this->layout_id,
				'post_type'    => Content_Gate::GATE_LAYOUT_CPT,
				'post_content' => '<!-- wp:paragraph --><p>DRAFT_GATE_PROMPT</p><!-- /wp:paragraph -->',
				'post_title'   => 'Autosave',
			]
		);

		$html = Content_Gate::get_inline_gate_html();

		$this->assertStringContainsString( 'DRAFT_GATE_PROMPT', $html, 'Autosaved content is rendered.' );
		$this->assertStringNotContainsString( 'STORED_GATE_PROMPT', $html, 'Saved content is not rendered when an autosave exists.' );
	}

	/**
	 * A preview restores overlay rendering even when Content_Gifting disabled it
	 * (it sets newspack_can_render_overlay_gate to false).
	 */
	public function test_overlay_render_is_restored_during_preview() {
		wp_set_current_user( $this->admin_id );
		add_filter( 'newspack_can_render_overlay_gate', '__return_false' );

		$this->assertFalse(
			(bool) apply_filters( 'newspack_can_render_overlay_gate', true ),
			'Without a preview, the overlay-disable filter stands.'
		);

		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );
		$this->assertTrue(
			(bool) apply_filters( 'newspack_can_render_overlay_gate', true ),
			'The preview restores overlay rendering even when it was disabled.'
		);

		remove_filter( 'newspack_can_render_overlay_gate', '__return_false' );
	}

	/**
	 * The overlay render path substitutes the autosaved content and honors the
	 * overlay position/size overrides carried in the preview URL.
	 */
	public function test_overlay_preview_renders_autosave_with_meta_overrides() {
		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );
		$this->set_query_param( 'ngp_st', 'overlay' );
		$this->set_query_param( 'ngp_op', 'bottom' );
		$this->set_query_param( 'ngp_os', 'large' );

		wp_create_post_autosave(
			[
				'post_ID'      => $this->layout_id,
				'post_type'    => Content_Gate::GATE_LAYOUT_CPT,
				'post_content' => '<!-- wp:paragraph --><p>OVERLAY_DRAFT_PROMPT</p><!-- /wp:paragraph -->',
				'post_title'   => 'Autosave',
			]
		);

		ob_start();
		Content_Gate::render_overlay_gate_html( $this->layout_id );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'newspack-content-gate__overlay-gate', $html, 'Overlay gate markup is rendered.' );
		$this->assertStringContainsString( 'OVERLAY_DRAFT_PROMPT', $html, 'Overlay renders the autosaved content via the layout-content seam.' );
		$this->assertStringContainsString( 'data-position="bottom"', $html, 'Overlay position override from the URL is applied.' );
		$this->assertStringContainsString( 'data-size="large"', $html, 'Overlay size override from the URL is applied.' );
	}

	/**
	 * Meta overrides in the URL are sanitized: valid values are applied, invalid
	 * ones are dropped so the stored meta wins.
	 */
	public function test_meta_overrides_are_sanitized() {
		$this->set_query_param( 'ngp_st', 'overlay' );
		$this->set_query_param( 'ngp_vp', '5' );
		$this->set_query_param( 'ngp_if', 'true' );
		$this->set_query_param( 'ngp_mt', 'false' );
		$this->set_query_param( 'ngp_op', 'bottom' );
		$this->set_query_param( 'ngp_os', 'large' );

		$overrides = Gate_Preview::get_preview_meta_overrides();

		$this->assertSame( 'overlay', $overrides['style'] );
		$this->assertSame( 5, $overrides['visible_paragraphs'] );
		$this->assertTrue( $overrides['inline_fade'] );
		$this->assertFalse( $overrides['use_more_tag'] );
		$this->assertSame( 'bottom', $overrides['overlay_position'] );
		$this->assertSame( 'large', $overrides['overlay_size'] );

		// Invalid enum values, non-numeric counts, and malformed booleans are
		// dropped entirely (a malformed boolean must not coerce to false and
		// silently override stored meta).
		$_GET['ngp_st'] = 'not-a-style';
		$_GET['ngp_vp'] = 'abc';
		$_GET['ngp_os'] = 'gigantic';
		$_GET['ngp_if'] = 'abc';
		$overrides      = Gate_Preview::get_preview_meta_overrides();

		$this->assertArrayNotHasKey( 'style', $overrides, 'An invalid style is dropped so stored meta wins.' );
		$this->assertArrayNotHasKey( 'visible_paragraphs', $overrides, 'A non-numeric count is dropped.' );
		$this->assertArrayNotHasKey( 'overlay_size', $overrides, 'An invalid overlay size is dropped.' );
		$this->assertArrayNotHasKey( 'inline_fade', $overrides, 'A malformed boolean is dropped rather than coerced to false.' );

		// A negative paragraph count clamps to 0 (matching get_visible_paragraphs),
		// not absint()'s 5 — a preview must never show more paragraphs than the gate.
		$_GET['ngp_vp'] = '-5';
		$overrides      = Gate_Preview::get_preview_meta_overrides();
		$this->assertSame( 0, $overrides['visible_paragraphs'], 'A negative count clamps to 0, not its absolute value.' );

		// A very high paragraph count clamps to the preview ceiling (defense-in-depth
		// so a hand-edited URL can't dump a gated post's full body as the excerpt).
		$_GET['ngp_vp'] = '99999';
		$overrides      = Gate_Preview::get_preview_meta_overrides();
		$this->assertSame(
			Gate_Preview::PREVIEW_MAX_VISIBLE_PARAGRAPHS,
			$overrides['visible_paragraphs'],
			'An unbounded count clamps to the preview ceiling.'
		);
	}

	/**
	 * During a preview, get_post_meta for the previewed layout reflects the URL
	 * override; other objects and other meta keys are untouched.
	 */
	public function test_meta_override_filter_applies_to_previewed_layout_only() {
		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );
		$this->set_query_param( 'ngp_st', 'overlay' );

		$this->assertSame( 'overlay', get_post_meta( $this->layout_id, 'style', true ), 'The override wins for the previewed layout.' );

		$other_layout = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_LAYOUT_CPT,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $other_layout, 'style', 'inline' );
		$this->assertSame( 'inline', get_post_meta( $other_layout, 'style', true ), 'A different layout is not affected by the override.' );
	}

	/**
	 * An invalid override falls back to the stored meta value.
	 */
	public function test_meta_override_falls_back_to_stored_value() {
		update_post_meta( $this->layout_id, 'style', 'overlay' );
		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );
		$this->set_query_param( 'ngp_st', 'bogus' );

		$this->assertSame( 'overlay', get_post_meta( $this->layout_id, 'style', true ), 'Invalid override falls back to stored meta.' );
	}

	/**
	 * Gate-post-id resolution: a published parent gate is returned; a draft
	 * parent falls back to the layout id so has_gate() stays true.
	 */
	public function test_gate_post_id_resolution() {
		$gate_id            = Content_Gate::create_gate( [ 'title' => 'Preview Parent Gate' ] );
		$registration       = Content_Gate::get_registration_settings( $gate_id );
		$parent_layout_id   = (int) $registration['gate_layout_id'];

		// The parent gate references its own registration layout.
		$this->assertSame( $gate_id, Gate_Preview::get_layout_parent_gate_id( $parent_layout_id ), 'The referencing gate is found regardless of status.' );

		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $parent_layout_id );

		// Published parent gate.
		wp_update_post(
			[
				'ID'          => $gate_id,
				'post_status' => 'publish',
			]
		);
		$this->assertSame( $gate_id, Content_Gate::get_gate_post_id(), 'A published parent gate is used as the gate post id.' );
		$this->assertTrue( Content_Gate::has_gate(), 'has_gate() is true with a published parent gate.' );

		// Draft parent gate falls back to the (published) layout id.
		wp_update_post(
			[
				'ID'          => $gate_id,
				'post_status' => 'draft',
			]
		);
		$this->assertSame( $parent_layout_id, Content_Gate::get_gate_post_id(), 'A draft parent falls back to the layout id.' );
		$this->assertTrue( Content_Gate::has_gate(), 'has_gate() stays true via the published layout id fallback.' );

		wp_delete_post( $gate_id, true );
	}

	/**
	 * Metering is short-circuited during a preview: the gate keeps rendering and
	 * the previewing admin's metering allowance is never consumed.
	 */
	public function test_metering_short_circuit_leaves_admin_allowance_untouched() {
		$gate_id          = Content_Gate::create_gate( [ 'title' => 'Metered Preview Gate' ] );
		$registration     = Content_Gate::get_registration_settings( $gate_id );
		$parent_layout_id = (int) $registration['gate_layout_id'];
		Content_Gate::update_registration_settings(
			$gate_id,
			[
				'active'   => true,
				'metering' => [
					'enabled' => true,
					'count'   => 3,
					'period'  => 'month',
				],
			]
		);
		wp_update_post(
			[
				'ID'          => $gate_id,
				'post_status' => 'publish',
			]
		);

		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $parent_layout_id );

		$user_meta_key = Metering::METERING_META_KEY . '_' . $gate_id;

		$this->assertTrue( apply_filters( 'newspack_content_gate_metering_short_circuit', null ), 'Metering is short-circuited during a preview.' );
		$this->assertFalse( Metering::is_metering(), 'Metering does not grant access during a preview.' );
		$this->assertFalse( Metering::is_logged_in_metering_allowed( $this->canvas_post_id ), 'Metering does not grant back-end access during a preview.' );
		$this->assertEmpty( get_user_meta( $this->admin_id, $user_meta_key, true ), "The admin's metering allowance is not consumed by previewing." );

		wp_delete_post( $gate_id, true );
	}

	/**
	 * The admin bar is hidden during a preview and shown otherwise.
	 */
	public function test_admin_bar_hidden_during_preview() {
		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );
		$this->assertFalse( Gate_Preview::filter_show_admin_bar( true ), 'Admin bar is hidden during a preview.' );

		unset( $_GET[ Gate_Preview::PREVIEW_QUERY_PARAM ] );
		$this->assertTrue( Gate_Preview::filter_show_admin_bar( true ), 'Admin bar is shown when not previewing.' );
	}

	/**
	 * The preview param list reaches the front-end script only on a preview
	 * request, which by definition means a user who can preview.
	 *
	 * The list itself is not secret — it is constant param names — but shipping it
	 * is what makes the previewed document rewrite every same-origin link. A
	 * reader who received it would carry the preview params for the rest of their
	 * session.
	 */
	public function test_preview_params_localized_only_for_a_previewing_user() {
		wp_set_current_user( $this->admin_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );

		$data = Content_Gate::get_frontend_script_data( false, Gate_Preview::is_preview_request() );
		$this->assertArrayHasKey( 'preview_param_names', $data, 'An admin previewing gets the param list.' );
		$this->assertContains( Gate_Preview::PREVIEW_QUERY_PARAM, $data['preview_param_names'], 'The list carries the preview param itself.' );
		$this->assertArrayNotHasKey( 'metadata', $data, 'No gate metadata is sent where no gate renders.' );
	}

	/**
	 * A subscriber on the same URL gets nothing, because is_preview_request()
	 * requires the preview capability on every call.
	 */
	public function test_preview_params_absent_for_subscriber_on_preview_url() {
		wp_set_current_user( $this->subscriber_id );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );

		$data = Content_Gate::get_frontend_script_data( false, Gate_Preview::is_preview_request() );
		$this->assertArrayNotHasKey( 'preview_param_names', $data, 'A subscriber on the preview URL gets no param list.' );
	}

	/**
	 * Outside a preview the key is absent, so an ordinary page load carries no
	 * extra payload.
	 */
	public function test_preview_params_absent_without_the_param() {
		wp_set_current_user( $this->admin_id );

		$data = Content_Gate::get_frontend_script_data( true, Gate_Preview::is_preview_request() );
		$this->assertArrayNotHasKey( 'preview_param_names', $data, 'No param list without a preview request.' );
		$this->assertArrayHasKey( 'metadata', $data, 'A rendering gate still gets its metadata.' );
	}

	/**
	 * The enqueue decision itself — not just its payload — has to survive a
	 * non-singular view, which is the whole point of the preview-session change.
	 *
	 * Asserts the context rather than driving enqueue_scripts(), because that would
	 * need built assets for its filemtime() cache-busters and CI runs PHPUnit
	 * without a build.
	 */
	public function test_script_loads_on_an_archive_during_a_preview() {
		wp_set_current_user( $this->admin_id );
		// After go_to(): it resets $_GET and repopulates it from the URL.
		$this->go_to( get_category_link( self::factory()->category->create() ) );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );

		$context = Content_Gate::get_frontend_script_conditions();

		$this->assertFalse( $context['renders_gate'], 'No gate renders on an archive.' );
		$this->assertTrue( $context['is_preview'], 'The request is still a preview.' );
		$this->assertTrue( $context['enqueue'], 'The script has to load anyway, so a click through this page keeps the preview.' );
	}

	/**
	 * The same view without a preview stays clean — the counterpart that would fail
	 * if the decision were widened to "always enqueue".
	 */
	public function test_script_does_not_load_on_an_ordinary_archive() {
		$this->go_to( get_category_link( self::factory()->category->create() ) );

		$context = Content_Gate::get_frontend_script_conditions();

		$this->assertFalse( $context['is_preview'], 'Not a preview request.' );
		$this->assertFalse( $context['enqueue'], 'Nothing to enqueue on an ordinary archive.' );
	}

	/**
	 * A term ID is not a post ID. On an archive the queried object is a term, and
	 * comparing its ID against post IDs force-restricted whichever unrelated post
	 * happened to share the number.
	 */
	public function test_archive_preview_does_not_force_restrict_an_unrelated_post() {
		wp_set_current_user( $this->admin_id );
		$term_id = self::factory()->category->create();
		// After go_to(): it resets $_GET and repopulates it from the URL. Setting the
		// param first would leave this not-a-preview, and the assertions below would
		// pass trivially on filter_is_post_restricted()'s own early return.
		$this->go_to( get_category_link( $term_id ) );
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );

		$this->assertTrue( Gate_Preview::is_preview_request(), 'Guard: the preview really is active here.' );

		// An id equal to the queried term id is the case that bit: nothing is created
		// at that id here, because the filter never loads the post — it only compares.
		$this->assertFalse(
			Gate_Preview::filter_is_post_restricted( false, $term_id ),
			'A post sharing an ID with the queried term is not part of the preview.'
		);
		$this->assertFalse(
			Gate_Preview::filter_is_post_restricted( false, 0 ),
			'Out of the loop on an archive there is no previewed post to force.'
		);
	}

	/**
	 * Cache-cancelling is gated on the capability, like every other preview seam.
	 */
	public function test_preview_caching_only_prevented_for_a_previewing_user() {
		$this->set_query_param( Gate_Preview::PREVIEW_QUERY_PARAM, $this->layout_id );

		wp_set_current_user( $this->subscriber_id );
		$this->assertFalse(
			Gate_Preview::prevent_preview_caching(),
			'A subscriber on the preview URL gets ordinary cache headers.'
		);

		wp_set_current_user( $this->admin_id );
		$this->assertTrue(
			Gate_Preview::prevent_preview_caching(),
			'A previewing admin gets the page kept out of the cache.'
		);
	}
}
