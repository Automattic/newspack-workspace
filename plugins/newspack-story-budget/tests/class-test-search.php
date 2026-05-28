<?php
/**
 * Tests for Search functionality.
 *
 * @package Newspack_Story_Budget
 */

namespace Newspack_Story_Budget;

use WP_UnitTestCase;

/**
 * Test Search class.
 */
class Test_Search extends WP_UnitTestCase {
	/**
	 * Test wp_admin_search_where method.
	 */
	public function test_wp_admin_search_where() {
		global $wpdb;

		// Create a mock WP_Query object.
		$query = $this->getMockBuilder( 'WP_Query' )
			->disableOriginalConstructor()
			->getMock();
		$query->query_vars['s'] = 'test search';

		// Register a test searchable field.
		add_filter(
			'newspack_story_budget_fields',
			function( $fields ) {
				$fields[] = [
					'slug'          => 'test-field',
					'name'          => 'Test Field',
					'is_searchable' => true,
					'type'          => 'text',
				];
				return $fields;
			}
		);

		// Initialize fields.
		Fields::init();

		// Test 1: Basic WHERE clause.
		$where = " AND ((({$wpdb->posts}.post_title LIKE '%test search%') OR ({$wpdb->posts}.post_excerpt LIKE '%test search%')))";
		$result = Search::wp_admin_search_where( $where, $query );
		$expected = " AND ((({$wpdb->postmeta}.meta_key IN ('newspack_story_budget_test-field') AND {$wpdb->postmeta}.meta_value LIKE '%test search%') OR ({$wpdb->posts}.post_title LIKE '%test search%') OR ({$wpdb->posts}.post_excerpt LIKE '%test search%')))";
		$this->assertEquals( $expected, $result );

		// Test 2: WHERE clause with whitespace variations.
		$where = " AND (((\n  {$wpdb->posts}.post_title LIKE '%test search%') OR ({$wpdb->posts}.post_excerpt LIKE '%test search%')))";
		$result = Search::wp_admin_search_where( $where, $query );
		$this->assertStringContainsString( "{$wpdb->postmeta}.meta_key IN ('newspack_story_budget_test-field')", $result );

		// Test 3: Empty search term.
		$query->query_vars['s'] = '';
		$where = " AND ((({$wpdb->posts}.post_title LIKE '%%') OR ({$wpdb->posts}.post_excerpt LIKE '%%')))";
		$result = Search::wp_admin_search_where( $where, $query );
		$this->assertEquals( $where, $result );

		// Test 4: No searchable fields.
		add_filter(
			'newspack_story_budget_fields',
			function( $fields ) {
				return array_map(
					function( $field ) {
						$field['is_searchable'] = false;
						return $field;
					},
					$fields
				);
			}
		);
		Fields::init();
		$query->query_vars['s'] = 'test search';
		$where = " AND ((({$wpdb->posts}.post_title LIKE '%test search%') OR ({$wpdb->posts}.post_excerpt LIKE '%test search%')))";
		$result = Search::wp_admin_search_where( $where, $query );
		$this->assertEquals( $where, $result );

		// Test 5: Special characters in search term.
		$query->query_vars['s'] = "test's search";
		$where = " AND ((({$wpdb->posts}.post_title LIKE '%test\'s search%') OR ({$wpdb->posts}.post_excerpt LIKE '%test\'s search%')))";
		$result = Search::wp_admin_search_where( $where, $query );
		$this->assertStringContainsString( "LIKE '%test\'s search%'", $result );
	}

	/**
	 * Test should_add_fields_to_wp_admin_search method.
	 */
	public function test_should_add_fields_to_wp_admin_search() {
		global $pagenow;

		// Create a mock WP_Query object.
		$query = $this->getMockBuilder( 'WP_Query' )
			->disableOriginalConstructor()
			->getMock();

		// Test non-admin context.
		$pagenow = 'index.php'; // phpcs:ignore
		$query->query_vars['s'] = 'test';
		$query->query_vars['story_budget_search'] = false;
		$this->assertFalse( $this->invoke_protected_method( 'should_add_fields_to_wp_admin_search', [ $query ] ) );

		// Test admin context but wrong page.
		$pagenow = 'post.php'; // phpcs:ignore
		set_current_screen( 'admin' );
		$query->query_vars['s'] = 'test';
		$query->query_vars['story_budget_search'] = false;
		$this->assertFalse( $this->invoke_protected_method( 'should_add_fields_to_wp_admin_search', [ $query ] ) );

		// Test correct page but no search term.
		$pagenow = 'edit.php'; // phpcs:ignore
		$query->query_vars['s'] = '';
		$query->query_vars['story_budget_search'] = false;
		$this->assertFalse( $this->invoke_protected_method( 'should_add_fields_to_wp_admin_search', [ $query ] ) );

		// Test all conditions met for WP admin search.
		$query->query_vars['s'] = 'test';
		$query->query_vars['story_budget_search'] = false;
		$query->is_main_query = true;
		$this->assertTrue( $this->invoke_protected_method( 'should_add_fields_to_wp_admin_search', [ $query ] ) );

		// Test story_budget_search parameter.
		$pagenow = 'not-index.php'; // phpcs:ignore
		$query->query_vars['s'] = 'test';
		$query->query_vars['story_budget_search'] = true;
		$this->assertTrue( $this->invoke_protected_method( 'should_add_fields_to_wp_admin_search', [ $query ] ) );

		// Test story_budget_search parameter with empty search term.
		$query->query_vars['s'] = '';
		$query->query_vars['story_budget_search'] = true;
		$this->assertFalse( $this->invoke_protected_method( 'should_add_fields_to_wp_admin_search', [ $query ] ) );
	}

	/**
	 * Helper method to invoke protected/private methods.
	 *
	 * @param string $method_name Method name to call.
	 * @param array  $parameters Array of parameters to pass into method.
	 * @return mixed Method return value.
	 */
	private function invoke_protected_method( $method_name, $parameters = [] ) {
		$reflection = new \ReflectionClass( Search::class );
		$method = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( null, $parameters );
	}
}
