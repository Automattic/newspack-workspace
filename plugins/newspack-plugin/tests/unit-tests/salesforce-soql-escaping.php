<?php
/**
 * Tests escaping of values interpolated into Salesforce SOQL queries.
 *
 * @package Newspack\Tests
 */

use Newspack\Salesforce;

require_once __DIR__ . '/../mocks/wc-mocks.php';

/**
 * Tests escaping of values interpolated into Salesforce SOQL queries.
 *
 * Emails and product names originate from reader input, so a stray apostrophe
 * must not be able to terminate the SOQL string literal it lands in.
 */
class Newspack_Test_Salesforce_Soql_Escaping extends WP_UnitTestCase {
	/**
	 * URLs of intercepted Salesforce API requests.
	 *
	 * @var array
	 */
	private $requested_urls = [];

	public function set_up() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		parent::set_up();
		global $orders_database;
		$orders_database      = [];
		$this->requested_urls = [];
		update_option( Salesforce::SALESFORCE_INSTANCE_URL, 'https://newspack.my.salesforce.com' );
		update_option( Salesforce::SALESFORCE_ACCESS_TOKEN, 'test-access-token' );
		add_filter( 'pre_http_request', [ $this, 'intercept_request' ], 10, 3 );
	}

	/**
	 * Intercept outgoing Salesforce API requests, recording the URL and
	 * returning an empty successful query response.
	 *
	 * @param false|array $preempt     Whether to preempt the request.
	 * @param array       $parsed_args Request arguments.
	 * @param string      $url         Request URL.
	 * @return array Mocked HTTP response.
	 */
	public function intercept_request( $preempt, $parsed_args, $url ) {
		$this->requested_urls[] = $url;
		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'totalSize' => 0,
					'records'   => [],
				]
			),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
		];
	}

	/**
	 * Get the SOQL query sent in the last intercepted request.
	 *
	 * @return string The value of the `q` query param.
	 */
	private function get_last_soql_query() {
		$this->assertNotEmpty( $this->requested_urls, 'A Salesforce API request was made.' );
		wp_parse_str( (string) wp_parse_url( end( $this->requested_urls ), PHP_URL_QUERY ), $params );
		return $params['q'] ?? '';
	}

	/**
	 * Call a private static method on the Salesforce class.
	 *
	 * @param string $method  Method name.
	 * @param mixed  ...$args Method arguments.
	 * @return mixed Method return value.
	 */
	private static function call_salesforce( $method, ...$args ) {
		$reflection = new ReflectionMethod( Salesforce::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invoke( null, ...$args );
	}

	/**
	 * Build an order item for the given product name, backed by a mock order.
	 *
	 * @param string $name Product (line item) name.
	 * @return array The order id and the parsed order item.
	 */
	private function create_order_item( $name ) {
		$order = wc_create_order( [ 'status' => 'pending' ] );
		return [
			$order->get_id(),
			[
				'Amount'      => '25.00',
				'CloseDate'   => '2026-08-25',
				'Description' => 'WooCommerce Order Number: ' . $order->get_id(),
				'Name'        => $name,
				'StageName'   => 'Closed Won',
			],
		];
	}

	/**
	 * An apostrophe in an email must not terminate the SOQL string literal.
	 */
	public function test_contact_query_escapes_apostrophe_in_email() {
		self::call_salesforce( 'get_contacts_by_email', "o'brien@example.com" );

		$this->assertSame(
			"SELECT Id, FirstName, LastName, Description FROM Contact WHERE Email = 'o\\'brien@example.com'",
			$this->get_last_soql_query()
		);
	}

	/**
	 * A backslash in an email must not escape the closing quote of the literal.
	 */
	public function test_contact_query_escapes_backslash_in_email() {
		self::call_salesforce( 'get_contacts_by_email', 'trailing\\' );

		$this->assertSame(
			"SELECT Id, FirstName, LastName, Description FROM Contact WHERE Email = 'trailing\\\\'",
			$this->get_last_soql_query()
		);
	}

	/**
	 * Backslashes are escaped before quotes: a `\'` sequence becomes `\\\'`,
	 * not `\\\\'` (which would leave the quote unescaped).
	 */
	public function test_contact_query_escapes_backslash_before_quote() {
		self::call_salesforce( 'get_contacts_by_email', "evil\\'@example.com" );

		$this->assertSame(
			"SELECT Id, FirstName, LastName, Description FROM Contact WHERE Email = 'evil\\\\\\'@example.com'",
			$this->get_last_soql_query()
		);
	}

	/**
	 * An apostrophe in a product name must not terminate the SOQL string literal.
	 */
	public function test_opportunity_query_escapes_apostrophe_in_product_name() {
		list( $order_id, $order_item ) = $this->create_order_item( "Season's Pass" );
		self::call_salesforce( 'get_opportunity_by_order_id', $order_id, $order_item );

		$this->assertSame(
			"SELECT Id, Description FROM Opportunity WHERE Name = 'Season\\'s Pass' AND Amount = 25.00 AND CloseDate = 2026-08-25",
			$this->get_last_soql_query()
		);
	}

	/**
	 * A backslash in a product name must not escape the closing quote of the literal.
	 */
	public function test_opportunity_query_escapes_backslash_in_product_name() {
		list( $order_id, $order_item ) = $this->create_order_item( 'Wildcard \\ Pass' );
		self::call_salesforce( 'get_opportunity_by_order_id', $order_id, $order_item );

		$this->assertSame(
			"SELECT Id, Description FROM Opportunity WHERE Name = 'Wildcard \\\\ Pass' AND Amount = 25.00 AND CloseDate = 2026-08-25",
			$this->get_last_soql_query()
		);
	}

	/**
	 * Opportunity ids stored in order meta are escaped in the IN (...) clause.
	 */
	public function test_opportunity_query_escapes_stored_opportunity_ids() {
		list( , $order_item ) = $this->create_order_item( 'Donation' );
		$order                = wc_create_order(
			[
				'status' => 'pending',
				'meta'   => [ 'newspack_salesforce_opportunities' => [ "006'ABC" ] ],
			]
		);
		self::call_salesforce( 'get_opportunity_by_order_id', $order->get_id(), $order_item );

		$this->assertSame(
			"SELECT Id, Description FROM Opportunity WHERE Id IN ('006\\'ABC')",
			$this->get_last_soql_query()
		);
	}
}
