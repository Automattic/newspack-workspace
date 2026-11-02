<?php
/**
 * Newspack Group Subscriptions - post-checkout prompt.
 *
 * @package Newspack
 */

namespace Newspack;

defined( 'ABSPATH' ) || exit;

/**
 * Points the buyer of a group subscription at the group's page from the order
 * confirmation, so filling the seats they just paid for is the next step rather
 * than something to find in My Account later.
 */
class Group_Subscription_Checkout_Prompt {
	/**
	 * Click handler for the modal's link.
	 *
	 * The modal renders in an iframe, and leaving it by plain navigation skips the
	 * modal's close flow: the post-checkout newsletter signup never opens, and the
	 * pending checkout Reader Activation keeps would reopen this cart on a later
	 * login. So the link hands the group page to the modal as its after-success
	 * destination and closes it, the same way a configured redirect does. Without
	 * the modal script, `target="_top"` still takes the reader there.
	 */
	const MODAL_LINK_ONCLICK = "if(!parent.newspackCloseModalCheckout){return true;}[['after_success_url',this.href],['after_success_behavior','custom']].forEach(function(f){var i=document.querySelector('input[name=\"'+f[0]+'\"]');if(!i){i=document.createElement('input');i.type='hidden';i.name=f[0];document.body.appendChild(i);}i.setAttribute('value',f[1]);});parent.newspackCloseModalCheckout();return false;";

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		// The group page this prompt links to exists only behind the Access Control feature flag.
		if ( ! Content_Gate::is_newspack_feature_enabled() ) {
			return;
		}
		\add_action( 'woocommerce_before_thankyou', [ __CLASS__, 'render_for_order_id' ], 10, 1 );
		// The modal checkout replaces WooCommerce's thank-you template, so the hook above
		// never fires inside the modal. Its template fires this one only for a valid order key.
		// The hook's name really is spelled "newpack"; correcting it here disconnects the modal.
		\add_action( 'newpack_blocks_modal_checkout_thankyou', [ __CLASS__, 'render_for_modal_checkout' ], 10, 0 );
	}

	/**
	 * Render the prompt on WooCommerce's order-received page.
	 *
	 * @param int|string $order_id The order ID.
	 */
	public static function render_for_order_id( int|string $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( $order ) {
			echo self::get_prompt_html( $order, \get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Render the prompt on the modal checkout's confirmation screen.
	 */
	public static function render_for_modal_checkout(): void {
		$order_id = absint( \get_query_var( 'order-received' ) );
		$order    = $order_id ? \wc_get_order( $order_id ) : false;
		if ( $order ) {
			echo self::get_prompt_html( $order, \get_current_user_id(), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Get the group subscriptions an order created that a user can manage.
	 *
	 * A manager check, not an owner check: it keeps the prompt off a confirmation
	 * page viewed by anyone the group page would turn away. A group still waiting on
	 * payment, such as one bought by check, is left out too: it can't take invites
	 * until it is active.
	 *
	 * @param \WC_Order|false $order   The order.
	 * @param int             $user_id The viewing user's ID.
	 *
	 * @return \WC_Subscription[] The subscriptions.
	 */
	public static function get_managed_group_subscriptions( $order, int $user_id ): array {
		if ( ! $user_id || ! $order instanceof \WC_Order || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return [];
		}
		// The prompt shows only where the group page it links to would render. A reader
		// whose payment didn't verify their email (an offline gateway, say) would find a
		// verification prompt there instead of the invite form.
		if ( ! Group_Subscription_MyAccount::is_group_page_available() || WooCommerce_My_Account::is_account_locked_for_user( $user_id ) ) {
			return [];
		}
		return array_values(
			array_filter(
				\wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'parent' ] ),
				fn ( $subscription ): bool => Group_Subscription::is_group_subscription( $subscription )
					&& Group_Subscription_MyAccount::is_subscription_active( $subscription )
					&& Group_Subscription::user_is_manager( $user_id, $subscription )
			)
		);
	}

	/**
	 * Get the prompt markup for an order, or an empty string when there is nothing to prompt.
	 *
	 * @param \WC_Order|false $order    The order.
	 * @param int             $user_id  The viewing user's ID.
	 * @param bool            $is_modal Whether the markup renders inside the modal checkout.
	 *
	 * @return string The markup.
	 */
	public static function get_prompt_html( $order, int $user_id, bool $is_modal = false ): string {
		$subscriptions = self::get_managed_group_subscriptions( $order, $user_id );
		if ( empty( $subscriptions ) ) {
			return '';
		}
		$label        = Group_Subscription::get_label_lower( 'singular' );
		$box_class    = $is_modal ? 'newspack-ui__box newspack-ui__box--border newspack-ui__spacing-top--5' : 'woocommerce-info';
		$button_class = $is_modal ? 'newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide' : 'button';

		ob_start();
		foreach ( $subscriptions as $subscription ) {
			$settings     = Group_Subscription_Settings::get_subscription_settings( $subscription );
			$capacity     = Group_Subscription::get_member_capacity( $subscription );
			$available    = null === $capacity ? null : max( 0, $capacity - Group_Subscription_Seats::get_occupancy( $subscription ) );
			/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
			$button_label = sprintf( __( 'Add %s members', 'newspack-plugin' ), $label );
			if ( null === $available ) {
				/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
				$seats_text = sprintf( __( 'Your %s has no seat limit.', 'newspack-plugin' ), $label );
			} elseif ( 0 === $available ) {
				// Invites would be refused, so point at the page rather than at adding people.
				/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
				$seats_text = sprintf( __( 'Every seat in your %s is taken.', 'newspack-plugin' ), $label );
				/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
				$button_label = sprintf( __( 'Manage your %s', 'newspack-plugin' ), $label );
			} else {
				$seats_text = sprintf(
					/* translators: 1: number of seats available, 2: lowercase singular group label (e.g. "group", "team"). */
					_n( '%1$s seat is available in your %2$s.', '%1$s seats are available in your %2$s.', $available, 'newspack-plugin' ),
					number_format_i18n( $available ),
					$label
				);
			}
			// An order can create more than one group, so each link names its group for screen readers.
			$name_id = 'newspack-group-subscription-checkout-prompt-' . $subscription->get_id();
			?>
			<div class="newspack-group-subscription-checkout-prompt <?php echo esc_attr( $box_class ); ?>">
				<p>
					<strong id="<?php echo esc_attr( $name_id ); ?>"><?php echo esc_html( $settings['name'] ); ?></strong><br>
					<?php echo esc_html( $seats_text ); ?>
				</p>
				<a
					class="<?php echo esc_attr( $button_class ); ?>"
					href="<?php echo esc_url( Group_Subscription_MyAccount::get_group_url( $subscription ) ); ?>"
					aria-describedby="<?php echo esc_attr( $name_id ); ?>"
					<?php if ( $is_modal ) : ?>
						target="_top"
						onclick="<?php echo esc_attr( self::MODAL_LINK_ONCLICK ); ?>"
					<?php endif; ?>
				>
					<?php echo esc_html( $button_label ); ?>
				</a>
			</div>
			<?php
		}
		return (string) ob_get_clean();
	}
}
Group_Subscription_Checkout_Prompt::init();
