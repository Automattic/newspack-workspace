<?php
/**
 * Abstract test class to test the common pieces of ESPs.
 *
 * @package Newspack_Newsletters
 */

/**
 * Abstract test class to test the common pieces of ESPs.
 *
 * This class is intended to test the consistency of the providers abstraction layer. It will mostly test
 * if the common methods behave the same way across different ESPs, under the same circumstances.
 *
 * It will not assert specific values, but it will assert that the expected endpoints are reached and that
 * the results are in the same format.
 *
 * Specific tests for each ESP are still required to test the specific behavior of each provider.
 */
abstract class Abstract_ESP_Tests extends WP_UnitTestCase {

	/**
	 * The current provider slug.
	 *
	 * @var string
	 */
	protected static $provider;

	/**
	 * The endpoints reached during the test. Populate this var as mock endpoints are reached.
	 *
	 * @var array
	 */
	protected static $endpoints_reached = [];

	/**
	 * The endopints we expect the test to reach. Populate this var before the test is executed.
	 *
	 * @var array
	 */
	protected static $expected_endpoints_reached = [];

	/**
	 * Set up
	 */
	public function set_up() {
		Newspack_Newsletters::set_service_provider( static::$provider );
		self::$endpoints_reached = [];
		self::$expected_endpoints_reached = [];
	}

	/**
	 * Sets up the required filters for the get_contact_lists test
	 *
	 * @param string $email The email to search for.
	 * @return void
	 */
	abstract public function set_up_test_get_contact_lists( $email );

	/**
	 * Tears down the required filters for the get_contact_lists test
	 *
	 * @param string $email The email to search for.
	 * @return void
	 */
	abstract public function tear_down_test_get_contact_lists( $email );

	/**
	 * Data provider for test_get_contact_lists.
	 */
	public function get_contact_lists_provider() {
		return [
			'Should return 4 lists for the contact'       => [
				'email'    => 'found-4@example.com',
				'expected' => 4,
			],
			'Should return 2 lists for the contact'       => [
				'email'    => 'found@example.com',
				'expected' => 2,
			],
			'Should return no lists for the contact'      => [
				'email'    => 'found-empty@example.com',
				'expected' => 0,
			],
			'Should behave as a non-existent contact'     => [
				'email'    => 'not-found@example.com',
				'expected' => 0,
			],
			'Should simulate an error in the API request' => [
				'email'    => 'failure@example.com',
				'expected' => false,
			],
		];
	}

	/**
	 * Test get_contact_lists.
	 *
	 * @param string    $email    The email to search for.
	 * @param int|false $expected The expected number of lists or false if an error is expected.
	 *
	 * @dataProvider get_contact_lists_provider
	 */
	public function test_get_contact_lists( $email, $expected ) {
		$provider = Newspack_Newsletters::get_service_provider();
		$this->set_up_test_get_contact_lists( $email );

		$lists = $provider->get_contact_lists( $email );
		$this->assertIsArray( $lists );
		$this->assertCount( (int) $expected, $lists ); // get_contact_lists return an empty array in case of an error.
		foreach ( $lists as $list ) {
			$this->assertIsString( $list );
		}

		$this->assertSame( self::$expected_endpoints_reached, self::$endpoints_reached, 'The expected endpoints were not reached or didn\'t include the expected parameters' );

		$this->tear_down_test_get_contact_lists( $email );
	}
}
