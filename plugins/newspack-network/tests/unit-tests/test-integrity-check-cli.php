<?php
/**
 * Class TestIntegrityCheckCLI
 *
 * @package Newspack_Network
 */

use Newspack_Network\CLI\Integrity_Check;
use Newspack_Network\Integrity_Check_Utils;

/**
 * Test the Integrity Check CLI class.
 */
class TestIntegrityCheckCLI extends WP_UnitTestCase {

	/**
	 * Test hash generation for different scenarios
	 */
	public function test_generate_hash() {

		// Empty data should return empty string.
		$empty_data_hash = Integrity_Check_Utils::generate_hash( [] );
		$this->assertEquals( '', $empty_data_hash );

		// Single item hash.
		$single_membership_data = [
			[
				'email'      => 'test@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'test-plan',
			],
		];
		$single_item_hash = Integrity_Check_Utils::generate_hash( $single_membership_data );
		$expected_single_hash = hash( 'sha256', "test@example.com:wcm-active:test-plan\n" );
		$this->assertEquals( $expected_single_hash, $single_item_hash );

		// Multiple items hash.
		$multiple_memberships_data = [
			[
				'email'      => 'test1@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan-a',
			],
			[
				'email'      => 'test2@example.com',
				'status'     => 'wcm-cancelled',
				'network_id' => 'plan-b',
			],
		];
		$multiple_items_hash = Integrity_Check_Utils::generate_hash( $multiple_memberships_data );
		$expected_multiple_string = "test1@example.com:wcm-active:plan-a\ntest2@example.com:wcm-cancelled:plan-b\n";
		$expected_multiple_hash = hash( 'sha256', $expected_multiple_string );
		$this->assertEquals( $expected_multiple_hash, $multiple_items_hash );

		// Hash consistency - same data should produce same hash.
		$first_consistency_hash = Integrity_Check_Utils::generate_hash( $multiple_memberships_data );
		$second_consistency_hash = Integrity_Check_Utils::generate_hash( $multiple_memberships_data );
		$this->assertEquals( $first_consistency_hash, $second_consistency_hash );
	}

