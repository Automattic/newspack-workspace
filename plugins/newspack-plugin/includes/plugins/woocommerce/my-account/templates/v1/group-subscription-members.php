<?php
/**
 * Custom template to manage group subscription members.
 * IMPORTANT: This template is supported only in My Account UI v1 and is not a standard WooCommerce Subscriptions template.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

namespace Newspack;

use Newspack\WooCommerce_Subscriptions;
use Newspack\Newspack_UI_Icons;

defined( 'ABSPATH' ) || exit;

\do_action( 'newspack_woocommerce_before_subscription_header', $subscription, $actions );
$members              = Group_Subscription::get_members( $subscription );
$managers_and_members = array_merge( Group_Subscription::get_managers( $subscription ), $members );
$member_limit         = Group_Subscription_Settings::get_subscription_settings( $subscription )['limit'];
$all_invites          = Group_Subscription_Invite::get_invites( $subscription );
$pending_invites      = Group_Subscription_Invite::get_invites( $subscription, false );
$current_user_id      = get_current_user_id();
$invite_link          = Group_Subscription_Invite::get_link_invite( $subscription, $current_user_id );
$invite_link_url      = $invite_link ? Group_Subscription_Invite::get_link_invite_url( $subscription->get_id(), $current_user_id, $invite_link['key'] ) : '';
$is_at_limit = $member_limit > 0 && ( count( $members ) + count( $pending_invites ) ) >= $member_limit;
$active_tab  = ( isset( $_GET['activeTab'] ) && 'invites' === sanitize_key( wp_unslash( $_GET['activeTab'] ) ) ) ? 'invites' : 'members'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<header class="newspack-my-account__subscription--header">
	<?php
	$product_id = WooCommerce_Subscriptions::get_subscription_product_id( $subscription );
	if ( $product_id ) {
		$product = \wc_get_product( $product_id );
		if ( $product ) :
			$status = $subscription->get_status(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			?>
		<div class="newspack-my-account__subscription--title">
			<a href="<?php echo esc_url( \wc_get_endpoint_url( 'view-subscription', $subscription->get_id(), \wc_get_page_permalink( 'myaccount' ) ) ); ?>" class="newspack-my-account__subscription--back-link newspack-ui__button newspack-ui__button--ghost newspack-ui__button--icon newspack-ui__button--small" title="<?php esc_attr_e( 'Back to subscription', 'newspack-plugin' ); ?>">
				<?php Newspack_UI_Icons::print_svg( 'chevronLeft' ); ?>
			</a>
			<h2 class="newspack-ui__font--m">
				<?php
				echo \esc_html(
					sprintf(
						// translators: %s: The product name.
						__( '%s / Manage members', 'newspack-plugin' ),
						$product->get_name()
					)
				);
				?>
			</h2>
			<?php
			if ( ! $subscription->has_status( 'active' ) ) :
				$classes = [ 'newspack-ui__badge' ];
				if ( $subscription->has_status( [ 'cancelled', 'expired' ] ) ) {
					$classes[] = 'newspack-ui__badge--error';
				} elseif ( $subscription->has_status( [ 'on-hold', 'pending', 'processing' ] ) ) {
					$classes[] = 'newspack-ui__badge--warning';
				} else {
					$classes[] = 'newspack-ui__badge--secondary';
				}
				?>
				<span class="<?php echo \esc_attr( implode( ' ', $classes ) ); ?>">
					<?php echo esc_html( \wcs_get_subscription_status_name( $status ) ); ?>
				</span>
			<?php endif; ?>
		</div>
			<?php
		endif;
	}
	?>
	<div class="newspack-my-account__subscription--actions">
		<div class="newspack-my-account__subscription--actions-container">
			<button type="button" class="newspack-my-account__subscription--action-link newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide newspack-my-account__subscription--invite-member"><?php \esc_html_e( 'Invite by email', 'newspack-plugin' ); ?></button>
			<button type="button" class="newspack-my-account__subscription--action-link newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__copy" data-error-text="<?php echo esc_attr( __( 'Could not copy. Please try again.', 'newspack-plugin' ) ); ?>"><span><?php \esc_html_e( 'Copy invite link', 'newspack-plugin' ); ?></span></button>
			<button type="button" class="newspack-my-account__subscription--action-link newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__confirm-regenerate
			<?php
			if ( ! $invite_link ) :
				?>
				hidden
			<?php endif; ?>
			"><?php \esc_html_e( 'Regenerate invite link', 'newspack-plugin' ); ?></button>
			<button type="button" class="newspack-my-account__subscription--action-link newspack-ui__button newspack-ui__button--outline newspack-ui__button--wide newspack-ui__button--destructive newspack-my-account__group_subscription__invite-link__confirm-disable
			<?php
			if ( ! $invite_link ) :
				?>
				hidden
			<?php endif; ?>
			"><?php \esc_html_e( 'Disable invite link', 'newspack-plugin' ); ?></button>
			<div class="newspack-ui__dropdown newspack-my-account__subscription--actions-dropdown">
				<button class="newspack-ui__button newspack-ui__button--secondary newspack-ui__dropdown__toggle">
					<?php esc_html_e( 'Invite members', 'newspack-plugin' ); ?>
					<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
				</button>
				<div class="newspack-ui__dropdown__content">
					<ul>
						<li>
							<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__subscription--invite-member"><?php \esc_html_e( 'Invite by email', 'newspack-plugin' ); ?></button>
						</li>
						<li>
							<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__copy" data-error-text="<?php echo esc_attr( __( 'Could not copy. Please try again.', 'newspack-plugin' ) ); ?>"><span><?php \esc_html_e( 'Copy invite link', 'newspack-plugin' ); ?></span></button>
						</li>
						<li class="
						<?php
						if ( ! $invite_link ) :
							?>
							hidden<?php endif; ?>">
							<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__confirm-regenerate"><?php \esc_html_e( 'Regenerate invite link', 'newspack-plugin' ); ?></button>
						</li>
						<li class="
						<?php
						if ( ! $invite_link ) :
							?>
							hidden<?php endif; ?>">
							<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__button--destructive newspack-my-account__group_subscription__invite-link__confirm-disable"><?php \esc_html_e( 'Disable invite link', 'newspack-plugin' ); ?></button>
						</li>
					</ul>
				</div>
			</div>
		</div>
		<?php \do_action( 'newspack_woocommerce_after_subscription_actions', $subscription, $actions ); ?>
	</div>
</header>

<div class="newspack-my-account__group_subscription__content" data-subscription-id="<?php echo esc_attr( $subscription->get_id() ); ?>" data-invite-link="<?php echo esc_attr( $invite_link_url ); ?>">
	<div class="newspack-ui__segmented-control newspack-my-account__group_subscription__segmented-control">
		<div class="newspack-ui__segmented-control__tabs" role="tablist">
			<button
				type="button"
				role="tab"
				id="newspack-my-account__group_subscription__tab-members"
				aria-controls="newspack-my-account__group_subscription__panel-members"
				aria-selected="<?php echo 'members' === $active_tab ? 'true' : 'false'; ?>"
				tabindex="<?php echo 'members' === $active_tab ? '0' : '-1'; ?>"
				class="newspack-ui__button newspack-ui__button--small<?php echo 'members' === $active_tab ? ' selected' : ''; ?>"
			>
				<?php esc_html_e( 'Members', 'newspack-plugin' ); ?>
				<span class="newspack-ui__badge newspack-ui__badge--outline newspack-group-subscription--members-count"><?php echo esc_html( count( $managers_and_members ) ); ?></span>
			</button>
			<button
				type="button"
				role="tab"
				id="newspack-my-account__group_subscription__tab-invites"
				aria-controls="newspack-my-account__group_subscription__panel-invites"
				aria-selected="<?php echo 'invites' === $active_tab ? 'true' : 'false'; ?>"
				tabindex="<?php echo 'invites' === $active_tab ? '0' : '-1'; ?>"
				class="newspack-ui__button newspack-ui__button--small<?php echo 'invites' === $active_tab ? ' selected' : ''; ?>"
			>
				<?php esc_html_e( 'Invitations', 'newspack-plugin' ); ?>
				<span class="newspack-ui__badge newspack-ui__badge--outline newspack-group-subscription--invitations-count"><?php echo esc_html( count( $all_invites ) ); ?></span>
			</button>
		</div>
		<div class="newspack-ui__segmented-control__content">
			<div
				id="newspack-my-account__group_subscription__panel-members"
				role="tabpanel"
				aria-labelledby="newspack-my-account__group_subscription__tab-members"
				class="newspack-ui__segmented-control__panel<?php echo 'members' === $active_tab ? ' selected' : ''; ?>"
			>
	<table class="shop_table shop_table_responsive newspack-my-account__group_subscription__members">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'newspack-plugin' ); ?></th>
				<th><?php esc_html_e( 'Email', 'newspack-plugin' ); ?></th>
				<th><?php esc_html_e( 'Role', 'newspack-plugin' ); ?></th>
				<th class="newspack-my-account__group_subscription__members--actions">&nbsp;</th>
			</tr>
		</thead>
		<tbody>
		<?php
		if ( empty( $managers_and_members ) ) :
			?>
			<tr>
				<td colspan="4"><?php esc_html_e( 'No members found.', 'newspack-plugin' ); ?></td>
			</tr>
			<?php
		endif;
		foreach ( $managers_and_members as $user_id ) :
			$is_manager  = Group_Subscription::user_is_manager( $user_id, $subscription );
			$user        = get_user_by( 'id', $user_id );
			$is_owner    = $user_id === $subscription->get_user_id();
			$member_role = $is_manager ? __( 'Manager', 'newspack-plugin' ) : __( 'Member', 'newspack-plugin' );
			if ( $is_owner ) {
				$member_role = __( 'Owner', 'newspack-plugin' );
			}
			?>
			<tr>
				<td data-title="<?php esc_attr_e( 'Name', 'newspack-plugin' ); ?>">
					<?php echo esc_html( $user->display_name ); ?>
					<?php if ( $is_owner ) : ?>
						<?php esc_html_e( ' (you)', 'newspack-plugin' ); ?>
					<?php endif; ?>
				</td>
				<td data-title="<?php esc_attr_e( 'Email', 'newspack-plugin' ); ?>"><a href="mailto:<?php echo esc_attr( sanitize_email( $user->user_email ) ); ?>"><?php echo esc_html( sanitize_email( $user->user_email ) ); ?></a></td>
				<td data-title="<?php esc_attr_e( 'Role', 'newspack-plugin' ); ?>"><?php echo esc_html( $member_role ); ?></td>
				<td class="newspack-my-account__group_subscription__members--actions order-actions <?php echo esc_attr( $is_manager ? 'newspack-my-account__group_subscription__members--actions--manager' : '' ); ?>">
					<?php if ( ! $is_manager ) : ?>
					<div class="newspack-ui__dropdown">
						<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small newspack-ui__dropdown__toggle newspack-ui__button--icon">
							<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
							<span class="screen-reader-text"><?php \esc_html_e( 'More', 'newspack-plugin' ); ?></span>
						</button>
							<div class="newspack-ui__dropdown__content">
								<ul>
									<li>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="newspack_group_subscription_remove_member">
											<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
											<input type="hidden" name="member_id" value="<?php echo esc_attr( $user->ID ); ?>">
											<?php wp_nonce_field( Group_Subscription_MyAccount::REMOVE_MEMBER_NONCE_ACTION ); ?>
											<button type="submit" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive"><?php \esc_html_e( 'Remove member', 'newspack-plugin' ); ?></button>
										</form>
									</li>
								</ul>
							</div>
					</div>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
			</div><!-- .newspack-ui__segmented-control__panel (members) -->
			<div class="newspack-ui__segmented-control__panel<?php echo 'invites' === $active_tab ? ' selected' : ''; ?>">
	<table class="shop_table shop_table_responsive newspack-my-account__group_subscription__invites">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Sent to', 'newspack-plugin' ); ?></th>
				<th><?php esc_html_e( 'Status', 'newspack-plugin' ); ?></th>
				<th>&nbsp;</th>
			</tr>
		</thead>
		<tbody>
		<?php
		if ( empty( $all_invites ) ) :
			?>
			<tr>
				<td colspan="3"><?php esc_html_e( 'No invitations found.', 'newspack-plugin' ); ?></td>
			</tr>
			<?php
		endif;
		foreach ( $all_invites as $key => $invite ) :
			?>
			<tr>
				<td data-title="<?php esc_attr_e( 'Sent to', 'newspack-plugin' ); ?>"><a href="mailto:<?php echo esc_attr( sanitize_email( $invite['email'] ) ); ?>"><?php echo esc_html( sanitize_email( $invite['email'] ) ); ?></a></td>
				<td data-title="<?php esc_attr_e( 'Status', 'newspack-plugin' ); ?>"><?php echo esc_html( Group_Subscription_Invite::is_invite_expired( $invite ) ? __( 'Expired', 'newspack-plugin' ) : __( 'Pending', 'newspack-plugin' ) ); ?></td>
				<td class="newspack-my-account__group_subscription__invites--actions order-actions">
					<div class="newspack-ui__dropdown">
						<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small newspack-ui__dropdown__toggle newspack-ui__button--icon">
							<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
							<span class="screen-reader-text"><?php \esc_html_e( 'More', 'newspack-plugin' ); ?></span>
						</button>
						<div class="newspack-ui__dropdown__content">
							<ul>
								<li>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="newspack_group_subscription_invite">
										<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
										<input type="hidden" name="newspack-group-subscription-invite-email" value="<?php echo esc_attr( sanitize_email( $invite['email'] ) ); ?>">
										<?php wp_nonce_field( Group_Subscription_MyAccount::INVITE_NONCE_ACTION ); ?>
										<button type="submit" class="newspack-ui__button newspack-ui__button--ghost"><?php \esc_html_e( 'Resend', 'newspack-plugin' ); ?></button>
									</form>
								</li>
								<li>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="newspack_group_subscription_cancel_invite">
										<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
										<input type="hidden" name="email" value="<?php echo esc_attr( sanitize_email( $invite['email'] ) ); ?>">
										<?php wp_nonce_field( Group_Subscription_MyAccount::CANCEL_INVITE_NONCE_ACTION ); ?>
										<button type="submit" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive"><?php \esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
									</form>
								</li>
							</ul>
						</div>
					</div>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
			</div><!-- .newspack-ui__segmented-control__panel (invites) -->
		</div><!-- .newspack-ui__segmented-control__content -->
	</div><!-- .newspack-ui__segmented-control -->

	<!-- .newspack-ui__modal: invite by email -->
	<div id="newspack-my-account__group_subscription--invite-member" class="newspack-ui__modal-container">
		<div class="newspack-ui__modal-container__overlay"></div>
		<div class="newspack-ui__modal newspack-ui__modal--small">
				<header class="newspack-ui__modal__header">
					<h2><?php esc_html_e( 'Invite a group member', 'newspack-plugin' ); ?></h2>

					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
					</button>
				</header>

				<section class="newspack-ui__modal__content">
					<?php if ( $is_at_limit ) : ?>
						<p>
							<?php esc_html_e( 'You have reached the member limit for this group subscription. Please remove some members or cancel pending invitations before inviting more group members.', 'newspack-plugin' ); ?>
						</p>
					<?php else : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									// translators: %s is a duration label like "30 days" or "1 hour".
									__( 'They\'ll get an email with a link to join the group. The link expires in %s.', 'newspack-plugin' ),
									Group_Subscription_Invite::get_expiration_label()
								)
							);
							?>
						</p>
						<form name="newspack-group-subscription-invite-member" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="newspack_group_subscription_invite">
							<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
							<?php wp_nonce_field( Group_Subscription_MyAccount::INVITE_NONCE_ACTION ); ?>
							<p>
								<input type="email" placeholder="<?php esc_attr_e( 'Recipient’s email address', 'newspack-plugin' ); ?>" name="newspack-group-subscription-invite-email" id="newspack-group-subscription-invite-email" required>
							</p>

							<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide"><?php esc_html_e( 'Invite', 'newspack-plugin' ); ?></button>
							<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close"><?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
						</form>
					<?php endif; ?>
				</section>
			</div><!-- .newspack-ui__modal__small -->
	</div> <!-- .newspack-ui__modal-container -->

	<!-- .newspack-ui__modal: regenerate invite link -->
	<div id="newspack-my-account__group_subscription--confirm-regenerate-link" class="newspack-ui__modal-container">
		<div class="newspack-ui__modal-container__overlay"></div>
		<div class="newspack-ui__modal newspack-ui__modal--small">
				<header class="newspack-ui__modal__header">
					<h2><?php esc_html_e( 'Regenerate invite link', 'newspack-plugin' ); ?></h2>

					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
					</button>
				</header>

				<section class="newspack-ui__modal__content">
						<p>
							<?php esc_html_e( 'The current link will stop working. You\'ll get a new link to share, and anyone who hasn\'t joined yet will need the new link.', 'newspack-plugin' ); ?>
						</p>

						<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__regenerate" data-error-text="<?php echo esc_attr( __( 'Could not regenerate. Please try again.', 'newspack-plugin' ) ); ?>"><span><?php esc_html_e( 'Regenerate link', 'newspack-plugin' ); ?></span></button>
						<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close"><?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
				</section>
			</div><!-- .newspack-ui__modal__small -->
	</div> <!-- .newspack-ui__modal-container -->

	<!-- .newspack-ui__modal: disable invite link -->
	<div id="newspack-my-account__group_subscription--confirm-disable-link" class="newspack-ui__modal-container">
		<div class="newspack-ui__modal-container__overlay"></div>
		<div class="newspack-ui__modal newspack-ui__modal--small">
				<header class="newspack-ui__modal__header">
					<h2><?php esc_html_e( 'Disable invite link', 'newspack-plugin' ); ?></h2>

					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
					</button>
				</header>

				<section class="newspack-ui__modal__content">
						<p>
							<?php esc_html_e( 'The current link will stop working. Anyone who hasn\'t joined yet will no longer be able to. You can create a new link at any time.', 'newspack-plugin' ); ?>
						</p>

						<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-ui__button--destructive newspack-my-account__group_subscription__invite-link__disable" data-error-text="<?php echo esc_attr( __( 'Could not disable. Please try again.', 'newspack-plugin' ) ); ?>"><span><?php esc_html_e( 'Disable link', 'newspack-plugin' ); ?></span></button>
						<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close"><?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
				</section>
			</div><!-- .newspack-ui__modal__small -->
	</div> <!-- .newspack-ui__modal-container -->
</div>
