<?php
/**
 * Tests for content gate layouts containing a synced pattern (core/block reference).
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;

/**
 * Characterization tests pinning synced-pattern (core/block) rendering inside
 * gate layouts — the surface NPPD-2166 originally suspected. These pass with
 * or without the re-entrancy fix (a synced pattern alone runs no secondary
 * loop); the regression proof for the fix lives in gate-restrict-reentry.php.
 * The re-entry guard here future-proofs the pattern path against recursion.
 *
 * @group content-gate
 */
class Test_Gate_Synced_Pattern_Layout extends \WP_UnitTestCase {

	/**
	 * Gate IDs created per test.
	 *
	 * @var int[]
	 */
	protected $gate_ids = [];

	/**
	 * Post IDs created per test.
	 *
	 * @var int[]
	 */
	protected $post_ids = [];

	/**
	 * Define the Content Gates feature flag (process-wide once defined) so the
	 * gate code paths are active for these tests.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Test set up: a published gate restricting all posts, and a gated post.
	 */
	public function set_up() {
		parent::set_up();
		$gate_id          = Content_Gate::create_gate( [ 'title' => 'Synced Pattern Gate' ] );
		$this->gate_ids[] = $gate_id;
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Synced Pattern Gate',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'post_types',
						'value' => [ 'post' ],
					],
				],
				'registration'  => [
					'active'               => true,
					'metering'             => [
						'enabled' => false,
						'count'   => 0,
						'period'  => 'month',
					],
					'require_verification' => false,
					'gate_id'              => 0,
				],
			]
		);
		$this->post_ids[] = $this->factory->post->create(
			[
				'post_content' => '<!-- wp:paragraph --><p>First paragraph.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Second paragraph.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Premium paragraph.</p><!-- /wp:paragraph -->',
			]
		);
	}

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		foreach ( $this->gate_ids as $gate_id ) {
			wp_delete_post( $gate_id, true );
		}
		foreach ( $this->post_ids as $post_id ) {
			wp_delete_post( $post_id, true );
		}
		$this->gate_ids = [];
		$this->post_ids = [];
		$this->reset_restriction_cache();
		$this->reset_gate_render_state();
		parent::tear_down();
	}

	/**
	 * Reset restriction caches (mirrors content-gates.php).
	 */
	private function reset_restriction_cache() {
		foreach ( [ 'post_gate_id_map', 'post_gate_layout_id_map', 'post_gates_map', 'term_descendants_map' ] as $prop ) {
			$reflection = new \ReflectionProperty( Content_Restriction_Control::class, $prop );
			$reflection->setAccessible( true );
			$reflection->setValue( null, [] );
		}
	}

	/**
	 * Reset the render-time static flags on Content_Gate.
	 */
	private function reset_gate_render_state() {
		foreach ( [ 'gate_rendered', 'is_gated', 'is_content_locked', 'restricted_content' ] as $prop ) {
			$reflection = new \ReflectionProperty( Content_Gate::class, $prop );
			$reflection->setAccessible( true );
			$reflection->setValue( null, 'restricted_content' === $prop ? [] : false );
		}
	}

	/**
	 * Create a synced pattern (wp_block post) and point the gate layout at it.
	 *
	 * @return array{pattern_id: int, layout_id: int} IDs.
	 */
	private function create_pattern_backed_layout() {
		$pattern_id = wp_insert_post(
			[
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Offer cards',
				'post_content' => '<!-- wp:paragraph --><p>Synced offer card content.</p><!-- /wp:paragraph -->',
			]
		);
		$this->post_ids[] = $pattern_id;

		$gate      = Content_Gate::get_gate( $this->gate_ids[0] );
		$layout_id = $gate['registration']['gate_layout_id'];
		wp_update_post(
			[
				'ID'           => $layout_id,
				'post_content' => '<!-- wp:paragraph --><p>Subscribe to keep reading.</p><!-- /wp:paragraph --><!-- wp:block {"ref":' . $pattern_id . '} /-->',
			]
		);
		update_post_meta( $layout_id, 'style', 'inline' );

		return [
			'pattern_id' => $pattern_id,
			'layout_id'  => $layout_id,
		];
	}

	/**
	 * Install a guard that converts unbounded gate-render re-entry into a
	 * diagnosable exception (instead of exhausting memory like production).
	 *
	 * @param int $threshold Number of allowed applications of the filter.
	 * @return callable Remover.
	 */
	private function install_reentry_guard( $threshold = 10 ) {
		$count = 0;
		$guard = function ( $content ) use ( &$count, $threshold ) {
			$count++;
			if ( $count > $threshold ) {
				throw new \RuntimeException(
					sprintf( 'newspack_gate_content re-entered more than %d times — unbounded gate render recursion.', (int) $threshold )
				);
			}
			return $content;
		};
		add_filter( 'newspack_gate_content', $guard, 1 );
		return function () use ( $guard ) {
			remove_filter( 'newspack_gate_content', $guard, 1 );
		};
	}

	/**
	 * A gate layout referencing a synced pattern must render the pattern's
	 * content exactly once — directly through the gate content pipeline.
	 */
	public function test_inline_gate_renders_synced_pattern_content() {
		$ids     = $this->create_pattern_backed_layout();
		$remover = $this->install_reentry_guard();

		try {
			$html = Content_Gate::get_inline_gate_content_for_post( $ids['layout_id'] );
			$html = apply_filters( 'newspack_gate_content', $html );
		} finally {
			$remover();
		}

		$this->assertStringContainsString( 'Subscribe to keep reading', $html, 'Gate layout content should render' );
		$this->assertStringContainsString( 'Synced offer card content', $html, 'Synced pattern content should render inside the gate' );
	}

	/**
	 * Full front-end flow: a restricted post rendered through restrict_post()
	 * and the_content must produce the gate with the pattern content. The
	 * re-entry guard is a safety net for the assertions, not the subject under
	 * test — see gate-restrict-reentry.php for the re-entrancy coverage.
	 */
	public function test_restricted_post_renders_gate_with_synced_pattern() {
		$ids     = $this->create_pattern_backed_layout();
		$post_id = $this->post_ids[0];

		wp_set_current_user( 0 );
		$this->reset_gate_render_state();
		$this->assertNotFalse( Content_Gate::is_post_restricted( $post_id ), 'Post should be restricted for the logged-out reader' );

		$remover = $this->install_reentry_guard();
		try {
			$this->go_to( get_permalink( $post_id ) );
			$post = get_post( $post_id );
			Content_Gate::restrict_post( $post, $GLOBALS['wp_query'] );
			$content = apply_filters( 'the_content', $post->post_content );
		} finally {
			$remover();
		}

		$this->assertStringContainsString( 'Synced offer card content', $content, 'Synced pattern content should render inside the gate on a restricted post' );
		$this->assertStringNotContainsString( 'Premium paragraph', $content, 'Restricted content should be truncated' );
	}
}
