<?php
/**
 * Tests for the premium lists Woo Memberships reports to the subscribe block.
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Subscription_List;
use Newspack\Newsletters\Subscription_Lists;
use Newspack_Newsletters\Plugins\Woocommerce_Memberships;
use function Newspack_Newsletters\Blocks\Subscribe\get_block_lists;
use function Newspack_Newsletters\Blocks\Subscribe\render_form;

/**
 * Tests Woocommerce_Memberships::filter_list_gates() and the block it feeds.
 *
 * Tests that need Memberships active run in a separate process, because the
 * mock that activates it can't be undeclared for later tests.
 *
 * @group subscribe-block
 */
class Woocommerce_Memberships_List_Gates_Test extends WP_UnitTestCase {

	/**
	 * Use Mailchimp, and start each test with no plans and no members.
	 */
	public function set_up() {
		parent::set_up();
		Newspack_Newsletters::set_service_provider( 'mailchimp' );
		$this->reset_memberships();
	}

	/**
	 * Forget the plans and members a test registered.
	 */
	public function tear_down() {
		$this->reset_memberships();
		parent::tear_down();
	}

	/**
	 * Clear the registered test plans and members.
	 */
	private function reset_memberships() {
		global $test_wc_memberships, $test_wc_memberships_members;
		$test_wc_memberships         = [];
		$test_wc_memberships_members = [];
	}

	/**
	 * Make Memberships count as active for the rest of this process.
	 */
	private function activate_memberships() {
		require_once dirname( __DIR__ ) . '/mocks/wc-memberships-active-mock.php';
	}

	/**
	 * Create a list configured for Mailchimp.
	 *
	 * @param string $title List title.
	 *
	 * @return Subscription_List
	 */
	private function create_list( $title ) {
		$post_id = self::factory()->post->create(
			[
				'post_type'    => Subscription_Lists::CPT,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $title . ' description',
			]
		);
		update_post_meta(
			$post_id,
			Subscription_List::META_KEY,
			[
				'mailchimp' => [
					'list'     => 'mc_list',
					'tag_id'   => $post_id,
					'tag_name' => $title,
				],
			]
		);
		return new Subscription_List( $post_id );
	}

	/**
	 * Register a membership plan whose content rule restricts the given lists.
	 *
	 * @param int                 $plan_id Plan ID.
	 * @param string              $name    Plan name.
	 * @param Subscription_List[] $lists   Lists the plan restricts.
	 */
	private function register_plan( $plan_id, $name, $lists ) {
		global $test_wc_memberships;
		$plan = new WC_Memberships_Membership_Plan( $plan_id, $name );
		$plan->set_content_restriction_rules(
			[
				new WC_Memberships_Membership_Plan_Rule(
					[
						'content_type_name' => Subscription_Lists::CPT,
						'object_id_rules'   => array_map(
							function ( $list ) {
								return $list->get_id();
							},
							$lists
						),
					]
				),
			]
		);
		$test_wc_memberships[] = $plan;
	}

	/**
	 * Give a user a membership in a plan.
	 *
	 * @param int $user_id User ID.
	 * @param int $plan_id Plan ID.
	 */
	private function grant_plan( $user_id, $plan_id ) {
		global $test_wc_memberships_members;
		$test_wc_memberships_members[ $user_id ][] = $plan_id;
	}

	/**
	 * Render the block's markup, with the IDs generated per render made stable.
	 *
	 * @param string[] $list_ids     Public IDs of the block's lists.
	 * @param bool     $show_premium Whether the block shows premium lists to readers without access.
	 *
	 * @return string|null Null when the block renders nothing.
	 */
	private function render( $list_ids, $show_premium ) {
		$attrs = WP_Block_Type_Registry::get_instance()
			->get_registered( 'newspack-newsletters/subscribe' )
			->prepare_attributes_for_render(
				[
					'lists'       => $list_ids,
					'showPremium' => $show_premium,
				]
			);
		$lists = get_block_lists( $attrs );
		if ( null === $lists ) {
			return null;
		}
		$html = render_form( $attrs, $lists );
		$html = preg_replace( '/newspack-newsletters-\d+-/', 'newspack-newsletters-N-', $html );
		$html = preg_replace( '/block-input-\d+/', 'block-input-N', $html );
		return preg_replace( '/newspack-subscribe-\d+/', 'newspack-subscribe-N', $html );
	}

