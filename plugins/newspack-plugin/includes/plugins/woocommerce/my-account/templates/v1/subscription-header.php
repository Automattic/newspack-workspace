<?php
/**
 * Custom header component for My Account single subscription pages.
 * Note that this template is not a standard WooCommerce Subscriptions template.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

use Newspack\WooCommerce_Subscriptions;
use Newspack\Newspack_UI_Icons;

defined( 'ABSPATH' ) || exit;

$actions      = $args['actions'];
$subscription = $args['subscription'];

$is_group_subscription        = Group_Subscription::is_group_subscription( $subscription );
$is_group_member_subscription = $is_group_subscription
	&& Group_Subscription::user_is_member( get_current_user_id(), $subscription );
$is_group_owner_subscription  = $is_group_subscription
	&& Group_Subscription::user_is_manager( get_current_user_id(), $subscription );

// Ensure the cancel action is shown last.
if ( ! empty( $actions['cancel'] ) ) {
	$cancel_action         = $actions['cancel'];
	$cancel_action['name'] = __( 'Cancel subscription', 'newspack-plugin' );
	unset( $actions['cancel'] );
	$actions['cancel'] = $cancel_action;
}

// Rename the 'Change payment' action, keeping the distinction WCS makes: a
// subscription with no gateway yet needs a card added, not an existing one
// updated. Telling a reader with nothing on file to "update" their payment
// method is what sent them looking for a card they never had (NPPD-2170).
if ( ! empty( $actions['change_payment_method']['name'] ) ) {
	$actions['change_payment_method']['name'] = $subscription->has_payment_gateway()
		? __( 'Update payment method', 'newspack-plugin' )
		: __( 'Add payment method', 'newspack-plugin' );
}

\do_action( 'newspack_woocommerce_before_subscription_header', $subscription, $actions );
?>
<header class="newspack-my-account__subscription--header newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--vertical-below-mobile newspack-ui__stack--wrap newspack-ui__stack--justify-between newspack-ui__stack--align-start newspack-ui__stack--gap-4">
	<?php
	$product_id = WooCommerce_Subscriptions::get_subscription_product_id( $subscription );
	if ( $product_id ) {
		$product   = \wc_get_product( $product_id );
		$is_single = 1 === count( \wcs_get_users_subscriptions() ) && \apply_filters( 'wcs_my_account_redirect_to_single_subscription', true );
		if ( $product ) :
			?>
		<div class="newspack-my-account__subscription--title newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--wrap newspack-ui__stack--align-center newspack-ui__stack--gap-2">
			<?php if ( ! $is_single ) : ?>
				<a href="<?php echo esc_url( \wc_get_account_endpoint_url( 'subscriptions' ) ); ?>" class="newspack-my-account__subscription--back-link newspack-ui__button newspack-ui__button--ghost newspack-ui__button--icon newspack-ui__button--small newspack-ui__spacing-bottom--0" title="<?php esc_attr_e( 'Back to all subscriptions', 'newspack-plugin' ); ?>" aria-label="<?php esc_attr_e( 'Back to all subscriptions', 'newspack-plugin' ); ?>">
					<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
				</a>
			<?php endif; ?>
			<h1 class="newspack-my-account__page-title newspack-ui__font--l">
				<?php echo \esc_html( $product->get_name() ); ?>
			</h1>
			<?php
			if ( $is_group_subscription ) :
				?>
					<span class="newspack-ui__badge newspack-ui__badge--secondary">
					<?php echo esc_html( Group_Subscription::get_label( 'singular' ) ); ?>
					</span>
				<?php
				endif;
			$status_modifier = My_Account_UI_V1::get_subscription_status_modifier( $subscription );
			$classes         = [ 'newspack-ui__badge', 'newspack-ui__badge--' . ( $status_modifier ? $status_modifier : 'secondary' ) ];
			?>
			<span class="<?php echo \esc_attr( implode( ' ', $classes ) ); ?>">
				<?php echo esc_html( My_Account_UI_V1::get_subscription_status_label( $subscription ) ); ?>
			</span>
		</div>
			<?php
			endif;
	}
	?>
	<div class="newspack-my-account__subscription--actions newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--vertical-below-mobile newspack-ui__stack--align-center newspack-ui__stack--gap-1">
		<div class="newspack-my-account__subscription--actions-container newspack-ui__stack newspack-ui__stack--horizontal newspack-ui__stack--vertical-below-mobile newspack-ui__stack--wrap newspack-ui__stack--gap-1">
		<?php
		// Members get a view-only experience: no owner-only management controls.
		$items = $is_group_member_subscription ? [] : $subscription->get_items();
		if ( 1 < count( $items ) ) {
			\add_filter(
				'woocommerce_subscriptions_switch_link_classes',
				function() {
					return [
						'wcs-switch-link',
						'newspack-ui__button',
						'newspack-ui__button--ghost',
					];
				}
			);
			\add_filter(
				'woocommerce_subscriptions_switch_link_text',
				function( $text, $item_id, $item ) {
					return sprintf(
						// translators: %1$s is the action (Edit for donations, Change for non-donation subscriptions), %2$s is the name of the item.
						'%1$s: %2$s',
						Donations::is_donation_product( $item->get_product_id() ) ? __( 'Edit', 'newspack-plugin' ) : __( 'Change', 'newspack-plugin' ),
						$item->get_name()
					);
				},
				13,
				3
			);
			?>
			<div class="newspack-ui__dropdown newspack-my-account__subscription--change-subscription-dropdown">
				<button class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small newspack-ui__button--medium-on-mobile newspack-ui__dropdown__toggle" aria-expanded="false">
					<span><?php esc_html_e( 'Change subscription', 'newspack-plugin' ); ?></span>
					<?php Newspack_UI_Icons::print_svg( 'chevronDownSmall' ); ?>
				</button>
				<div class="newspack-ui__dropdown__content">
					<ul>
						<?php foreach ( $items as $item ) : ?>
							<li class="newspack-my-account__subscription--change-subscription-item"><?php \WC_Subscriptions_Switcher::print_switch_link( $item->get_id(), $item, $subscription ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
			<?php
		} elseif ( 1 === count( $items ) ) {
			$item = reset( $items );
			\add_filter(
				'woocommerce_subscriptions_switch_link_classes',
				function( $classes ) {
					return [
						'wcs-switch-link',
						'newspack-ui__button',
						'newspack-ui__button--outline',
						'newspack-ui__button--small',
						'newspack-ui__button--medium-on-mobile',
						'newspack-my-account__subscription--change-subscription-item',
					];
				}
			);
			?>
			<?php \WC_Subscriptions_Switcher::print_switch_link( $item->get_id(), $item, $subscription ); ?>
			<?php
		}
		$parent_order = array_values( $subscription->get_related_orders( 'all', 'parent' ) );
		if ( ! $is_group_member_subscription && $subscription->has_status( [ 'expired', 'cancelled' ] ) && ! empty( $parent_order ) && empty( $actions['resubscribe'] ) ) {
			\woocommerce_order_again_button( $parent_order[0] );
		}
		?>
		<?php
		if ( $is_group_member_subscription ) :
			$group_label_lower  = Group_Subscription::get_label_lower( 'singular' );
			$leave_button_label = sprintf(
				/* translators: %s: lowercase singular group label. */
				__( 'Leave %s', 'newspack-plugin' ),
				$group_label_lower
			);
			?>
			<button type="button" class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--destructive newspack-my-account__subscription--leave-group">
				<?php echo esc_html( $leave_button_label ); ?>
			</button>
			<?php
		endif;
		?>
		</div>
		<?php // Group owners always get "View group" here, even when WooCommerce has no actions of its own. ?>
		<?php if ( ! empty( $actions ) || $is_group_owner_subscription ) : ?>
		<div class="newspack-ui__dropdown newspack-my-account__subscription--actions-dropdown">
			<button class="newspack-ui__button newspack-ui__button--outline newspack-ui__button--small newspack-ui__button--medium-on-mobile newspack-ui__dropdown__toggle" aria-expanded="false">
				<?php echo esc_html( WooCommerce_Update_Payment_Notice::is_donation_subscription( $subscription ) ? __( 'Manage donation', 'newspack-plugin' ) : __( 'Manage subscription', 'newspack-plugin' ) ); ?>
				<?php Newspack_UI_Icons::print_svg( 'chevronDownSmall' ); ?>
			</button>
			<div class="newspack-ui__dropdown__content">
				<ul>
					<?php if ( $is_group_owner_subscription ) : ?>
						<li>
							<a href="<?php echo esc_url( Group_Subscription_MyAccount::get_group_url( $subscription ) ); ?>" class="newspack-ui__button newspack-ui__button--ghost">
								<?php
								printf(
									/* translators: %s is the lowercase singular group label (e.g. "group", "team", or a publisher override). */
									esc_html__( 'View %s', 'newspack-plugin' ),
									esc_html( Group_Subscription::get_label_lower( 'singular' ) )
								);
								?>
							</a>
						</li>
					<?php endif; ?>
					<?php $dropdown_actions = array_diff_key( $actions, [ 'cancel' => true ] ) + array_intersect_key( $actions, [ 'cancel' => true ] ); ?>
					<?php foreach ( $dropdown_actions as $key => $action_link ) : ?>
						<?php
						$classes = [
							'newspack-ui__button',
							'newspack-ui__button--ghost',
							\sanitize_html_class( $key ),
						];
						if ( 'cancel' === $key ) {
							$classes[] = 'newspack-ui__button--destructive';
						}
						?>
						<li><a class="<?php echo \esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo \esc_url( $action_link['url'] ); ?>"><?php echo \esc_html( $action_link['name'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php endif; ?>
		<?php \do_action( 'newspack_woocommerce_after_subscription_actions', $subscription, $actions ); ?>
	</div>
</header>
<?php
if ( $is_group_member_subscription ) :
	ob_start();
	?>
	<p>
		<?php esc_html_e( "You'll lose access right away. To get back in, you'll need to be invited again — or start your own subscription.", 'newspack-plugin' ); ?>
	</p>
	<input type="hidden" name="action" value="<?php echo esc_attr( Group_Subscription_MyAccount::LEAVE_GROUP_NONCE_ACTION ); ?>">
	<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
	<?php wp_nonce_field( Group_Subscription_MyAccount::LEAVE_GROUP_NONCE_ACTION ); ?>
	<?php
	Newspack_UI::generate_modal(
		[
			'id'          => 'confirm-leave-group',
			'title'       => $leave_button_label,
			'content'     => ob_get_clean(),
			'form'        => 'post',
			'form_action' => admin_url( 'admin-post.php' ),
			'actions'     => [
				'confirm' => [
					'label' => $leave_button_label,
					'type'  => 'destructive',
				],
				'cancel'  => [
					'label'  => __( 'Cancel', 'newspack-plugin' ),
					'type'   => 'outline',
					'action' => 'close',
					'url'    => '#',
				],
			],
		]
	);
endif;

\do_action( 'newspack_woocommerce_after_subscription_header', $subscription, $actions );
