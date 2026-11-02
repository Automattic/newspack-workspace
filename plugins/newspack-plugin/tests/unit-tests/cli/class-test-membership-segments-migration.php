<?php
/**
 * Tests for `wp newspack migrate-membership-segments`.
 *
 * @package Newspack\Tests
 */

use Newspack\CLI\Membership_Segments_Migration;
use Newspack\Content_Gate;

require_once dirname( __DIR__, 2 ) . '/mocks/wp-cli-mocks.php';
require_once dirname( __DIR__, 2 ) . '/mocks/newspack-segments-model-mock.php';

/**
 * Segments on the membership criteria move onto the gate-access criteria.
 *
 * @group Membership_Segments_Migration
 */
class Newspack_Test_Membership_Segments_Migration extends WP_UnitTestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		if ( ! defined( 'NEWSPACK_CONTENT_GATES' ) ) {
			define( 'NEWSPACK_CONTENT_GATES', true );
		}
		WP_CLI::reset();
		Newspack_Segments_Model::$segments = [];
		Newspack_Segments_Model::$updates  = [];
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		Newspack_Segments_Model::$segments = [];
		Newspack_Segments_Model::$updates  = [];
		parent::tear_down();
	}

	/**
	 * Create a published gate whose custom access requires a subscription to the products.
	 *
	 * @param string $title       Gate title.
	 * @param int[]  $product_ids Required product IDs.
	 *
	 * @return int Gate ID.
	 */
	private function create_paid_gate( $title, $product_ids ) {
		$gate_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => $title,
			]
		);
		update_post_meta(
			$gate_id,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => [
					[
						[
							'slug'  => 'subscription',
							'value' => $product_ids,
						],
					],
				],
			]
		);
		return $gate_id;
	}

	/**
	 * Create a Memberships plan post as WooCommerce Memberships stores it. Read
	 * from meta, because the migration runs after Memberships is deactivated.
	 *
	 * @param string $name          Plan name.
	 * @param string $access_method Access method.
	 * @param int[]  $product_ids   Products that grant the plan.
	 *
	 * @return int Plan ID.
	 */
	private function create_plan( $name, $access_method, $product_ids = [] ) {
		$plan_id = $this->factory->post->create(
			[
				'post_type'   => 'wc_membership_plan',
				'post_status' => 'publish',
				'post_title'  => $name,
			]
		);
		update_post_meta( $plan_id, '_access_method', $access_method );
		update_post_meta( $plan_id, '_product_ids', $product_ids );
		return $plan_id;
	}

	/**
	 * Store a segment.
	 *
	 * @param int   $id       Segment ID.
	 * @param array $criteria Criteria.
	 */
	private function add_segment( $id, $criteria ) {
		Newspack_Segments_Model::$segments[ $id ] = [
			'id'            => $id,
			'name'          => "Segment $id",
			'criteria'      => $criteria,
			'configuration' => [ 'is_disabled' => false ],
		];
	}

	/**
	 * Run the command.
	 *
	 * @param array $assoc_args Named args.
	 */
	private function run_command( $assoc_args = [] ) {
		( new Membership_Segments_Migration() )->migrate_membership_segments( [], $assoc_args );
	}

	/**
	 * A plan maps to every paid gate that grants one of its products. The dry run
	 * reports the rewrite and writes nothing; --live writes it and leaves the
	 * segment's other criteria alone; a second run finds nothing left to do.
	 */
	public function test_rewrites_plan_onto_the_gates_granting_its_products() {
		$digital_gate_id = $this->create_paid_gate( 'Digital', [ 101 ] );
		$print_gate_id   = $this->create_paid_gate( 'Print', [ 102 ] );
		$this->create_paid_gate( 'Unrelated', [ 303 ] );
		$plan_id = $this->create_plan( 'Members', 'purchase', [ 101, 102 ] );
		$this->add_segment(
			7,
			[
				[
					'criteria_id' => 'not_active_memberships',
					'value'       => [ $plan_id ],
				],
				[
					'criteria_id' => 'articles_read',
					'value'       => [ 'min' => 3 ],
				],
			]
		);

		$this->run_command();
		$this->assertSame( [], Newspack_Segments_Model::$updates, 'A dry run writes nothing.' );
		$this->assertSame( 'would rewrite', WP_CLI::$tables[0]['items'][0]['result'] );

		$this->run_command( [ 'live' => true ] );
		$this->assertSame(
			[
				[
					'criteria_id' => 'cannot_access_gates',
					'value'       => [ (string) $digital_gate_id, (string) $print_gate_id ],
				],
				[
					'criteria_id' => 'articles_read',
					'value'       => [ 'min' => 3 ],
				],
			],
			Newspack_Segments_Model::$segments[7]['criteria']
		);

		Newspack_Segments_Model::$updates = [];
		$this->run_command( [ 'live' => true ] );
		$this->assertSame( [], Newspack_Segments_Model::$updates, 'A second run has nothing left to rewrite.' );
	}

	/**
	 * A plan that no product grants (free signup, manual assignment) or whose
	 * products no gate requires has no gate equivalent. The criterion is left as
	 * it is and reported, rather than rewritten onto a guess.
	 */
	public function test_leaves_unmappable_plans_in_place_and_reports_them() {
		$this->create_paid_gate( 'Digital', [ 101 ] );
		$signup_plan_id   = $this->create_plan( 'Registered', 'signup' );
		$orphan_plan_id   = $this->create_plan( 'Legacy', 'purchase', [ 999 ] );
		$mappable_plan_id = $this->create_plan( 'Members', 'purchase', [ 101 ] );
		$this->add_segment(
			8,
			[
				[
					'criteria_id' => 'active_memberships',
					'value'       => [ $mappable_plan_id, $signup_plan_id ],
				],
			]
		);
		$this->add_segment(
			9,
			[
				[
					'criteria_id' => 'not_active_memberships',
					'value'       => [ $orphan_plan_id ],
				],
			]
		);

		$this->run_command( [ 'live' => true ] );

		$this->assertSame( [], Newspack_Segments_Model::$updates );
		$rows = WP_CLI::$tables[0]['items'];
		$this->assertStringContainsString( 'Registered', $rows[0]['result'] );
		$this->assertStringContainsString( 'no paid gate', $rows[1]['result'] );
	}

	/**
	 * Where gates and plans do not line up product for product, the segment
	 * matches different readers after the rewrite. It goes ahead, and the report
	 * names each gap: a gate product outside the plan admits non-members, and a
	 * plan product no gate requires drops its holders.
	 */
	public function test_notes_where_gates_and_plan_products_differ() {
		$bundle_gate_id = $this->create_paid_gate( 'Bundle', [ 101, 999 ] );
		$plan_id        = $this->create_plan( 'Members', 'purchase', [ 101, 102 ] );
		$this->add_segment(
			11,
			[
				[
					'criteria_id' => 'not_active_memberships',
					'value'       => [ $plan_id ],
				],
			]
		);

		$this->run_command();

		$this->assertSame(
			sprintf( 'gate %d also accepts product(s) 999; no mapped gate requires plan product(s) 102', $bundle_gate_id ),
			WP_CLI::$tables[0]['items'][0]['notes']
		);
	}

	/**
	 * A gate with several rules in one group grants access only to readers who
	 * hold all of them. The notes judge each plan product by whether it passes a
	 * group on its own, and report a group that admits readers without any plan
	 * product.
	 */
	public function test_notes_judge_multi_rule_groups_by_what_passes_alone() {
		$either_gate_id = $this->create_paid_gate( 'Digital or bundle', [ 101 ] );
		update_post_meta(
			$either_gate_id,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => [
					[ self::subscription_rule( 101 ) ],
					[ self::subscription_rule( 555 ), self::subscription_rule( 556 ) ],
				],
			]
		);
		$together_gate_id = $this->create_paid_gate( 'Digital plus print', [ 101 ] );
		update_post_meta(
			$together_gate_id,
			'custom_access',
			[
				'active'       => true,
				'access_rules' => [ [ self::subscription_rule( 101 ), self::subscription_rule( 557 ) ] ],
			]
		);
		$plan_id = $this->create_plan( 'Members', 'purchase', [ 101 ] );
		$this->add_segment(
			12,
			[
				[
					'criteria_id' => 'not_active_memberships',
					'value'       => [ $plan_id ],
				],
			]
		);

		$this->run_command();

		$this->assertSame(
			sprintf( 'gate %d also accepts product(s) 555, 556; gate %d requires plan product(s) 101 together with other products', $either_gate_id, $together_gate_id ),
			WP_CLI::$tables[0]['items'][0]['notes']
		);
	}

	/**
	 * A subscription rule for one product.
	 *
	 * @param int $product_id Product ID.
	 *
	 * @return array
	 */
	private static function subscription_rule( $product_id ) {
		return [
			'slug'  => 'subscription',
			'value' => [ $product_id ],
		];
	}

	/**
	 * A segment that already uses the target criterion would need the two lists
	 * combined, and folding them into one list turns an AND into an OR. It is left
	 * for a person.
	 */
	public function test_skips_segment_already_using_the_target_criterion() {
		$this->create_paid_gate( 'Digital', [ 101 ] );
		$plan_id = $this->create_plan( 'Members', 'purchase', [ 101 ] );
		$this->add_segment(
			10,
			[
				[
					'criteria_id' => 'active_memberships',
					'value'       => [ $plan_id ],
				],
				[
					'criteria_id' => 'can_access_gates',
					'value'       => [ '1' ],
				],
			]
		);

		$this->run_command( [ 'live' => true ] );

		$this->assertSame( [], Newspack_Segments_Model::$updates );
		$this->assertStringContainsString( 'can_access_gates', WP_CLI::$tables[0]['items'][0]['result'] );
	}
}