	/**
	 * Test email range filtering
	 */
	public function test_filter_data_by_range() {

		$membership_test_data = [
			[
				'email'      => 'alice@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
			[
				'email'      => 'bob@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
			[
				'email'      => 'charlie@example.com',
				'status'     => 'wcm-cancelled',
				'network_id' => 'plan2',
			],
			[
				'email'      => 'david@example.com',
				'status'     => 'wcm-expired',
				'network_id' => 'plan1',
			],
		];

		// Test filtering by range (alice to david should include all).
		$all_filtered_results = Integrity_Check_Utils::filter_data_by_range( $membership_test_data, 'alice@example.com', 'david@example.com' );
		$this->assertCount( 4, $all_filtered_results );

		// Test filtering by range (b to d should include bob, charlie).
		$partial_filtered_results = Integrity_Check_Utils::filter_data_by_range( $membership_test_data, 'b', 'd' );
		$this->assertCount( 2, $partial_filtered_results );
		$this->assertEquals( 'bob@example.com', $partial_filtered_results[0]['email'] );
		$this->assertEquals( 'charlie@example.com', $partial_filtered_results[1]['email'] );

		// Test case insensitive filtering.
		$case_insensitive_test_data = [
			[
				'email'      => 'Alice@Example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
			[
				'email'      => 'BOB@EXAMPLE.COM',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
		];
		$case_insensitive_results = Integrity_Check_Utils::filter_data_by_range( $case_insensitive_test_data, 'a', 'z' );
		$this->assertCount( 2, $case_insensitive_results );
	}

	/**
	 * Test chunk comparison with no discrepancies
	 */
	public function test_compare_chunk_data_no_discrepancies() {
		$integrity_check_reflection = new ReflectionClass( Integrity_Check::class );
		$compare_chunk_data_method = $integrity_check_reflection->getMethod( 'compare_chunk_data' );
		$compare_chunk_data_method->setAccessible( true );

		$matching_hub_chunk = [
			[
				'email'      => 'test@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
			[
				'email'      => 'user@example.com',
				'status'     => 'wcm-cancelled',
				'network_id' => 'plan2',
			],
		];

		$matching_node_chunk = [
			[
				'email'      => 'test@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
			[
				'email'      => 'user@example.com',
				'status'     => 'wcm-cancelled',
				'network_id' => 'plan2',
			],
		];

		$no_discrepancy_results = $compare_chunk_data_method->invoke( null, $matching_hub_chunk, $matching_node_chunk );
		$this->assertEmpty( $no_discrepancy_results );
	}

	/**
	 * Test chunk comparison with status discrepancies
	 */
	public function test_compare_chunk_data_status_discrepancy() {
		$integrity_check_reflection = new ReflectionClass( Integrity_Check::class );
		$compare_chunk_data_method = $integrity_check_reflection->getMethod( 'compare_chunk_data' );
		$compare_chunk_data_method->setAccessible( true );

		$hub_chunk_with_active_status = [
			[
				'email'      => 'test@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
		];

		$node_chunk_with_cancelled_status = [
			[
				'email'      => 'test@example.com',
				'status'     => 'wcm-cancelled',
				'network_id' => 'plan1',
			],
		];

		$status_discrepancy_results = $compare_chunk_data_method->invoke( null, $hub_chunk_with_active_status, $node_chunk_with_cancelled_status );
		$this->assertCount( 1, $status_discrepancy_results );
		$this->assertEquals( 'test@example.com', $status_discrepancy_results[0]['email'] );
		$this->assertEquals( 'plan1', $status_discrepancy_results[0]['network_id'] );
		$this->assertEquals( 'wcm-active', $status_discrepancy_results[0]['hub_status'] );
		$this->assertEquals( 'wcm-cancelled', $status_discrepancy_results[0]['node_status'] );
	}

	/**
	 * Test chunk comparison with missing memberships
	 */
	public function test_compare_chunk_data_missing_membership() {
		$integrity_check_reflection = new ReflectionClass( Integrity_Check::class );
		$compare_chunk_data_method = $integrity_check_reflection->getMethod( 'compare_chunk_data' );
		$compare_chunk_data_method->setAccessible( true );

		$hub_chunk_with_test_email = [
			[
				'email'      => 'test@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
		];

		$node_chunk_with_different_email = [
			[
				'email'      => 'different@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
		];

		$missing_membership_discrepancies = $compare_chunk_data_method->invoke( null, $hub_chunk_with_test_email, $node_chunk_with_different_email );
		$this->assertCount( 2, $missing_membership_discrepancies );

		// Check for both NOT_FOUND cases.
		$discrepancy_emails = array_column( $missing_membership_discrepancies, 'email' );
		$this->assertContains( 'test@example.com', $discrepancy_emails );
		$this->assertContains( 'different@example.com', $discrepancy_emails );
	}

	/**
	 * Test unique key generation for same email with different network IDs
	 */
	public function test_compare_chunk_data_same_email_different_network_id() {
		$integrity_check_reflection = new ReflectionClass( Integrity_Check::class );
		$compare_chunk_data_method = $integrity_check_reflection->getMethod( 'compare_chunk_data' );
		$compare_chunk_data_method->setAccessible( true );

		$hub_chunk_multiple_plans = [
			[
				'email'      => 'user@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
			[
				'email'      => 'user@example.com',
				'status'     => 'wcm-cancelled',
				'network_id' => 'plan2',
			],
		];

		$node_chunk_multiple_plans = [
			[
				'email'      => 'user@example.com',
				'status'     => 'wcm-active',
				'network_id' => 'plan1',
			],
			[
				'email'      => 'user@example.com',
				'status'     => 'wcm-cancelled',
				'network_id' => 'plan2',
			],
		];

		$multiple_plans_discrepancies = $compare_chunk_data_method->invoke( null, $hub_chunk_multiple_plans, $node_chunk_multiple_plans );
		$this->assertEmpty( $multiple_plans_discrepancies );
	}
}
