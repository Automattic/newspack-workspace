<?php
/**
 * Tests for content gating on REST reads.
 *
 * @package Newspack\Tests\Content_Gate
 */

namespace Newspack\Tests\Content_Gate;

use Newspack\Content_Gate;
use Newspack\Metering;
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
				// Force WordPress to auto-generate the excerpt from post_content
				// instead of the factory's placeholder text, so the excerpt test
				// actually exercises the withheld-body case (NPPM-3090's defect
				// class): a generated excerpt that carries the gated paragraph.
				'post_excerpt' => '',
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
	 * A password-protected AND gated post discloses nothing to an anonymous
	 * reader. Core already withholds content.rendered ('') for a
	 * password-protected post (see post_password_required() in
	 * WP_REST_Posts_Controller::prepare_item_for_response()); the gate must
	 * defer to that rather than overwrite the empty string with a teaser the
	 * caller has earned neither the password nor the entitlement for.
	 */
	public function test_anonymous_read_of_a_password_protected_gated_post_stays_empty() {
		wp_update_post(
			[
				'ID'            => $this->gated_post_id,
				'post_password' => 'secret',
			]
		);
		wp_set_current_user( 0 );

		$data = $this->rest_get( '/wp/v2/posts/' . $this->gated_post_id );

		$this->assertSame(
			'',
			$data['content']['rendered'],
			'A password-protected post must stay empty, not be replaced with a gate teaser.'
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
	 * The editor's context=edit payload is untouched, even when another
	 * newspack_is_post_restricted callback forces a restriction that would
	 * otherwise apply regardless of the requesting user's edit_post
	 * capability — the way Gate_Preview::filter_is_post_restricted does
	 * during a layout preview.
	 *
	 * The `content.raw` field is never touched by filter_rest_response() (only
	 * `content.rendered` is), so the assertion has to be on 'rendered': that
	 * is the field the substitution actually writes, and the only one whose
	 * value depends on the context=edit guard being there.
	 *
	 * Content_Restriction_Control::is_post_restricted() itself already
	 * returns false for any user holding edit_post, and only such a user can
	 * request context=edit — so the two guards agree by default and a plain
	 * request can never exercise the context=edit branch at all. Forcing
	 * restriction via 'newspack_is_post_restricted' at PHP_INT_MAX, and the
	 * gate layout via 'newspack_content_gate_layout_id', makes the
	 * context=edit return the only thing standing between this editor and a
	 * gated payload.
	 */
	public function test_edit_context_is_untouched() {
		$user_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user_id );

		$gated_post_id = $this->gated_post_id;
		$gate_layout_id = Content_Gate::get_registration_settings( $this->gate_id )['gate_layout_id'];

		$force_restricted = function ( $is_restricted, $post_id ) use ( $gated_post_id ) {
			return (int) $post_id === $gated_post_id ? true : $is_restricted;
		};
		$force_layout = function () use ( $gate_layout_id ) {
			return $gate_layout_id;
		};
		add_filter( 'newspack_is_post_restricted', $force_restricted, PHP_INT_MAX, 2 );
		add_filter( 'newspack_content_gate_layout_id', $force_layout );

		try {
			$data = $this->rest_get( '/wp/v2/posts/' . $this->gated_post_id, [ 'context' => 'edit' ] );
		} finally {
			remove_filter( 'newspack_is_post_restricted', $force_restricted, PHP_INT_MAX );
			remove_filter( 'newspack_content_gate_layout_id', $force_layout );
		}

		$this->assertStringContainsString(
			self::BODY_SENTINEL,
			$data['content']['rendered'],
			'The block editor must receive the real body even when another newspack_is_post_restricted callback forces a restriction.'
		);
	}

	/**
	 * Every gated item in a collection is gated, not just the first.
	 *
	 * The front-end path marks a gate as rendered once per page. Carrying that
	 * flag into a collection response would gate the first item and serve the
	 * rest intact.
	 */
	public function test_every_gated_item_in_a_collection_is_gated() {
		$second_gated_id = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_title'   => 'Second gated article',
				// Same three-paragraph shape as the primary fixture in set_up():
				// with visible_paragraphs defaulting to 2, a single-paragraph body
				// can't distinguish a teaser from the full body.
				'post_content' => '<!-- wp:paragraph --><p>Free paragraph one.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Free paragraph two.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>' . self::BODY_SENTINEL . '</p><!-- /wp:paragraph -->',
			]
		);
		Content_Gate::update_gate_settings(
			$this->gate_id,
			[
				'title'         => 'REST Gate',
				'status'        => 'publish',
				'priority'      => 0,
				'content_rules' => [
					[
						'slug'  => 'specific_posts',
						'value' => [ $this->gated_post_id, $second_gated_id ],
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
		wp_set_current_user( 0 );

		$data = $this->rest_get( '/wp/v2/posts', [ 'include' => [ $this->gated_post_id, $second_gated_id ] ] );

		$this->assertCount( 2, $data, 'Both gated posts are in the collection.' );
		foreach ( $data as $item ) {
			$this->assertStringNotContainsString(
				self::BODY_SENTINEL,
				$item['content']['rendered'],
				'Every gated item in a collection must withhold its body.'
			);
		}
	}

	/**
	 * An embed-context response keeps the shape core gives it.
	 */
	public function test_embed_context_does_not_gain_a_content_key() {
		wp_set_current_user( 0 );

		$data = $this->rest_get( '/wp/v2/posts/' . $this->gated_post_id, [ 'context' => 'embed' ] );

		$this->assertArrayNotHasKey(
			'content',
			$data,
			'Embed responses omit content; the filter must not fabricate the key.'
		);
	}

	/**
	 * The excerpt is replaced along with the body.
	 */
	public function test_excerpt_is_replaced_for_a_gated_post() {
		wp_set_current_user( 0 );

		$data = $this->rest_get( '/wp/v2/posts/' . $this->gated_post_id );

		$this->assertStringNotContainsString(
			self::BODY_SENTINEL,
			$data['excerpt']['rendered'],
			'A generated excerpt must not carry the withheld body.'
		);
	}

	/**
	 * A gated post reports comments closed, matching the front end.
	 */
	public function test_comment_status_matches_the_front_end() {
		wp_set_current_user( 0 );

		$data = $this->rest_get( '/wp/v2/posts/' . $this->gated_post_id );

		$this->assertSame(
			'closed',
			$data['comment_status'],
			'restrict_post() closes comments on the front end; REST must agree.'
		);
	}

	/**
	 * A REST collection read does not spend the reader's metered allowance.
	 *
	 * Metering only comes into play once a post is otherwise restricted:
	 * Metering::restrict_post() flips a `true` restriction to `false` when
	 * the reader still has metered views left. `registration.metering` alone
	 * cannot exercise that: with `require_verification` false,
	 * is_gated_by_registration() returns false for a logged-in reader, so
	 * Metering::get_effective_settings() reads `custom_access.metering`
	 * instead ({@see class-metering.php:267-274}) — the two settings groups
	 * are read by different branches, not merged. And an empty
	 * `access_rules` evaluates as "no constraint" (Access_Rules::evaluate_rules()
	 * returns true for it), so custom_access.active alone doesn't restrict
	 * anyone either. This fixture needs both: metering mirrored into
	 * `custom_access.metering` (the branch that actually applies here), and
	 * a real access rule that denies this reader so the post is genuinely
	 * restricted in the first place.
	 *
	 * `email_domain` is that rule: is_email_domain_whitelisted() denies
	 * outright whenever the reader isn't verified
	 * ({@see class-access-rules.php:771}), which every subscriber created
	 * below is not — no WooCommerce fixture required.
	 *
	 * The `newspack_content_gate_post_id` filter stands in for the
	 * is_singular() main-query context Content_Gate::get_gate_post_id()
	 * normally relies on to identify which gate is in play. A REST request
	 * has no such context, so without this the settings lookup silently
	 * resolves to nothing and metering never engages — the same technique
	 * Test_Metering::test_metering_allowed_when_verification_not_required()
	 * (tests/unit-tests/content-gate/metering.php) uses to reach the same
	 * update_user_meta() write this test is asserting against.
	 */
	public function test_rest_reads_do_not_consume_metered_allowance() {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
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
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
					'require_verification' => false,
				],
				'custom_access' => [
					'active'       => true,
					'metering'     => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
					// Denies an unverified reader outright regardless of the
					// domain value; see is_email_domain_whitelisted(). What
					// matters here is that this genuinely restricts the
					// subscriber below, not the domain string itself.
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.com',
							],
						],
					],
				],
			]
		);
		wp_set_current_user( $user_id );

		$force_gate_id = function () {
			return $this->gate_id;
		};
		add_filter( 'newspack_content_gate_post_id', $force_gate_id );

		$meta_key = Metering::METERING_META_KEY . '_' . $this->gate_id;
		$before   = get_user_meta( $user_id, $meta_key, true );

		try {
			$data = $this->rest_get( '/wp/v2/posts', [ 'include' => [ $this->gated_post_id ] ] );
		} finally {
			remove_filter( 'newspack_content_gate_post_id', $force_gate_id );
		}

		$after = get_user_meta( $user_id, $meta_key, true );

		$this->assertSame(
			$before,
			$after,
			'A REST read must not record metered consumption.'
		);

		// The flip side of the short-circuit: a reader metering would have let
		// through instead receives the teaser. An API read must not silently
		// spend allowance, so this reader sees what an un-metered restricted
		// reader would see. A future change that quietly restores metering on
		// the REST path should fail this, not pass silently.
		$this->assertStringNotContainsString(
			self::BODY_SENTINEL,
			$data[0]['content']['rendered'],
			'A reader metering would have admitted must still receive the teaser over REST, not the full body.'
		);
		$this->assertStringContainsString(
			'Free paragraph one.',
			$data[0]['content']['rendered'],
			'The teaser must still be served in place of the withheld body.'
		);
	}
}
