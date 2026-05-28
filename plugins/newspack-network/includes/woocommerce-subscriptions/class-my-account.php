<?php
/**
 * Newspack Subscriptions My Account.
 *
 * @package Newspack
 */

namespace Newspack_Network\Woocommerce_Subscriptions;

use Newspack_Network\Incoming_Events\Subscription_Changed;

/**
 * Handles integration for WooCommerce Subscriptions in My Account.
 */
class My_Account {

	/**
	 * The menu item slug.
	 *
	 * @var string
	 */
	const MENU_ENDPOINT = 'np-network-subscriptions';

	/**
	 * Runs the initialization.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'add_menu_item' ], 20 );
		add_filter( 'woocommerce_get_query_vars', [ __CLASS__, 'add_query_var' ] );
		add_action( 'woocommerce_account_np-network-subscriptions_endpoint', [ __CLASS__, 'endpoint_content' ] );
		add_action( 'init', [ __CLASS__, 'flush_rewrite_rules' ] );

		// enqueue a css file to hide the "Subscriptions" menu item.
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_css' ] );
	}

	/**
	 * Enqueue a CSS file to hide the "Subscriptions" menu item.
	 */
	public static function enqueue_css() {
		// Only if visiting the My Account page.
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_enqueue_style( 'newspack-network-subscriptions-my-account', plugins_url( '/my-account.css', __FILE__ ), [], filemtime( NEWSPACK_NETWORK_PLUGIN_DIR . 'includes/woocommerce-subscriptions/my-account.css' ) );
	}

	/**
	 * Flush rewrite rules for MENU_ENDPOINT.
	 */
	public static function flush_rewrite_rules() {
		$option_name = 'newspack_network_subscriptions_has_flushed_rewrite_rules';
		if ( ! get_option( $option_name ) ) {
			flush_rewrite_rules(); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.flush_rewrite_rules_flush_rewrite_rules
			add_option( $option_name, true );
		}
	}

	/**
	 * Add query var for the My Account endpoint.
	 *
	 * @param array $vars Query var.
	 *
	 * @return array
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::MENU_ENDPOINT;
		return $vars;
	}

	/**
	 * Adds the menu item to the My Account page.
	 *
	 * @param array $items The menu items.
	 * @return array The menu items.
	 */
	public static function add_menu_item( $items ) {

		$user = wp_get_current_user();
		$network_subscriptions = get_user_meta( $user->ID, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );

		if ( empty( $network_subscriptions ) ) {
			return $items;
		}

		// Check if user has any subscrioption on this site.
		$has_local_subscriptions = false;
		$menu_position = 1;
		if ( function_exists( 'wcs_get_users_subscriptions' ) ) {
			$this_site_subscriptions = wcs_get_users_subscriptions( $user->ID );
			if ( ! empty( $this_site_subscriptions ) ) {
				$has_local_subscriptions = true;
			}
		}

		$menu_entry = $has_local_subscriptions ? __( 'Other Subscriptions', 'newspack-network' ) : __( 'Subscriptions', 'newspack-network' );

		$items = array_slice( $items, 0, $menu_position, true ) +
			[ self::MENU_ENDPOINT => $menu_entry ] +
			array_slice( $items, $menu_position, null, true );

		return $items;
	}

	/**
	 * Outputs the content for the My Account endpoint.
	 */
	public static function endpoint_content() {
		$user = wp_get_current_user();
		$network_subscriptions = get_user_meta( $user->ID, Subscription_Changed::USER_SUBSCRIPTIONS_META_KEY, true );
		if ( empty( $network_subscriptions ) ) {
			return;
		}

		// We assume that all sites in the network have the same My Account page configuration.
		$my_account_prefix_pattern = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );

		?>
		<div id="newspack-network-subscriptions" class="newspack-ui">
			<p>
				<?php esc_html_e( 'Here you can view and manage the subscriptions you have purchased in other sites in our network.', 'newspack-network' ); ?>
			</p>
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Subscription', 'newspack-network' ); ?></th>
						<th class="newspack-network-subscriptions-status"><?php esc_html_e( 'Status', 'newspack-network' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $network_subscriptions as $site => $subscriptions ) { ?>
						<?php
						$my_account_prefix = str_replace( home_url(), $site, $my_account_prefix_pattern );
						$site_label = str_replace( 'https://', '', $site );
						?>
						<?php foreach ( $subscriptions as $subscription ) { ?>
							<?php
							$sub_id = $subscription['id'];
							$sub_status = $subscription['status'];
							$sub_product = array_pop( $subscription['products'] );
							$sub_product_name = $sub_product['name'];
							$sub_product_url = $my_account_prefix . 'view-subscription/' . $sub_id;
							?>
							<tr>
								<?php // translators: %1$s is the subscription name, %2$s is the site label where the subscription was purchased. ?>
								<td><?php echo esc_html( sprintf( __( '%1$s (%2$s) on %3$s', 'newspack-network' ), $sub_product_name, '#' . $sub_id, $site_label ) ); ?></td>
								<td class="newspack-network-subscriptions-status"><?php echo esc_html( $sub_status ); ?></td>
								<td>
									<a href="<?php echo esc_url( $sub_product_url ); ?>" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide">
										<?php esc_html_e( 'Manage', 'newspack-network' ); ?>
									</a>
								</td>
							</tr>
						<?php } ?>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
