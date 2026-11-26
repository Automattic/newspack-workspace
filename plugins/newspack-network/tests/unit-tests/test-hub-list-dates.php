<?php
/**
 * Tests that the hub lists show dates in the site's formats and timezone.
 *
 * @package Newspack_Network_Hub
 */

use Newspack_Network\Hub\Admin\Event_Log_List_Table;
use Newspack_Network\Hub\Admin\Orders;
use Newspack_Network\Hub\Admin\Subscriptions;
use Newspack_Network\Hub\Database\Orders as Orders_DB;
use Newspack_Network\Hub\Database\Subscriptions as Subscriptions_DB;

/**
 * Hub list dates.
 */
class Test_Hub_List_Dates extends WP_UnitTestCase {

	/**
	 * 2026-11-09 20:00 UTC, which is 09:00 on 10 November in Auckland.
	 */
	const GMT = '2026-11-09 20:00:00';

	/**
	 * Use a site ahead of UTC with a non-default date and time format.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'timezone_string', 'Pacific/Auckland' );
		update_option( 'date_format', 'd/m/Y' );
		update_option( 'time_format', 'g:i a' );
	}

	/**
	 * Render a Woo list column and return the echoed HTML.
	 *
	 * @param string $admin_class The list's admin class.
	 * @param string $post_type   The list's post type.
	 * @param string $meta_key    The date meta key.
	 * @param string $column      The column key.
	 * @param string $value       The stored date.
	 * @return string
	 */
	private function render_column( $admin_class, $post_type, $meta_key, $column, $value ) {
		$post_id = self::factory()->post->create( [ 'post_type' => $post_type ] );
		update_post_meta( $post_id, $meta_key, $value );
		ob_start();
		$admin_class::posts_columns_values( $column, $post_id );
		return ob_get_clean();
	}

	/**
	 * An order's GMT creation date shows on the site's calendar day.
	 */
	public function test_order_date_is_in_the_site_timezone() {
		$out = $this->render_column( Orders::class, Orders_DB::POST_TYPE_SLUG, 'date_created', 'date', str_replace( ' ', 'T', self::GMT ) );
		$this->assertSame( '10/11/2026', $out );
	}

	/**
	 * A subscription's GMT start date shows on the site's calendar day.
	 */
	public function test_subscription_date_is_in_the_site_timezone() {
		$out = $this->render_column( Subscriptions::class, Subscriptions_DB::POST_TYPE_SLUG, 'start_date', 'start_date', self::GMT );
		$this->assertSame( '10/11/2026', $out );
	}

	/**
	 * An event's timestamp shows in the site's timezone, keeping am and pm.
	 */
	public function test_event_log_date_is_in_the_site_timezone() {
		$item = new class() {
			/**
			 * The event's Unix timestamp.
			 *
			 * @return int
			 */
			public function get_timestamp() {
				return strtotime( Test_Hub_List_Dates::GMT . ' UTC' );
			}
		};
		$this->assertSame( '10/11/2026 9:00 am', ( new Event_Log_List_Table() )->column_default( $item, 'date' ) );
	}
}
