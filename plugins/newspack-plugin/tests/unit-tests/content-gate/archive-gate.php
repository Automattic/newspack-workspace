<?php
/**
 * Tests for gating a post type archive page.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;
use Newspack\Collections\Gate_Integration;
use Newspack\Collections\Post_Type;

/**
 * Tests for gating the Collections archive page.
 *
 * @group content-gate
 */
class Test_Archive_Gate extends \WP_UnitTestCase {

	use \Newspack\Tests\Content_Gate\Traits\Trait_Restriction_Cache_Test;

	/**
	 * Body text every test Collection carries.
	 *
	 * @var string
	 */
	const COLLECTION_TEXT = 'Collection body text.';

	/**
	 * Text of the archive gate's layout.
	 *
	 * @var string
	 */
	const LAYOUT_TEXT = 'Join to browse the archive.';

	/**
	 * The published registration gate with an archive rule for Collections.
	 *
	 * @var int
	 */
	private $gate_id;

	/**
	 * Collection post IDs.
	 *
	 * @var int[]
	 */
	private $collection_ids = [];

	/**
	 * Define the Content Gates feature flag for this test class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Register Collections, offer their archive to gates, and gate it.
	 */
	public function set_up() {
		parent::set_up();
		$this->reset_restriction_cache();
		$this->reset_gate_rendered();

		Post_Type::register_post_type();
		add_filter( 'newspack_content_gate_supported_archive_post_types', [ Gate_Integration::class, 'add_archive_support' ] );

		$this->collection_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$this->collection_ids[] = self::factory()->post->create(
				[
					'post_type'    => Post_Type::get_post_type(),
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:paragraph --><p>' . self::COLLECTION_TEXT . '</p><!-- /wp:paragraph -->',
				]
			);
		}

