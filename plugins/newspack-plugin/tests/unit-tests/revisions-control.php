<?php
/**
 * Tests the Revisions Control Newspack functionality.
 *
 * @package Newspack\Tests
 */

use Newspack\Revisions_Control;

/**
 * Test Revisions Control functionality.
 */
class Newspack_Test_Revisions_Control extends WP_UnitTestCase {

	/**
	 * Clean up
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'newspack_revisions_control' );
	}

	/**
	 * Sets the option to a valid value
	 *
	 * @return void
	 */
	public function set_option() {
		update_option(
			'newspack_revisions_control',
			[
				'active'  => true,
				'number'  => 3,
				'min_age' => '-1 week',
			]
		);
	}

	/**
	 * Gets a ReflecionMethod from one of our private methods so we can test them
	 *
	 * @param string $method_name The name of the private method.
	 * @return ReflectionMethod
	 */
	public function get_private_method( $method_name ) {
		$class  = new ReflectionClass( 'Newspack\Revisions_Control' );
		$method = $class->getMethod( $method_name );
		$method->setAccessible( true );
		return $method;
	}

	/**
	 * Data provider for test_get_option
	 *
	 * @return array
	 */
	public function get_option_data() {
		$valid_value = [
			'active'  => true,
			'number'  => 3,
			'min_age' => '-1 week',
		];
		return [
			'no option'            => [
				null,
				[],
			],
			'invalid option'       => [
				'asdsad',
				[],
			],
			'invalid array option' => [
				[
					'active'  => 123,
					'invalid' => true,
				],
				[],
			],
			'valid'                => [
				$valid_value,
				$valid_value,
			],
		];
	}
	/**
	 * Tests the get_option method
	 *
	 * @param mixed $option_value The value to be present in the database.
	 * @param array $expected The expected value.
	 * @return void
	 * @dataProvider get_option_data
	 */
	public function test_get_option( $option_value, $expected ) {
		update_option( 'newspack_revisions_control', $option_value );
		$method = $this->get_private_method( 'get_option' );
		$this->assertSame( $expected, $method->invoke( null ) );
	}

	/**
	 * Data provider for test_get_status
	 *
	 * @return array
	 */
	public function get_status_data() {
		$valid_value    = [
			'active'  => true,
			'number'  => 3,
			'min_age' => '-1 week',
		];
		$invalid_return = [ 'active' => false ];
		return [
			'no option'            => [
				null,
				$invalid_return,
				false,
				null,
				null,
			],
			'invalid option'       => [
				'asdsad',
				$invalid_return,
				false,
				null,
				null,
			],
			'invalid array option' => [
				[
					'active'  => 123,
					'invalid' => true,
				],
				$invalid_return,
				false,
				null,
				null,
			],
			'valid'                => [
				$valid_value,
				$valid_value,
				true,
				3,
				'-1 week',
			],
		];
	}
	/**
	 * Tests the get_status and other get_methods
	 *
	 * @param mixed $option_value The value to be present in the database.
	 * @param array $expected The expected value of get_status.
	 * @param bool  $active The expected value of is_active.
	 * @param mixed $number The expected value of get_number.
	 * @param mixed $min_age The expected value of get_min_age.
	 * @return void
	 * @dataProvider get_status_data
	 */
	public function test_get_status( $option_value, $expected, $active, $number, $min_age ) {
		update_option( 'newspack_revisions_control', $option_value );

		$get_status = $this->get_private_method( 'get_status' );
		$this->assertSame( $expected, $get_status->invoke( null ) );

		$this->assertSame( $active, Revisions_Control::is_active() );

		$get_number = $this->get_private_method( 'get_number' );
		$this->assertSame( $number, $get_number->invoke( null ) );

		$get_min_age = $this->get_private_method( 'get_min_age' );
		$_min_age    = $get_min_age->invoke( null );

		if ( ! is_null( $min_age ) ) {
			$diff = date_diff( new DateTime(), new DateTime( $_min_age ) );
			$this->assertSame( 7, $diff->d );
		} else {
			$this->assertSame( $min_age, $_min_age );
		}
	}

	/**
	 * Tests filter_revisions_to_keep when feature is inactive
	 *
	 * @return void
	 */
	public function test_filter_revisions_to_keep_inactive() {
		$this->assertSame( 10, Revisions_Control::filter_revisions_to_keep( 10 ) );
		$this->assertSame( 20, Revisions_Control::filter_revisions_to_keep( 20 ) );
		$this->assertSame( false, Revisions_Control::filter_revisions_to_keep( false ) );
	}

	/**
	 * Tests filter_revisions_to_keep when feature is active
	 *
	 * @return void
	 */
	public function test_filter_revisions_to_keep_active() {
		$this->set_option();
		$this->assertSame( 3, Revisions_Control::filter_revisions_to_keep( 10 ) );
		$this->assertSame( 3, Revisions_Control::filter_revisions_to_keep( 20 ) );
		$this->assertSame( 3, Revisions_Control::filter_revisions_to_keep( false ) );
	}

	/**
	 * Data source for testing pre_delete_revision
	 *
	 * @return array
	 */
	public function pre_delete_revision_data() {
		return [
			'other old post type' => [
				new WP_Post(
					(object) [
						'post_type' => 'post',
						'post_date' => ( new DateTime( '-1 month' ) )->format( 'Y-m-d H:i:s' ),
					]
				),
				null,
			],
			'new revision'        => [
				new WP_Post(
					(object) [
						'post_type' => 'revision',
						'post_date' => ( new DateTime( '-1 day' ) )->format( 'Y-m-d H:i:s' ),
					]
				),
				true,
			],
			'old revision'        => [
				new WP_Post(
					(object) [
						'post_type' => 'revision',
						'post_date' => ( new DateTime( '-1 month' ) )->format( 'Y-m-d H:i:s' ),
					]
				),
				null,
			],
		];
	}


	/**
	 * Tests the pre_delete_revision hook when the feature is active
	 *
	 * @param WP_Post $post The Post object.
	 * @param mixed   $expected The expected result.
	 *
	 * @return void
	 * @dataProvider pre_delete_revision_data
	 */
	public function test_pre_delete_revision_active( $post, $expected ) {
		$this->set_option();
		$this->assertSame( $expected, Revisions_Control::pre_delete_revision( null, $post ) );
	}


	/**
	 * Tests the pre_delete_revision hook when the feature is active
	 *
	 * @param WP_Post $post The Post object.
	 *
	 * @return void
	 * @dataProvider pre_delete_revision_data
	 */
	public function test_pre_delete_revision_inactive( $post ) {
		$this->assertSame( null, Revisions_Control::pre_delete_revision( null, $post ) );
	}
}
