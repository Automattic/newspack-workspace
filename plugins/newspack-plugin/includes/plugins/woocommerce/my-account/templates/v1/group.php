<?php
/**
 * Group page shell. Renders header (name + status badge + actions) and the
 * members panel below it. The Subscription tab has been retired in favour of a
 * direct link to /my-account/view-subscription/{id}/.
 *
 * @var WC_Subscription $subscription The group subscription.
 * @var array           $actions      Subscription actions array (passed through to the members panel).
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_MyAccount;
use Newspack\Group_Subscription_Settings;
use Newspack\Newspack_UI_Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings            = Group_Subscription_Settings::get_subscription_settings( $subscription );
$user_id             = get_current_user_id();
$managed             = Group_Subscription::get_managed_subscriptions_for_user( $user_id );
$multi_group         = count( $managed ) > 1;
$subscription_status = $subscription->get_status();
$is_active           = Group_Subscription_MyAccount::is_subscription_active( $subscription );
$member_limit        = (int) $settings['limit'];
$requested_limit     = Group_Subscription_Settings::get_requested_limit( $subscription );
// A finite limit can be raised; an unlimited group (limit 0) has no ceiling to request beyond.
$can_request_seats   = $is_active && $member_limit > 0;
$has_pending_request = $requested_limit > $member_limit;
$current_user_id     = $user_id;
$invite_link         = \Newspack\Group_Subscription_Invite::get_link_invite( $subscription, $current_user_id );
$members             = Group_Subscription::get_members( $subscription );
$managers            = Group_Subscription::get_managers( $subscription );
$all_invites         = \Newspack\Group_Subscription_Invite::get_invites( $subscription );
// A seat is held by the owner and each accepted member; a pending invite doesn't take a seat until it's accepted.
$seats_used          = count( array_unique( array_map( 'intval', array_merge( (array) $managers, (array) $members ) ) ) );
$is_completely_empty = empty( $members ) && empty( $all_invites );

$status_badge_classes = [ 'newspack-ui__badge' ];
if ( in_array( $subscription_status, [ 'cancelled', 'expired' ], true ) ) {
	$status_badge_classes[] = 'newspack-ui__badge--error';
} elseif ( in_array( $subscription_status, [ 'pending-cancel', 'on-hold', 'pending', 'processing' ], true ) ) {
	$status_badge_classes[] = 'newspack-ui__badge--warning';
} elseif ( 'active' === $subscription_status ) {
	$status_badge_classes[] = 'newspack-ui__badge--success';
} else {
	$status_badge_classes[] = 'newspack-ui__badge--secondary';
}
?>
<div class="newspack-my-account__group">
	<header class="newspack-my-account__subscription--header">
		<div class="newspack-my-account__subscription--title">
			<?php if ( $multi_group ) : ?>
				<?php /* translators: %s: lowercase plural group label (e.g. "groups", "teams"). */ ?>
				<?php $back_label = sprintf( __( 'Back to %s', 'newspack-plugin' ), Group_Subscription::get_label_lower( 'plural' ) ); ?>
				<a href="<?php echo esc_url( wc_get_endpoint_url( Group_Subscription_MyAccount::GROUP_ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="newspack-my-account__subscription--back-link newspack-ui__button newspack-ui__button--ghost newspack-ui__button--icon newspack-ui__button--small" title="<?php echo esc_attr( $back_label ); ?>" aria-label="<?php echo esc_attr( $back_label ); ?>">
					<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
				</a>
			<?php endif; ?>
			<h2 class="newspack-ui__font--m"><?php echo esc_html( $settings['name'] ); ?></h2>
			<span class="<?php echo esc_attr( implode( ' ', $status_badge_classes ) ); ?>">
				<?php echo esc_html( wcs_get_subscription_status_name( $subscription_status ) ); ?>
			</span>
		</div>
		<div class="newspack-my-account__subscription--actions">
			<div class="newspack-my-account__subscription--actions-container">
				<a href="<?php echo esc_url( wc_get_endpoint_url( 'view-subscription', $subscription->get_id(), wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="newspack-ui__button newspack-ui__button--secondary">
					<?php esc_html_e( 'View subscription', 'newspack-plugin' ); ?>
				</a>
				<?php if ( $is_active && ! $is_completely_empty ) : ?>
					<div class="newspack-ui__dropdown newspack-my-account__subscription--actions-dropdown">
						<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__dropdown__toggle">
							<?php esc_html_e( 'Invite members', 'newspack-plugin' ); ?>
							<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
						</button>
						<div class="newspack-ui__dropdown__content">
							<ul>
								<li>
									<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__subscription--invite-member"><?php esc_html_e( 'Invite by email', 'newspack-plugin' ); ?></button>
								</li>
								<li>
									<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__copy" data-error-text="<?php echo esc_attr( __( 'Could not copy. Please try again.', 'newspack-plugin' ) ); ?>"><span><?php esc_html_e( 'Copy invite link', 'newspack-plugin' ); ?></span></button>
								</li>
								<li class="<?php echo esc_attr( ! $invite_link ? 'hidden' : '' ); ?>">
									<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__confirm-regenerate"><?php esc_html_e( 'Regenerate invite link', 'newspack-plugin' ); ?></button>
								</li>
								<li class="<?php echo esc_attr( ! $invite_link ? 'hidden' : '' ); ?>">
									<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__button--destructive newspack-my-account__group_subscription__invite-link__confirm-disable"><?php esc_html_e( 'Disable invite link', 'newspack-plugin' ); ?></button>
								</li>
							</ul>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</header>
	<div class="newspack-my-account__subscription--meta">
		<span class="newspack-my-account__subscription--seat-count">
			<?php
			if ( $member_limit > 0 ) {
				printf(
					/* translators: 1: seats used, 2: total seat limit. */
					esc_html__( '%1$d of %2$d seats', 'newspack-plugin' ),
					(int) $seats_used,
					(int) $member_limit
				);
			} else {
				printf(
					/* translators: %d: number of seats used. */
					esc_html( _n( '%d seat', '%d seats', (int) $seats_used, 'newspack-plugin' ) ),
					(int) $seats_used
				);
			}
			?>
		</span>
		<?php if ( $can_request_seats ) : ?>
			<span class="newspack-my-account__subscription--meta-sep" aria-hidden="true">&middot;</span>
			<?php if ( $has_pending_request ) : ?>
				<span class="newspack-my-account__group_subscription__seat-request-pending">
					<?php
					printf(
						/* translators: %d: the requested member limit. */
						esc_html__( 'Increase to %d requested', 'newspack-plugin' ),
						(int) $requested_limit
					);
					?>
				</span>
			<?php else : ?>
				<button type="button" class="newspack-my-account__subscription--meta-link newspack-my-account__group_subscription__request-seats">
					<?php esc_html_e( 'Request more seats', 'newspack-plugin' ); ?>
				</button>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="newspack-my-account__group__content">
		<?php
		wc_get_template(
			'myaccount/group-subscription-members.php',
			[
				'subscription' => $subscription,
				'actions'      => $actions,
				'view'         => 'manage-members',
			]
		);
		?>
	</div>
</div>
