<?php
/**
 * Tests the User Gate Access class.
 *
 * @package Newspack\Tests
 */

use Newspack\Access_Rules;
use Newspack\Content_Gate;
use Newspack\Reader_Activation;
use Newspack\User_Gate_Access;

/**
 * Test User Gate Access functionality.
 *
 * @group User_Gate_Access
 */
class Newspack_Test_User_Gate_Access extends WP_UnitTestCase {

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private static $user_id;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private static $admin_id;

	/**
	 * Set up before each test.
	 */
	public function set_up() {
		parent::set_up();
		self::$user_id = $this->factory->user->create(
			[
				'role'       => 'subscriber',
				'user_email' => 'reader@example.com',
			]
		);
		Reader_Activation::set_reader_verified( self::$user_id );
		self::$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Helper to create a gate with custom access rules.
	 *
	 * @param string $title        Gate title.
	 * @param array  $access_rules Access rules array.
	 *
	 * @return int Gate post ID.
	 */
	private function create_gate_with_rules( $title, $access_rules ) {
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
				'access_rules' => $access_rules,
			]
		);
		return $gate_id;
	}

	/**
	 * Test get_custom_access_gates returns only active custom access gates.
	 */
	public function test_get_custom_access_gates_filters_correctly() {
		// Create a gate with custom access active.
		$this->create_gate_with_rules( 'Active Gate', [] );

		// Create a gate without custom access.
		$inactive_id = $this->factory->post->create(
			[
				'post_type'   => Content_Gate::GATE_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Inactive Gate',
			]
		);
		update_post_meta( $inactive_id, 'custom_access', [ 'active' => false ] );

		// Use reflection to test private method.
		$method = new ReflectionMethod( User_Gate_Access::class, 'get_custom_access_gates' );
		$method->setAccessible( true );
		$gates = $method->invoke( null );

		$this->assertCount( 1, $gates, 'Should only return gates with active custom access.' );
		$this->assertEquals( 'Active Gate', reset( $gates )['title'] );
	}

	/**
	 * Test evaluate_gate_for_user with empty rules returns can_bypass true.
	 */
	public function test_evaluate_gate_empty_rules_means_bypass() {
		$gate_id = $this->create_gate_with_rules( 'Empty Gate', [] );
		$gate    = Content_Gate::get_gate( $gate_id );

		$method = new ReflectionMethod( User_Gate_Access::class, 'evaluate_gate_for_user' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $gate, self::$user_id );

		$this->assertTrue( $result['can_bypass'], 'Empty access rules should mean the user can bypass (gate does not restrict).' );
		$this->assertEmpty( $result['groups'] );
	}

	/**
	 * Test evaluate_gate_for_user with email domain rule.
	 */
	public function test_evaluate_gate_email_domain_pass() {
		$rules = [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
		$gate_id = $this->create_gate_with_rules( 'Domain Gate', $rules );
		$gate    = Content_Gate::get_gate( $gate_id );

		$method = new ReflectionMethod( User_Gate_Access::class, 'evaluate_gate_for_user' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $gate, self::$user_id );

		$this->assertTrue( $result['can_bypass'], 'User with example.com email should pass email_domain rule.' );
		$this->assertTrue( $result['groups'][0]['passes'] );
		$this->assertTrue( $result['groups'][0]['rules'][0]['passes'] );
	}

	/**
	 * Test evaluate_gate_for_user with email domain rule - fail case.
	 */
	public function test_evaluate_gate_email_domain_fail() {
		$rules = [
			[
				[
					'slug'  => 'email_domain',
					'value' => 'other.com',
				],
			],
		];
		$gate_id = $this->create_gate_with_rules( 'Domain Gate', $rules );
		$gate    = Content_Gate::get_gate( $gate_id );

		$method = new ReflectionMethod( User_Gate_Access::class, 'evaluate_gate_for_user' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $gate, self::$user_id );

		$this->assertFalse( $result['can_bypass'], 'User with example.com email should fail other.com domain rule.' );
		$this->assertFalse( $result['groups'][0]['passes'] );
	}

	/**
	 * Test metabox only renders for users with manage_options capability.
	 */
	public function test_render_requires_manage_options() {
		wp_set_current_user( self::$user_id ); // Subscriber, no manage_options.
		$user = get_user_by( 'id', self::$user_id );

		ob_start();
		User_Gate_Access::render_user_gate_access( $user );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Should not render for users without manage_options.' );
	}

	/**
	 * Test metabox renders for admin users when gates exist.
	 */
	public function test_render_for_admin() {
		wp_set_current_user( self::$admin_id );
		$this->create_gate_with_rules( 'Test Gate', [] );
		$user = get_user_by( 'id', self::$user_id );

		ob_start();
		User_Gate_Access::render_user_gate_access( $user );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Content Gate Access', $output, 'Should render heading for admin users.' );
	}

	/**
	 * Test metabox does not render when no custom-access gates exist.
	 */
	public function test_render_empty_when_no_gates() {
		wp_set_current_user( self::$admin_id );
		$user = get_user_by( 'id', self::$user_id );

		ob_start();
		User_Gate_Access::render_user_gate_access( $user );
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'Should not render when no custom-access gates exist.' );
	}

	/**
	 * Test OR logic between groups - user passes one group but not another.
	 */
	public function test_evaluate_gate_or_logic_between_groups() {
		$rules = [
			// Group 1: email domain that won't match.
			[
				[
					'slug'  => 'email_domain',
					'value' => 'other.com',
				],
			],
			// Group 2: email domain that will match.
			[
				[
					'slug'  => 'email_domain',
					'value' => 'example.com',
				],
			],
		];
		$gate_id = $this->create_gate_with_rules( 'OR Gate', $rules );
		$gate    = Content_Gate::get_gate( $gate_id );

		$method = new ReflectionMethod( User_Gate_Access::class, 'evaluate_gate_for_user' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $gate, self::$user_id );

		$this->assertTrue( $result['can_bypass'], 'User should pass when at least one group matches (OR logic).' );
		$this->assertFalse( $result['groups'][0]['passes'], 'First group should fail.' );
		$this->assertTrue( $result['groups'][1]['passes'], 'Second group should pass.' );
	}
}
