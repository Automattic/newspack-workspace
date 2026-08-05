<?php
/**
 * Tests the access_source GA4 parameter.
 *
 * @package Newspack\Tests
 */

use Newspack\GoogleSiteKit;

/**
 * Covers one branch of the resolver per test, plus the guarantee that building
 * the parameters never spends a reader's metering allowance.
 *
 * @group GoogleSiteKit_Access_Source
 */
class Newspack_Test_GoogleSiteKit_Access_Source extends WP_UnitTestCase {

	/**
	 * Gate post ID created by the most recent create_gated_post() call.
	 *
	 * @var int
	 */
	private $gate_id;

	/**
	 * Enable the content gating feature flag.
	 *
	 * Without it `get_custom_event_parameters()` never reaches the resolver, so
	 * the metering test below would pass without exercising anything.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
	}

	/**
	 * Clear every request-scoped memo this code path populates, so no test
	 * inherits another's answer.
	 */
	public function tear_down() {
		Newspack\Access_Attribution::reset_memo();
		Newspack\Access_Rules::flush_one_time_purchase_memo();
		GoogleSiteKit::reset_access_source_memo();
		parent::tear_down();
	}

	/**
	 * Off a singular view there is no gate to evaluate.
	 */
	public function test_non_singular_reports_no_custom_access_gate() {
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A post carrying no gates at all.
	 */
	public function test_ungated_post_reports_no_custom_access_gate() {
		$post_id = $this->factory->post->create();
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A registration-only gate is out of scope: the ESP scopes the whole
	 * Content Access family to custom-access gates, and access_source mirrors
	 * that. Regwall state is read from is_reader and logged_in instead.
	 */
	public function test_registration_only_gate_reports_no_custom_access_gate() {
		$post_id = $this->create_gated_post( [ 'registration' => [ 'active' => true ] ] );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A custom-access gate nobody passes and which does not meter.
	 */
	public function test_blocked_reader_reports_gated() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
				],
			]
		);
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'gated', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * On its own, a custom-access gate with an empty rule set restricts nobody,
	 * so there is no gating to report.
	 */
	public function test_only_unrestricting_gate_reports_no_custom_access_gate() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [],
				],
			]
		);
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'no_custom_access_gate', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A gate whose custom access is on but whose rule set is empty restricts
	 * nobody — but it must not speak for the whole post. Content_Restriction_Control
	 * stops at the first gate that restricts, so a reader blocked by a second
	 * gate is genuinely blocked, and reporting "no gate applies" would describe
	 * the opposite of what the reader sees.
	 */
	public function test_unrestricting_gate_does_not_mask_a_restricting_one() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [],
				],
			]
		);
		$this->attach_gate(
			$post_id,
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
				],
			]
		);
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'gated', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * A passing rule reports its source rather than collapsing to "no gate".
	 * This is the failure a reader-dependent restriction check would produce,
	 * and it is silent: every value still looks plausible.
	 */
	public function test_passing_rule_reports_its_source() {
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'example.org',
							],
						],
					],
				],
			]
		);
		$user_id = $this->factory->user->create( [ 'user_email' => 'reader@example.org' ] );
		Newspack\Reader_Activation::set_reader_verified( $user_id );
		wp_set_current_user( $user_id );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'domain', GoogleSiteKit::get_request_access_source() );
	}

	/**
	 * Building the parameters must not record a metered view. An analytics
	 * parameter that spends a reader's allowance is worse than a missing one.
	 */
	public function test_building_params_does_not_write_metering_meta() {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );
		$post_id = $this->create_gated_post(
			[
				'custom_access' => [
					'active'       => true,
					'access_rules' => [
						[
							[
								'slug'  => 'email_domain',
								'value' => 'nobody.example',
							],
						],
					],
					'metering'     => [
						'enabled' => true,
						'count'   => 3,
						'period'  => 'month',
					],
				],
			]
		);
		$this->assertTrue(
			Newspack\Metering::offers_metering( $this->gate_id ),
			'The fixture gate must actually offer metering, or the no-write guarantee is untested.'
		);
		$this->go_to( get_permalink( $post_id ) );

		$before = get_user_meta( $user_id );
		$params = GoogleSiteKit::get_custom_event_parameters();
		$after  = get_user_meta( $user_id );

		$this->assertSame( $before, $after, 'Building GA4 params must not consume a metered view.' );
		$this->assertSame(
			'metering_eligible',
			$params['access_source'] ?? null,
			'A blocked reader on a metering gate is metering_eligible.'
		);
	}

	/**
	 * Build a post with a published gate bound to it.
	 *
	 * Binding uses a `specific_posts` content rule, which
	 * Content_Restriction_Control::get_post_gates() treats as an inclusion
	 * override — the most deterministic association available, and immune to
	 * category or post-type matching changing underneath the test.
	 *
	 * @param array $gate_meta Post meta to set on the gate, keyed by meta key
	 *                         (`custom_access`, `registration`).
	 * @return int Post ID of the gated post.
	 */
	private function create_gated_post( $gate_meta ) {
		$post_id = $this->factory->post->create();
		$this->attach_gate( $post_id, $gate_meta );
		return $post_id;
	}

	/**
	 * Publish a gate and bind it to an existing post.
	 *
	 * @param int   $post_id   Post the gate applies to.
	 * @param array $gate_meta Post meta to set on the gate, keyed by meta key.
	 * @return int Post ID of the gate.
	 */
	private function attach_gate( $post_id, $gate_meta ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Newspack\Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Access source test gate',
			]
		);
		foreach ( $gate_meta as $meta_key => $meta_value ) {
			update_post_meta( $gate_id, $meta_key, $meta_value );
		}
		Newspack\Content_Rules::update_gate_content_rules(
			$gate_id,
			[
				[
					'slug'  => 'specific_posts',
					'value' => [ $post_id ],
				],
			]
		);
		$this->gate_id = $gate_id;
		return $gate_id;
	}
}