		$this->gate_id = $this->create_archive_gate(
			[
				'priority'     => 0,
				'registration' => [ 'active' => true ],
			]
		);
		wp_update_post(
			[
				'ID'           => $this->get_layout_id( $this->gate_id ),
				'post_content' => '<!-- wp:paragraph --><p>' . self::LAYOUT_TEXT . '</p><!-- /wp:paragraph -->',
			]
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Undo the support filter and the request-scoped state.
	 */
	public function tear_down() {
		remove_filter( 'newspack_content_gate_supported_archive_post_types', [ Gate_Integration::class, 'add_archive_support' ] );
		$this->reset_restriction_cache();
		$this->reset_gate_rendered();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a published gate with an archive rule for Collections and no content rules.
	 *
	 * @param array $settings Gate settings to merge over the defaults.
	 *
	 * @return int Gate ID.
	 */
	private function create_archive_gate( array $settings ) {
		$gate_id = Content_Gate::create_gate( [ 'title' => 'Archive Gate' ] );
		Content_Gate::update_gate_settings(
			$gate_id,
			array_merge(
				[
					'status'        => 'publish',
					'content_rules' => [],
					'archive_rules' => [
						[
							'slug'  => 'post_type_archives',
							'value' => [ Post_Type::get_post_type() ],
						],
					],
				],
				$settings
			)
		);
		return $gate_id;
	}

	/**
	 * Get a gate's registration layout ID.
	 *
	 * @param int $gate_id Gate ID.
	 *
	 * @return int
	 */
	private function get_layout_id( $gate_id ) {
		return (int) Content_Gate::get_gate( $gate_id )['registration']['gate_layout_id'];
	}

	/**
	 * Go to the Collections archive.
	 *
	 * @param array $args Query args to add to the archive URL.
	 */
	private function go_to_archive( array $args = [] ) {
		$this->go_to( add_query_arg( $args, get_post_type_archive_link( Post_Type::get_post_type() ) ) );
	}

	/**
	 * Release the once-per-request gate render lock.
	 */
	private function reset_gate_rendered() {
		$reflection = new \ReflectionProperty( Content_Gate::class, 'gate_rendered' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, false );
	}

	/**
	 * Paid-access settings whose one rule no test account meets: an email domain
	 * the factory's addresses don't use.
	 *
	 * @param array $metering Metering settings.
	 *
	 * @return array
	 */
	private function get_paid_access_settings( array $metering = [] ) {
		$settings = [
			'active'       => true,
			'access_rules' => [
				[
					[
						'slug'  => 'email_domain',
						'value' => 'example.test',
					],
				],
			],
		];
		if ( $metering ) {
			$settings['metering'] = $metering;
		}
		return $settings;
	}

	/**
	 * A logged-out reader on the archive gets the gate and its layout.
	 */
	public function test_logged_out_reader_gets_archive_gate() {
		$this->go_to_archive();

		$this->assertSame(
			[
				'gate_id'        => $this->gate_id,
				'gate_layout_id' => $this->get_layout_id( $this->gate_id ),
			],
			Content_Restriction_Control::get_archive_gate()
		);
	}

	/**
	 * Later pages and filtered views of the archive are gated like the first page.
	 */
	public function test_paged_and_filtered_archive_is_gated() {
		update_option( 'posts_per_page', 1 );
		$this->go_to_archive( [ 'paged' => 2 ] );
		$this->assertTrue( is_paged() && is_post_type_archive(), 'Setup: this is page 2 of the archive.' );
		$this->assertSame( $this->gate_id, Content_Restriction_Control::get_archive_gate()['gate_id'] ?? null, 'Page 2 is gated.' );

		$this->reset_restriction_cache();
		$this->go_to_archive( [ 'year' => get_the_date( 'Y', $this->collection_ids[0] ) ] );
		$this->assertTrue( is_post_type_archive(), 'Setup: the year filter keeps the archive.' );
		$this->assertSame( $this->gate_id, Content_Restriction_Control::get_archive_gate()['gate_id'] ?? null, 'The year filter is gated.' );
	}

	/**
	 * An account that skips access checks sees the archive.
	 */
	public function test_admin_skips_archive_gate() {
		// A paid-access gate, which refuses a logged-in account unless it skips checks.
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'registration'  => [ 'active' => false ],
				'custom_access' => $this->get_paid_access_settings(),
			]
		);
		$this->go_to_archive();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( $this->gate_id, Content_Restriction_Control::get_archive_gate()['gate_id'] ?? null, 'Setup: the gate refuses a logged-in reader.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertNull( Content_Restriction_Control::get_archive_gate() );
	}

	/**
	 * Content rules for Collections don't gate their archive; only an archive rule does.
	 */
	public function test_archive_without_archive_rule_is_not_gated() {
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'archive_rules' => [],
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ Post_Type::get_post_type() ],
					],
				],
			]
		);
		$this->go_to_archive();

		$this->assertNull( Content_Restriction_Control::get_archive_gate() );
	}

	/**
	 * Jetpack's Infinite Scroll can't append more Collections below an archive gate.
	 */
	public function test_infinite_scroll_off_on_gated_archive() {
		Gate_Integration::init();
		$this->go_to_archive();
		$this->assertNotNull( Content_Restriction_Control::get_archive_gate(), 'Setup: the archive is gated.' );

		$this->assertFalse( apply_filters( 'infinite_scroll_archive_supported', true, [] ) );
	}

	/**
	 * On an archive no gate covers, Infinite Scroll keeps the answer it was given.
	 */
	public function test_infinite_scroll_unchanged_on_ungated_archive() {
		Gate_Integration::init();
		Content_Gate::update_gate_settings( $this->gate_id, [ 'archive_rules' => [] ] );
		$this->go_to_archive();
		$this->assertNull( Content_Restriction_Control::get_archive_gate(), 'Setup: the archive is open.' );

		$this->assertTrue( apply_filters( 'infinite_scroll_archive_supported', true, [] ) );
		$this->assertFalse( apply_filters( 'infinite_scroll_archive_supported', false, [] ) );
	}

	/**
	 * A stored archive rule stops applying when its post type is no longer offered.
	 */
	public function test_post_type_no_longer_offered_is_not_gated() {
		remove_filter( 'newspack_content_gate_supported_archive_post_types', [ Gate_Integration::class, 'add_archive_support' ] );
		$this->go_to_archive();

		$this->assertNull( Content_Restriction_Control::get_archive_gate() );
	}

	/**
	 * The highest-priority matching gate decides alone, as it does for posts.
	 */
	public function test_first_matching_gate_decides() {
		$gate_b = $this->create_archive_gate(
			[
				'priority'      => 1,
				'registration'  => [ 'active' => false ],
				'custom_access' => $this->get_paid_access_settings(),
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->go_to_archive();

		$this->assertNull( Content_Restriction_Control::get_archive_gate(), 'Gate A admits a registered reader, so gate B is never consulted.' );

		// Control: without gate A, gate B refuses this reader.
		Content_Gate::update_gate_settings( $this->gate_id, [ 'status' => 'draft' ] );
		$this->reset_restriction_cache();
		$this->assertSame( $gate_b, Content_Restriction_Control::get_archive_gate()['gate_id'] ?? null );
	}

	/**
	 * The archive's feed isn't gated.
	 */
	public function test_feed_is_not_gated() {
		$this->go_to( get_post_type_archive_feed_link( Post_Type::get_post_type() ) );
		$this->assertTrue( is_feed(), 'Setup: this is the archive feed.' );

		$this->assertNull( Content_Restriction_Control::get_archive_gate() );
	}

	/**
	 * An archive gate renders inline and without a fade, whatever the layout's style.
	 */
	public function test_archive_gate_html_ignores_overlay_style() {
		$layout_id = $this->get_layout_id( $this->gate_id );
		update_post_meta( $layout_id, 'inline_fade', true );

		foreach ( [ 'overlay', 'inline' ] as $style ) {
			update_post_meta( $layout_id, 'style', $style );
			$html = Content_Gate::get_archive_gate_html( $layout_id );

			$this->assertStringContainsString( 'newspack-content-gate__archive-gate', $html, $style );
			$this->assertStringContainsString( 'newspack-content-gate__inline-gate', $html, $style );
			$this->assertStringContainsString( self::LAYOUT_TEXT, $html, $style );
			$this->assertStringNotContainsString( 'linear-gradient', $html, $style );
			$this->assertStringNotContainsString( 'newspack-content-gate__overlay-gate', $html, $style );
		}
	}

	/**
	 * A missing layout falls back to the archive copy, never to the global post.
	 */
	public function test_archive_gate_html_uses_default_copy_without_layout() {
		$this->go_to_archive();
		$this->assertNotEmpty( $GLOBALS['post'], 'Setup: the archive sets a global post.' );

		$layout_id = $this->get_layout_id( $this->gate_id );
		wp_delete_post( $layout_id, true );

		foreach ( [ $layout_id, 0 ] as $missing_layout_id ) {
			$html = Content_Gate::get_archive_gate_html( $missing_layout_id );
			$this->assertStringContainsString( 'This page is only available to members.', $html );
			$this->assertStringContainsString( 'newspack-content-gate__archive-gate', $html );
			$this->assertStringNotContainsString( self::COLLECTION_TEXT, $html );
		}
	}

	/**
	 * The template hook prints the gate and claims the render lock, or does nothing.
	 */
	public function test_maybe_render_archive_gate_outputs_gate() {
		$this->go_to_archive();
		ob_start();
		$rendered = Gate_Integration::maybe_render_archive_gate();
		$output   = ob_get_clean();

		$this->assertTrue( $rendered );
		$this->assertStringContainsString( 'newspack-content-gate__archive-gate', $output );
		$this->assertStringContainsString( self::LAYOUT_TEXT, $output );
		$this->assertTrue( Content_Gate::has_rendered(), 'The render lock is claimed.' );

		$this->reset_gate_rendered();
		$this->reset_restriction_cache();
		$this->go_to( get_permalink( $this->collection_ids[0] ) );
		ob_start();
		$rendered = Gate_Integration::maybe_render_archive_gate();
		$output   = ob_get_clean();

		$this->assertFalse( $rendered, 'A single Collection page is not an archive.' );
		$this->assertSame( '', $output );
		$this->assertFalse( Content_Gate::has_rendered() );
	}

	/**
	 * The gate script and styles load on a gated archive.
	 */
	public function test_script_conditions_on_gated_archive() {
		$this->go_to_archive();
		$context = Content_Gate::get_frontend_script_conditions();

		$this->assertTrue( $context['renders_gate'] );
		$this->assertTrue( $context['enqueue'] );
	}

	/**
	 * Analytics on a gated archive name the archive gate.
	 */
	public function test_gate_metadata_on_gated_archive() {
		$this->go_to_archive();

		$this->assertSame( $this->gate_id, Content_Gate::get_gate_metadata()['gate_post_id'] );
	}

	/**
	 * Campaigns stand down on a gated archive.
	 */
	public function test_disable_popups_on_gated_archive() {
		$this->go_to_archive();
		$this->assertSame( $this->gate_id, Content_Restriction_Control::get_archive_gate()['gate_id'] ?? null, 'Setup: the archive is gated.' );

		$this->assertTrue( Content_Gate::disable_popups( false ) );
	}
}