	/**
	 * Each list a plan restricts is reported with its plans, and a free list is
	 * not reported at all. A reader gets no edit links.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_list_gates_report_the_plans_that_restrict_each_list() {
		$this->activate_memberships();
		$digital = $this->create_list( 'Digital Briefing' );
		$both    = $this->create_list( 'Members Weekly' );
		$free    = $this->create_list( 'Free Daily' );
		$this->register_plan( 101, 'Digital', [ $digital, $both ] );
		$this->register_plan( 102, 'Print', [ $both ] );

		$this->assertSame(
			[
				$digital->get_public_id() => [
					[
						'id'    => 101,
						'title' => 'Digital',
					],
				],
				$both->get_public_id()    => [
					[
						'id'    => 101,
						'title' => 'Digital',
					],
					[
						'id'    => 102,
						'title' => 'Print',
					],
				],
			],
			Newspack_Newsletters_Blocks::get_list_gates()
		);
	}

	/**
	 * A plan's edit link goes only to a user who may edit the plan. An Editor,
	 * who can edit others' posts and pages, can't edit plans and gets no link.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_list_gates_link_a_plan_only_for_users_who_can_edit_it() {
		$this->activate_memberships();
		$list = $this->create_list( 'Members Weekly' );
		// The plan post type maps its own capabilities, as Memberships registers it.
		register_post_type(
			'wc_membership_plan',
			[
				'capability_type' => 'membership_plan',
				'map_meta_cap'    => true,
				'show_ui'         => true,
			]
		);
		$plan_id = self::factory()->post->create( [ 'post_type' => 'wc_membership_plan' ] );
		$this->register_plan( $plan_id, 'Digital', [ $list ] );

		// Memberships grants these to administrators and shop managers. An Editor
		// given them stands in for a shop manager: it lacks manage_options, so only
		// the plan's own capabilities can earn it the link.
		$manager = new WP_User( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		foreach ( [ 'edit_membership_plans', 'edit_others_membership_plans', 'edit_published_membership_plans' ] as $cap ) {
			$manager->add_cap( $cap );
		}
		wp_set_current_user( $manager->ID );
		$gate = Newspack_Newsletters_Blocks::get_list_gates()[ $list->get_public_id() ][0];
		$this->assertSame( get_edit_post_link( $plan_id, 'raw' ), $gate['edit_url'] );

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'subscriber' ] ), self::factory()->user->create( [ 'role' => 'editor' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );
			$gate = Newspack_Newsletters_Blocks::get_list_gates()[ $list->get_public_id() ][0];
			$this->assertArrayNotHasKey( 'edit_url', $gate );
		}
	}

	/**
	 * A list Memberships marks as public is not premium, and anyone may join it.
	 * The marker and the access check agree.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_list_marked_public_is_not_gated() {
		$this->activate_memberships();
		$list = $this->create_list( 'Members Weekly' );
		$this->register_plan( 101, 'Digital', [ $list ] );
		$this->assertArrayHasKey( $list->get_public_id(), Newspack_Newsletters_Blocks::get_list_gates(), 'Before it is marked public, the plan gates the list.' );
		$this->assertSame( [], Woocommerce_Memberships::filter_lists( [ $list->get_public_id() ] ), 'Before it is marked public, a reader without the plan cannot join.' );

		update_post_meta( $list->get_id(), '_wc_memberships_force_public', 'yes' );

		$this->assertArrayNotHasKey( $list->get_public_id(), Newspack_Newsletters_Blocks::get_list_gates() );
		$this->assertSame( [ $list->get_public_id() ], Woocommerce_Memberships::filter_lists( [ $list->get_public_id() ] ) );
	}

	/**
	 * A reader without the plan, signed in or not, sees the premium list as a
	 * row they can't join when the block shows premium lists. Nothing in the
	 * markup can submit the list, and the plan's name never reaches the page.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reader_without_the_plan_sees_a_premium_row() {
		$this->activate_memberships();
		$free    = $this->create_list( 'Free Daily' );
		$premium = $this->create_list( 'Members Weekly' );
		$this->register_plan( 101, 'Digital Plan', [ $premium ] );
		$list_ids = [ $free->get_public_id(), $premium->get_public_id() ];

		foreach ( [ 0, self::factory()->user->create( [ 'role' => 'subscriber' ] ) ] as $user_id ) {
			wp_set_current_user( $user_id );

			$html = $this->render( $list_ids, true );
			$this->assertSame( 1, substr_count( $html, 'class="is-gated"' ) );
			$this->assertStringContainsString( 'Members Weekly', $html );
			$this->assertStringContainsString( 'value="' . $free->get_public_id() . '"', $html );
			$this->assertDoesNotMatchRegularExpression( '/' . preg_quote( $premium->get_public_id(), '/' ) . '(?![0-9])/', $html );
			$this->assertStringNotContainsString( 'Digital Plan', $html );

			$html = $this->render( $list_ids, false );
			$this->assertStringNotContainsString( 'class="is-gated"', $html );
			$this->assertStringNotContainsString( 'Members Weekly', $html );
		}
	}

	/**
	 * A member sees the premium list with a checkbox and its badge.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_member_sees_a_premium_list_as_joinable_with_its_badge() {
		$this->activate_memberships();
		$free    = $this->create_list( 'Free Daily' );
		$premium = $this->create_list( 'Members Weekly' );
		$this->register_plan( 101, 'Digital', [ $premium ] );
		$member = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->grant_plan( $member, 101 );
		wp_set_current_user( $member );

		$html = $this->render( [ $free->get_public_id(), $premium->get_public_id() ], true );

		$this->assertStringNotContainsString( 'class="is-gated"', $html );
		$this->assertMatchesRegularExpression( '/name="lists\[\]"\s+value="' . preg_quote( $premium->get_public_id(), '/' ) . '"/', $html );
		$this->assertSame( 2, substr_count( $html, 'class="newspack-ui__badge ' ), 'The premium list gets its badge twice: decorative by the checkbox, and in the title.' );
	}

	/**
	 * The block renders the same markup whether Memberships or Access Control
	 * reports the premium list, so a site can switch without editing its blocks.
	 * Access Control is played by the two filters it answers.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_block_renders_the_same_after_the_switch_to_access_control() {
		$this->activate_memberships();
		$free     = $this->create_list( 'Free Daily' );
		$premium  = $this->create_list( 'Members Weekly' );
		$member   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$reader   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$readers  = [ 0, $reader, $member ];
		$list_ids = [ $free->get_public_id(), $premium->get_public_id() ];

		$this->register_plan( 101, 'Digital', [ $premium ] );
		$this->grant_plan( $member, 101 );
		$under_memberships = [];
		foreach ( $readers as $user_id ) {
			wp_set_current_user( $user_id );
			$under_memberships[ $user_id ] = [ $this->render( $list_ids, true ), $this->render( $list_ids, false ) ];
		}

		// The plans go away with Memberships, and an Access Control gate takes over.
		$this->reset_memberships();
		$hide_from_non_members = function ( $lists ) use ( $premium, $member ) {
			if ( get_current_user_id() === $member ) {
				return $lists;
			}
			return array_values(
				array_filter(
					$lists,
					function ( $list ) use ( $premium ) {
						return $list->get_id() !== $premium->get_id();
					}
				)
			);
		};
		$report_gate           = function ( $list_gates ) use ( $premium ) {
			$list_gates[ $premium->get_public_id() ] = [
				[
					'id'    => 7,
					'title' => 'Digital',
				],
			];
			return $list_gates;
		};
		add_filter( 'newspack_newsletters_subscription_lists', $hide_from_non_members );
		add_filter( 'newspack_newsletters_subscription_list_gates', $report_gate );

		foreach ( $readers as $user_id ) {
			wp_set_current_user( $user_id );
			$this->assertSame( $under_memberships[ $user_id ][0], $this->render( $list_ids, true ), "With showPremium on, reader $user_id sees the same block." );
			$this->assertSame( $under_memberships[ $user_id ][1], $this->render( $list_ids, false ), "With showPremium off, reader $user_id sees the same block." );
		}
	}

	/**
	 * Once Memberships is deactivated it reports nothing, even with plans that
	 * still name the list, so Access Control is the only one answering.
	 */
	public function test_list_gates_report_nothing_once_memberships_is_deactivated() {
		$this->assertFalse( Woocommerce_Memberships::is_enabled(), 'This test needs Memberships inactive in the main process.' );
		$list = $this->create_list( 'Members Weekly' );
		$this->register_plan( 101, 'Digital', [ $list ] );

		$this->assertSame( [], Woocommerce_Memberships::filter_list_gates( [] ) );
		$this->assertSame( [], Newspack_Newsletters_Blocks::get_list_gates() );
	}
}
