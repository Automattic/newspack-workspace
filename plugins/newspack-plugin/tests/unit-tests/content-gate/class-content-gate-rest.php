<?php
/**
 * Tests for content gating on REST reads.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Reader_Activation;

/**
 * Tests for content gating on REST reads.
 *
 * @group content-gate
 */
class Test_Content_Gate_Rest extends \WP_UnitTestCase {

	/**
	 * A sentinel that appears only in the gated post's body.
	 *
	 * @var string
	 */
	const BODY_SENTINEL = 'SUBSCRIBER_ONLY_PARAGRAPH_SENTINEL';

	/**
	 * The gated post's ID.
	 *
	 * @var int
	 */
	protected $gated_post_id;

	/**
	 * An ungated post's ID.
	 *
	 * @var int
	 */
	protected $open_post_id;

	/**
	 * The gate layout ID.
	 *
	 * @var int
	 */
	protected $gate_id;

	/**
	 * Define the feature flag for this class only and re-init the REST server
	 * so routes register with the flag on.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		$GLOBALS['wp_rest_server'] = null;
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Create one gated post, one open post, and a published gate bound to the
	 * gated post through a specific_posts rule.
	 */
	public function set_up() {
		parent::set_up();

		Reader_Activation::update_setting( 'enabled', true );

		$this->gated_post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Gated article',
				'post_content' => '<!-- wp:paragraph --><p>Free paragraph one.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Free paragraph two.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>' . self::BODY_SENTINEL . '</p><!-- /wp:paragraph -->',
			]
		);
		$this->open_post_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Open article',
				'post_content' => '<!-- wp:paragraph --><p>Freely readable.</p><!-- /wp:paragraph -->',
			]
		);

		$this->gate_id = Content_Gate::create_gate( [ 'title' => 'REST Gate' ] );
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'title'         => 'REST Gate',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'specific_posts',
						'value' => [ $this->gated_post_id ],
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
	}

	/**
	 * Perform a REST GET and return the response data.
	 *
	 * @param string $route   REST route.
	 * @param array  $params  Query parameters.
	 * @return array Response data.
	 */
	protected function rest_get( $route, $params = [] ) {
		$request = new \WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->response_to_data( rest_do_request( $request ), false );
	}

	/**
	 * An anonymous read of a gated post returns the teaser, not the body.
	 */
	public function test_anonymous_read_of_a_gated_post_withholds_the_body() {
		wp_set_current_user( 0 );

		$data = $this->rest_get( '/wp/v2/posts/' . $this->gated_post_id );

		$this->assertArrayHasKey( 'content', $data, 'View context includes content.' );
		$this->assertStringNotContainsString(
			self::BODY_SENTINEL,
			$data['content']['rendered'],
			'A gated post must not serialize its body over REST.'
		);
	}

	/**
	 * An anonymous read of an ungated post is untouched.
	 */
	public function test_anonymous_read_of_an_open_post_is_untouched() {
		wp_set_current_user( 0 );

		$data = $this->rest_get( '/wp/v2/posts/' . $this->open_post_id );

		$this->assertStringContainsString(
			'Freely readable.',
			$data['content']['rendered'],
			'An ungated post must be unaffected.'
		);
	}

	/**
	 * A reader the gate would admit still receives the full body.
	 */
	public function test_entitled_reader_receives_the_full_body() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$data = $this->rest_get( '/wp/v2/posts/' . $this->gated_post_id );

		$this->assertStringContainsString(
			self::BODY_SENTINEL,
			$data['content']['rendered'],
			'A registered reader passes the registration gate and keeps full access.'
		);
	}

	/**
	 * The editor's context=edit payload is untouched.
	 */
	public function test_edit_context_is_untouched() {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$data = $this->rest_get( '/wp/v2/posts/' . $this->gated_post_id, [ 'context' => 'edit' ] );

		$this->assertStringContainsString(
			self::BODY_SENTINEL,
			$data['content']['raw'],
			'The block editor must receive the real body.'
		);
	}
}
