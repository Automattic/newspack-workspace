<?php
/**
 * Tests that Content_Gate::restrict_post() is not re-entered while it renders.
 *
 * NPPD-2166: a gated article containing a block that runs a secondary loop and
 * calls wp_reset_postdata() (e.g. Homepage Posts) re-fires the `the_post`
 * action for the main post mid-render. Without a re-entrancy guard the gate
 * renders recursively until memory is exhausted on large sites.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Content_Restriction_Control;

/**
 * Gate render re-entrancy tests.
 *
 * @group content-gate
 */
class Test_Gate_Restrict_Reentry extends \WP_UnitTestCase {

	const LOOP_BLOCK = 'newspack-tests/secondary-loop';

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
	 * How many times the secondary-loop block rendered.
	 *
	 * @var int
	 */
	private $loop_block_renders = 0;

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
	 * Test set up: a published gate restricting posts, a pool of posts for the
	 * secondary loop, and a dynamic block that mimics Homepage Posts (secondary
	 * WP_Query + wp_reset_postdata).
	 */
	public function set_up() {
		parent::set_up();

		$gate_id          = Content_Gate::create_gate( [ 'title' => 'Reentry Gate' ] );
		$this->gate_ids[] = $gate_id;
		Content_Gate::update_gate_settings(
			$gate_id,
			[
				'title'         => 'Reentry Gate',
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

		// Pool of posts for the secondary loop to list.
		$this->post_ids = array_merge( $this->post_ids, $this->factory->post->create_many( 3 ) );

		// A block that runs a secondary loop and resets postdata, like
		// newspack-blocks/homepage-articles and other listing blocks do.
		// Capped so a re-entrancy regression fails the assertion instead of
		// recursing without bound.
		$this->loop_block_renders = 0;
		register_block_type(
			self::LOOP_BLOCK,
			[
				'render_callback' => function () {
					$this->loop_block_renders++;
					if ( $this->loop_block_renders > 5 ) {
						return '';
					}
					$query = new \WP_Query(
						[
							'post_type'      => 'post',
							'posts_per_page' => 2,
							'post__in'       => array_slice( $this->post_ids, 0, 2 ),
						]
					);
					while ( $query->have_posts() ) {
						$query->the_post();
					}
					\wp_reset_postdata();
					return '<div class="secondary-loop">related</div>';
				},
			]
		);
	}

	/**
	 * Teardown after tests.
	 */
	public function tear_down() {
		unregister_block_type( self::LOOP_BLOCK );
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
		foreach ( [ 'gate_rendered', 'is_gated', 'is_content_locked' ] as $prop ) {
			$reflection = new \ReflectionProperty( Content_Gate::class, $prop );
			$reflection->setAccessible( true );
			$reflection->setValue( null, false );
		}
		$reflection = new \ReflectionProperty( Content_Gate::class, 'restricted_content' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, [] );
	}

	/**
	 * Restricting a post whose content runs a secondary loop must render the
	 * gate exactly once: wp_reset_postdata() re-fires `the_post` for the main
	 * post while restrict_post() is still rendering, and without a re-entrancy
	 * guard the gate recurses (unboundedly, on sites with enough posts).
	 */
	public function test_restrict_post_is_not_reentered_by_secondary_loops() {
		$post_id          = $this->factory->post->create(
			[
				'post_content' => '<!-- wp:paragraph --><p>First paragraph.</p><!-- /wp:paragraph -->'
					. '<!-- wp:paragraph --><p>Second paragraph.</p><!-- /wp:paragraph -->'
					. '<!-- wp:' . self::LOOP_BLOCK . ' /-->'
					. '<!-- wp:paragraph --><p>Premium paragraph.</p><!-- /wp:paragraph -->',
			]
		);
		$this->post_ids[] = $post_id;

		wp_set_current_user( 0 );
		$this->reset_gate_render_state();
		$this->assertNotFalse( Content_Gate::is_post_restricted( $post_id ), 'Post should be restricted for the logged-out reader' );

		// Count gate layout renders: one per restrict_post() render pass.
		$layout_renders = 0;
		$counter        = function ( $content ) use ( &$layout_renders ) {
			$layout_renders++;
			return $content;
		};
		add_filter( 'newspack_gate_layout_content', $counter );

		// Production wiring: restrict_post() listens on `the_post`, which is
		// how wp_reset_postdata() re-enters it. Content_Gate::init() hooks are
		// not registered in the test bootstrap, so wire it explicitly — but
		// only add (and later remove) the hook if it isn't already registered,
		// so this test never strips a production-registered listener.
		$hook_added = false;
		if ( false === has_action( 'the_post', [ Content_Gate::class, 'restrict_post' ] ) ) {
			add_action( 'the_post', [ Content_Gate::class, 'restrict_post' ], 10, 2 );
			$hook_added = true;
		}

		try {
			$this->go_to( get_permalink( $post_id ) );
			$post = get_post( $post_id );
			Content_Gate::restrict_post( $post, $GLOBALS['wp_query'] );
		} finally {
			if ( $hook_added ) {
				remove_action( 'the_post', [ Content_Gate::class, 'restrict_post' ], 10 );
			}
			remove_filter( 'newspack_gate_layout_content', $counter );
		}

		$this->assertSame( 1, $layout_renders, 'Gate layout must render exactly once — more means restrict_post() was re-entered by the secondary loop\'s wp_reset_postdata()' );
		$this->assertSame( 1, $this->loop_block_renders, 'The article body must be rendered through the gate pipeline exactly once' );
		$this->assertSame( 1, substr_count( $post->post_content, 'newspack-content-gate__inline-gate' ), 'Restricted content must contain exactly one inline gate' );
		$this->assertStringNotContainsString( 'Premium paragraph', $post->post_content, 'Restricted content should be truncated' );
	}
}
