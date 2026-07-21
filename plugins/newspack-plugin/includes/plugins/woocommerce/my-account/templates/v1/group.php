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

$settings = Group_Subscription_Settings::get_subscription_settings( $subscription );
// The raw custom-name override (empty when the displayed name is an inherited fallback), so the
// rename input shows the editable custom name and uses the resolved fallback as its placeholder.
$custom_name         = (string) $subscription->get_meta( Group_Subscription_Settings::GROUP_SUBSCRIPTION_META_PREFIX . 'name', true );
$group_label_lower   = Group_Subscription::get_label_lower( 'singular' );
$user_id             = get_current_user_id();
$managed             = Group_Subscription::get_managed_subscriptions_for_user( $user_id );
$multi_group         = count( $managed ) > 1;
$subscription_status = $subscription->get_status();
$is_active           = Group_Subscription_MyAccount::is_subscription_active( $subscription );
$current_user_id     = $user_id;
$invite_link         = \Newspack\Group_Subscription_Invite::get_link_invite( $subscription, $current_user_id );
$members             = Group_Subscription::get_members( $subscription );
$all_invites         = \Newspack\Group_Subscription_Invite::get_invites( $subscription );
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
			<h2 class="newspack-ui__font--m" data-group-name><?php echo esc_html( $settings['name'] ); ?></h2>
			<?php
			/* translators: %s: lowercase singular group label (e.g. "group", "team"). */
			$rename_label = sprintf( __( 'Rename %s', 'newspack-plugin' ), $group_label_lower );
			?>
			<button
				type="button"
				class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--icon newspack-ui__button--small newspack-my-account__group--rename"
				title="<?php echo esc_attr( $rename_label ); ?>"
				aria-label="<?php echo esc_attr( $rename_label ); ?>"
			>
				<?php Newspack_UI_Icons::print_svg( 'edit' ); ?>
			</button>
			<span class="<?php echo esc_attr( implode( ' ', $status_badge_classes ) ); ?>">
				<?php echo esc_html( wcs_get_subscription_status_name( $subscription_status ) ); ?>
			</span>
		</div>
		<div class="newspack-my-account__subscription--actions">
			<div class="newspack-my-account__subscription--actions-container">
				<?php if ( $user_id === (int) $subscription->get_user_id() ) : // Billing is the owner's surface; a manager never sees it. ?>
					<a href="<?php echo esc_url( wc_get_endpoint_url( 'view-subscription', $subscription->get_id(), wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="newspack-ui__button newspack-ui__button--secondary">
						<?php esc_html_e( 'View subscription', 'newspack-plugin' ); ?>
					</a>
				<?php endif; ?>
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

	<!-- .newspack-ui__modal: rename group -->
	<div id="newspack-my-account__group_subscription--rename" class="newspack-ui__modal-container">
		<div class="newspack-ui__modal-container__overlay"></div>
		<div class="newspack-ui__modal newspack-ui__modal--small">
			<header class="newspack-ui__modal__header">
				<h2><?php echo esc_html( $rename_label ); ?></h2>
				<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
					<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
					<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
				</button>
			</header>

			<form
				class="newspack-ui__modal__content newspack-my-account__group--rename-form"
				data-subscription-id="<?php echo esc_attr( $subscription->get_id() ); ?>"
				data-error-text="<?php echo esc_attr( __( 'Could not rename. Please try again.', 'newspack-plugin' ) ); ?>"
			>
				<p>
					<label for="newspack-my-account__group--rename-input" class="screen-reader-text">
						<?php
						/* translators: %s: capitalized singular group label (e.g. "Group", "Team"). */
						printf( esc_html__( '%s name', 'newspack-plugin' ), esc_html( Group_Subscription::get_label( 'singular' ) ) );
						?>
					</label>
					<input
						type="text"
						id="newspack-my-account__group--rename-input"
						class="newspack-my-account__group--rename-input"
						name="group_name"
						value="<?php echo esc_attr( $custom_name ); ?>"
						placeholder="<?php echo esc_attr( $settings['name'] ); ?>"
						maxlength="<?php echo esc_attr( Group_Subscription_Settings::GROUP_NAME_MAX_LENGTH ); ?>"
						autocomplete="off"
					/>
				</p>
				<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-my-account__group--rename-save"><span><?php esc_html_e( 'Save', 'newspack-plugin' ); ?></span></button>
				<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close"><?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
			</form>
		</div><!-- .newspack-ui__modal--small -->
	</div> <!-- .newspack-ui__modal-container -->
</div>
