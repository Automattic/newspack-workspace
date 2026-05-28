<?php
/**
 * Adds an admin notice when possibly duplicated orders are detected.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an admin notice when possibly duplicated orders are detected.
 */
class WooCommerce_Duplicate_Orders {
	const CRON_HOOK_NAME = 'newspack_wc_check_order_duplicates';
	const ADMIN_NOTICE_TRANSIENT_NAME = 'newspack_wc_check_order_duplicates_admin_notice';
	const DUPLICATED_ORDERS_OPTION_NAME = 'newspack_wc_order_duplicates';
	const DISMISSED_DUPLICATES_OPTION_NAME = 'newspack_wc_order_duplicates_dismissed';

	/**
	 * Initialize.
	 *
	 * @codeCoverageIgnore
	 */
	public static function init(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK_NAME ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK_NAME );
		}
		add_action( self::CRON_HOOK_NAME, [ __CLASS__, 'check_for_order_duplicates' ] );
		add_action( 'admin_notices', [ __CLASS__, 'display_admin_notice' ] );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'newspack detect-order-duplicates', [ __CLASS__, 'cli_upsert_order_duplicates' ] );
		}
	}

	/**
	 * Detect duplicate orders.
	 * Duplicates will be detected if it the same amount, same day, from the same customer.
	 *
	 * @param number $cutoff_time The cutoff time in the past (how many seconds ago).
	 * @param number $current_page Current page of results.
	 * @param array  $results Results to be merged with new results.
	 */
	public static function get_order_duplicates( $cutoff_time, $current_page = 0, $results = [] ): array {

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$per_page = 100;
		$order_result = wc_get_orders(
			[
				'paginate'       => true,
				'limit'          => $per_page,
				'status'         => [ 'wc-completed' ],
				'offset'         => $current_page * $per_page,
				'date_completed' => '>' . ( time() - $cutoff_time ),
			]
		);

		if ( defined( 'WP_CLI' ) && WP_CLI && $order_result->max_num_pages > 0 ) {
			\WP_CLI::line( sprintf( 'Processing page %d/%d of orders.', $current_page + 1, $order_result->max_num_pages ) );
		}

		$orders = $order_result->orders;
		$order_duplicates = [];

		foreach ( $orders as $order ) {
			$email = $order->get_billing_email();
			$amount = $order->get_total();
			$date = $order->get_date_created()->date( 'Y-m-d' );

			if ( \wcs_order_contains_renewal( $order ) || \wcs_order_contains_resubscribe( $order ) ) {
				continue;
			}

			if ( ! isset( $order_duplicates[ $email ] ) ) {
				$order_duplicates[ $email ] = [];
			}

			if ( ! isset( $order_duplicates[ $email ][ $amount ] ) ) {
				$order_duplicates[ $email ][ $amount ] = [];
			}

			if ( ! isset( $order_duplicates[ $email ][ $amount ][ $date ] ) ) {
				$order_duplicates[ $email ][ $amount ][ $date ] = [];
			}

			$order_duplicates[ $email ][ $amount ][ $date ][] = $order->get_id();
		}

		foreach ( $order_duplicates as $email => $amounts ) {
			foreach ( $amounts as $amount => $dates ) {
				foreach ( $dates as $date => $order_ids ) {
					if ( count( $order_ids ) > 1 ) {
						sort( $order_ids );
						$ids = implode( ',', $order_ids );
						$results[ $ids ] = [
							'email'  => $email,
							'amount' => $amount,
							'date'   => $date,
							'ids'    => $ids,
						];
					}
				}
			}
		}

		if ( $order_result->total > 0 ) {
			$current_page++;
			return self::get_order_duplicates( $cutoff_time, $current_page, $results );
		}

		return $results;
	}

	/**
	 * Check for duplicate orders and save the result in an option.
	 *
	 * @param number $cutoff_time The cutoff time in the past (how many seconds ago).
	 * @param bool   $save Whether to save the result as the option.
	 * @param bool   $upsert Whether to upsert the option (merge with existing).
	 */
	public static function check_for_order_duplicates( $cutoff_time = DAY_IN_SECONDS, $save = true, $upsert = true ): array {
		$order_duplicates = self::get_order_duplicates( $cutoff_time );
		if ( empty( $order_duplicates ) ) {
			return [];
		}
		if ( $save ) {
			if ( $upsert ) {
				$existing_order_duplicates = get_option( self::DUPLICATED_ORDERS_OPTION_NAME, [] );
				foreach ( $existing_order_duplicates as $key => $value ) {
					if ( isset( $order_duplicates[ $key ] ) ) {
						continue;
					}
					$order_duplicates[ $key ] = $value;
				}
			}
			update_option( self::DUPLICATED_ORDERS_OPTION_NAME, $order_duplicates );
		}
		return $order_duplicates;
	}

	/**
	 * Display an admin notice if duplicate orders are found.
	 */
	public static function display_admin_notice(): void {
		if ( ! function_exists( 'wc_price' ) ) {
			return;
		}
		$existing_order_duplicates = get_option( self::DUPLICATED_ORDERS_OPTION_NAME, [] );
		if ( empty( $existing_order_duplicates ) ) {
			return;
		}
		$dismissed_duplicates = get_option( self::DISMISSED_DUPLICATES_OPTION_NAME, [] );

		$orders_to_display = array_filter(
			$existing_order_duplicates,
			function( $order_duplicates ) use ( $dismissed_duplicates ) {
				return ! in_array( $order_duplicates['ids'], $dismissed_duplicates );
			}
		);

		if ( empty( $orders_to_display ) ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<!-- Admin notice added by newspack-plugin -->
			<details>
				<summary style="margin: 0.6em 0; cursor: pointer;">
					<?php echo esc_html__( 'There are some potentially duplicate transactions to review. Some of these might be intentional. Click this message to display the list of possible duplicates.', 'newspack-plugin' ); ?>
				</summary>
				<ul>
					<?php foreach ( $orders_to_display as $order_duplicates ) : ?>
						<li style="display: flex; align-items: center;">
							<p style="margin: 0;">

							<?php
							ob_start();
							?>
								<a href="<?php echo esc_url( admin_url( 'edit.php?s=' . urlencode( $order_duplicates['email'] ) . '&post_type=shop_order' ) ); ?>"><?php echo esc_html( $order_duplicates['email'] ); ?></a>
							<?php
							$customer_email = ob_get_clean();

							ob_start();
							$order_ids = explode( ',', $order_duplicates['ids'] );
							foreach ( $order_ids as $index => $order_id ) :
								$order_url = admin_url( 'post.php?post=' . intval( $order_id ) . '&action=edit' );
								?>
									<a href="<?php echo esc_url( $order_url ); ?>"><?php echo esc_html( $order_id ); ?></a><?php echo ( $index < count( $order_ids ) - 1 ) ? ', ' : ''; ?>
								<?php
							endforeach;
							$order_list = ob_get_clean();

							printf(
								/* translators: 1: customer email, 2: order amount, 3: orders date, 4: order IDs */
								wp_kses_post( __( 'Customer %1$s made multiple orders of %2$s on %3$s. Orders: %4$s.', 'newspack-plugin' ) ),
								wp_kses_post( $customer_email ),
								wp_kses_post( \wc_price( $order_duplicates['amount'] ) ),
								esc_html( date_i18n( get_option( 'date_format' ), strtotime( $order_duplicates['date'] ) ) ),
								wp_kses_post( trim( $order_list ) )
							);

							$order_duplicates_id = implode( '-', $order_ids );
						?>
							</p>
							<form method="post" style="display:inline; margin-left: 8px;">
								<input type="hidden" name="dismiss_order_ids" value="<?php echo esc_attr( $order_duplicates['ids'] ); ?>">
								<?php submit_button( __( 'Dismiss', 'newspack-plugin' ), 'small', 'dismiss_order', false, [ 'id' => 'dismiss_order_duplicates_' . $order_duplicates_id ] ); ?>
							</form>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		</div>
		<?php
		if ( isset( $_POST['dismiss_order'] ) && isset( $_POST['dismiss_order_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$dismissed_duplicates[] = $_POST['dismiss_order_ids']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			update_option( self::DISMISSED_DUPLICATES_OPTION_NAME, $dismissed_duplicates );
			// Refresh the page to reflect changes.
			wp_safe_redirect( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : admin_url() );
			exit;
		}
	}

	/**
	 * CLI handler to search for duplicates and optionally store this info to be displayed in the admin panel.
	 *
	 * ## OPTIONS
	 *
	 * [--cutoff_time=<time-string>]
	 * : The cutoff time in the past (e.g. "2 months").
	 *
	 * [--save]
	 * : Whether to save the results for display in the admin panel.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack detect-order-duplicates --cutoff_time='2 months' --save
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public static function cli_upsert_order_duplicates( $args, $assoc_args ) {
		$cutoff_time_str = isset( $assoc_args['cutoff_time'] ) ? $assoc_args['cutoff_time'] : '1 month';
		$cutoff_time = strtotime( $cutoff_time_str ) - time();
		$save_as_option = isset( $assoc_args['save'] ) ? $assoc_args['save'] : false;

		$duplicates = self::check_for_order_duplicates( $cutoff_time, $save_as_option );

		if ( empty( $duplicates ) ) {
			\WP_CLI::success( 'No duplicate orders found.' );
		} else {
			\WP_CLI::success( sprintf( '%d duplicate order series found.', count( $duplicates ) ) );
		}
	}
}

WooCommerce_Duplicate_Orders::init();
