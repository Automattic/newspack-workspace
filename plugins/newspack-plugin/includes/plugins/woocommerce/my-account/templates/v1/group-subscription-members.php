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

use Newspack\Newspack_UI_Icons;

defined( 'ABSPATH' ) || exit;

\do_action( 'newspack_woocommerce_before_subscription_header', $subscription, $actions );
// Both lists pass through public filters, so normalize to ints: the strict in_array()
// and integer-keyed lookup below shouldn't depend on what a filter returns.
$members              = array_map( 'intval', Group_Subscription::get_members( $subscription ) );
$managers             = array_map( 'intval', Group_Subscription::get_managers( $subscription ) );
// array_unique() guards against a user appearing in both lists (e.g. the owner also carrying
// member meta), which would otherwise render two rows for the same person.
$managers_and_members = array_values( array_unique( array_merge( $managers, $members ) ) );
// Batch-load every row's user once, instead of one get_user_by() per row. The non-empty
// guard matters: get_users() with an empty 'include' would return every site user.
$members_by_id = [];
if ( ! empty( $managers_and_members ) ) {
	foreach ( get_users( [ 'include' => $managers_and_members ] ) as $member_user ) {
		$members_by_id[ $member_user->ID ] = $member_user;
	}
}
// The seat limit, not the owner-inclusive configured limit: $members excludes the owner,
// so this is the threshold the server actually gates additions and invites on.
$member_limit         = Group_Subscription::get_member_seat_limit( $subscription );
$all_invites          = Group_Subscription_Invite::get_invites( $subscription );
$pending_invites      = Group_Subscription_Invite::get_invites( $subscription, false );
$current_user_id      = get_current_user_id();
$invite_link          = Group_Subscription_Invite::get_link_invite( $subscription );
$invite_link_url      = $invite_link ? Group_Subscription_Invite::get_link_invite_url( $subscription->get_id(), $invite_link['key'] ) : '';
$is_at_limit         = $member_limit > 0 && ( count( $members ) + count( $pending_invites ) ) >= $member_limit;
$is_manageable       = Group_Subscription_MyAccount::is_subscription_manageable( $subscription );
// Role changes (and touching peer managers) are the owner's call; a manager only manages plain members.
$viewer_is_owner     = $current_user_id === (int) $subscription->get_user_id();
$is_active           = Group_Subscription_MyAccount::is_subscription_active( $subscription );
$active_tab          = ( isset( $_GET['activeTab'] ) && 'invites' === sanitize_key( wp_unslash( $_GET['activeTab'] ) ) ) ? 'invites' : 'members'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$group_label_lower   = Group_Subscription::get_label_lower( 'singular' );
$is_completely_empty = empty( $members ) && empty( $all_invites );

/**
 * Render a search field that narrows a table's rows client-side (see
 * src/my-account/v1/group-member-search.js). The page renders every row, so a group
 * of a few hundred filters without a request.
 *
 * @param string $list            'members' or 'invites'; keys the element IDs.
 * @param string $label           Accessible label; says what the field matches on.
 * @param string $status_template Result count announced to screen readers, with %1$d
 *                                (matching rows) and %2$d (all rows) placeholders.
 */
$render_search_field = static function ( string $list, string $label, string $status_template ): void {
	$input_id = 'newspack-my-account__group_subscription__search-' . $list;
	?>
	<div class="newspack-my-account__group_subscription__search" data-status-template="<?php echo esc_attr( $status_template ); ?>">
		<label for="<?php echo esc_attr( $input_id ); ?>" class="screen-reader-text"><?php echo esc_html( $label ); ?></label>
		<input
			type="search"
			id="<?php echo esc_attr( $input_id ); ?>"
			aria-controls="newspack-my-account__group_subscription__table-<?php echo esc_attr( $list ); ?>"
			placeholder="<?php echo esc_attr( $label ); ?>"
			autocomplete="off"
		>
		<p class="newspack-ui__helper-text newspack-my-account__group_subscription__search-status" data-search-status aria-live="polite"></p>
	</div>
	<?php
};
?>
<?php if ( ! $is_manageable ) : ?>
	<div class="newspack-ui__notice newspack-ui__notice--info newspack-my-account__group_subscription__inactive-notice" role="status">
		<p class="newspack-ui__spacing-top--0 newspack-ui__spacing-bottom--0">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: lowercase singular group label. */
					__( 'This %s is no longer active, so members can\'t be invited, removed, or updated.', 'newspack-plugin' ),
					$group_label_lower
				)
			);
			?>
		</p>
	</div>
<?php endif; ?>

<div class="newspack-my-account__group_subscription__content" data-subscription-id="<?php echo esc_attr( $subscription->get_id() ); ?>" data-invite-link="<?php echo esc_attr( $invite_link_url ); ?>">
<?php if ( $is_completely_empty ) : ?>
	<div class="newspack-ui__box newspack-ui__box--border newspack-ui__box--text-center newspack-ui__box--2x-large">
		<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--align-center newspack-ui__stack--gap-1">
			<h3 class="newspack-ui__font--m"><?php esc_html_e( "You haven't added anyone yet", 'newspack-plugin' ); ?></h3>
			<p class="newspack-ui__spacing-top--0 newspack-ui__spacing-bottom--3">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: lowercase singular group label. */
						__( 'Invite friends, family, or colleagues to share your %s. They get full access — at no extra cost to them.', 'newspack-plugin' ),
						$group_label_lower
					)
				);
				?>
			</p>
			<?php if ( $is_active ) : ?>
				<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-2 newspack-my-account__group_subscription__empty-actions">
					<button type="button" class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--wide newspack-my-account__subscription--invite-member"><?php esc_html_e( 'Invite by email', 'newspack-plugin' ); ?></button>
					<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__subscription--invite-bulk"><?php esc_html_e( 'Invite in bulk', 'newspack-plugin' ); ?></button>
					<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__copy" data-error-text="<?php echo esc_attr( __( 'Could not copy. Please try again.', 'newspack-plugin' ) ); ?>"><span><?php esc_html_e( 'Copy invite link', 'newspack-plugin' ); ?></span></button>
					<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__confirm-regenerate <?php echo esc_attr( ! $invite_link ? 'hidden' : '' ); ?>"><?php esc_html_e( 'Regenerate invite link', 'newspack-plugin' ); ?></button>
					<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__button--destructive newspack-my-account__group_subscription__invite-link__confirm-disable <?php echo esc_attr( ! $invite_link ? 'hidden' : '' ); ?>"><?php esc_html_e( 'Disable invite link', 'newspack-plugin' ); ?></button>
				</div>
			<?php endif; ?>
		</div>
	</div>
<?php else : ?>
	<div class="newspack-ui__tabs newspack-my-account__group_subscription__tabs">
		<div class="newspack-ui__tabs__list" role="tablist">
			<button
				type="button"
				role="tab"
				id="newspack-my-account__group_subscription__tab-members"
				aria-controls="newspack-my-account__group_subscription__panel-members"
				aria-selected="<?php echo 'members' === $active_tab ? 'true' : 'false'; ?>"
				tabindex="<?php echo 'members' === $active_tab ? '0' : '-1'; ?>"
				class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small<?php echo 'members' === $active_tab ? ' selected' : ''; ?>"
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
				class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small<?php echo 'invites' === $active_tab ? ' selected' : ''; ?>"
			>
				<?php esc_html_e( 'Invitations', 'newspack-plugin' ); ?>
				<span class="newspack-ui__badge newspack-ui__badge--outline newspack-group-subscription--invitations-count"><?php echo esc_html( count( $all_invites ) ); ?></span>
			</button>
		</div>
		<div class="newspack-ui__tabs__content">
			<div
				id="newspack-my-account__group_subscription__panel-members"
				role="tabpanel"
				aria-labelledby="newspack-my-account__group_subscription__tab-members"
				class="newspack-ui__tabs__panel<?php echo 'members' === $active_tab ? ' selected' : ''; ?>"
			>
	<?php if ( empty( $members ) && ! empty( $pending_invites ) ) : ?>
		<p class="newspack-my-account__group_subscription__panel-info">
			<?php esc_html_e( 'Your invitations are still pending. Anyone who accepts will appear here.', 'newspack-plugin' ); ?>
		</p>
	<?php endif; ?>
	<?php
	$render_search_field(
		'members',
		__( 'Search by name or email', 'newspack-plugin' ),
		/* translators: 1: number of members matching the search, 2: total number of members. */
		_n( 'Showing %1$d of %2$d member', 'Showing %1$d of %2$d members', count( $members_by_id ), 'newspack-plugin' )
	);
	?>
	<table id="newspack-my-account__group_subscription__table-members" class="shop_table shop_table_responsive newspack-my-account__group_subscription__members">
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
		foreach ( $managers_and_members as $user_id ) :
			$user = $members_by_id[ $user_id ] ?? null;
			if ( ! $user ) {
				continue;
			}
			// Check membership against the hoisted manager list (which depends only on the
			// subscription) instead of calling user_is_manager() per row, which would re-resolve
			// the managers and group settings every iteration. Apply the same
			// newspack_group_subscription_user_is_manager filter user_is_manager() applies, so the
			// extension point is preserved.
			$is_manager    = (bool) apply_filters( 'newspack_group_subscription_user_is_manager', in_array( $user_id, $managers, true ), $user_id, $subscription );
			$is_owner      = $user_id === (int) $subscription->get_user_id();
			$display_label = newspack_get_user_display_label( $user );
			$member_role   = $is_manager ? __( 'Manager', 'newspack-plugin' ) : __( 'Member', 'newspack-plugin' );
			if ( $is_owner ) {
				$member_role = __( 'Owner', 'newspack-plugin' );
			}
			?>
			<tr data-search-text="<?php echo esc_attr( $display_label . ' ' . sanitize_email( $user->user_email ) ); ?>">
				<td data-title="<?php esc_attr_e( 'Name', 'newspack-plugin' ); ?>">
					<strong><?php echo esc_html( $display_label ); ?></strong>
					<?php if ( $user_id === $current_user_id ) : ?>
						<?php esc_html_e( ' (you)', 'newspack-plugin' ); ?>
					<?php endif; ?>
				</td>
				<td data-title="<?php esc_attr_e( 'Email', 'newspack-plugin' ); ?>"><a href="mailto:<?php echo esc_attr( sanitize_email( $user->user_email ) ); ?>"><?php echo esc_html( sanitize_email( $user->user_email ) ); ?></a></td>
				<td data-title="<?php esc_attr_e( 'Role', 'newspack-plugin' ); ?>"><?php echo esc_html( $member_role ); ?></td>
				<td class="newspack-my-account__group_subscription__members--actions order-actions">
					<?php
					// The owner's row has no actions. A manager row is only actionable by
					// the owner (peer managers are off limits); a manager viewing manages
					// plain members only.
					$show_row_actions = ! $is_owner && $is_manageable && ( $viewer_is_owner || ! $is_manager );
					?>
					<?php if ( $show_row_actions ) : ?>
					<div class="newspack-ui__dropdown">
						<button class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small newspack-ui__dropdown__toggle newspack-ui__button--icon">
							<?php Newspack_UI_Icons::print_svg( 'more' ); ?>
							<span class="screen-reader-text"><?php \esc_html_e( 'More', 'newspack-plugin' ); ?></span>
						</button>
							<div class="newspack-ui__dropdown__content">
								<ul>
									<?php if ( $viewer_is_owner ) : ?>
									<li>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="newspack_group_subscription_set_manager_role">
											<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
											<input type="hidden" name="member_id" value="<?php echo esc_attr( $user->ID ); ?>">
											<input type="hidden" name="role" value="<?php echo esc_attr( $is_manager ? 'member' : 'manager' ); ?>">
											<?php wp_nonce_field( Group_Subscription_MyAccount::SET_MANAGER_ROLE_NONCE_ACTION ); ?>
											<button type="submit" class="newspack-ui__button newspack-ui__button--ghost">
												<?php echo esc_html( $is_manager ? __( 'Remove manager', 'newspack-plugin' ) : __( 'Make manager', 'newspack-plugin' ) ); ?>
											</button>
										</form>
									</li>
									<?php endif; ?>
									<li>
										<button
											type="button"
											class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive newspack-my-account__group_subscription__remove-member"
											data-member-id="<?php echo esc_attr( $user->ID ); ?>"
											data-member-name="<?php echo esc_attr( $display_label ); ?>"
										>
											<?php \esc_html_e( 'Remove member', 'newspack-plugin' ); ?>
										</button>
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
	<p class="newspack-my-account__group_subscription__panel-info" data-search-empty hidden>
		<?php esc_html_e( 'No members match your search.', 'newspack-plugin' ); ?>
	</p>
			</div><!-- .newspack-ui__tabs__panel (members) -->
			<div
			id="newspack-my-account__group_subscription__panel-invites"
			role="tabpanel"
			aria-labelledby="newspack-my-account__group_subscription__tab-invites"
			class="newspack-ui__tabs__panel<?php echo 'invites' === $active_tab ? ' selected' : ''; ?>"
		>

	<?php if ( empty( $all_invites ) ) : ?>
		<p class="newspack-my-account__group_subscription__panel-info">
			<?php esc_html_e( 'No pending invitations.', 'newspack-plugin' ); ?>
		</p>
	<?php else : ?>
		<?php
		$render_search_field(
			'invites',
			__( 'Search by email', 'newspack-plugin' ),
			/* translators: 1: number of invitations matching the search, 2: total number of invitations. */
			_n( 'Showing %1$d of %2$d invitation', 'Showing %1$d of %2$d invitations', count( $all_invites ), 'newspack-plugin' )
		);
		?>
	<table id="newspack-my-account__group_subscription__table-invites" class="shop_table shop_table_responsive newspack-my-account__group_subscription__invites">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Sent to', 'newspack-plugin' ); ?></th>
				<th><?php esc_html_e( 'Status', 'newspack-plugin' ); ?></th>
				<th>&nbsp;</th>
			</tr>
		</thead>
		<tbody>
		<?php
		foreach ( $all_invites as $key => $invite ) :
			?>
			<tr data-search-text="<?php echo esc_attr( sanitize_email( $invite['email'] ) ); ?>">
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
								<?php if ( $is_active ) : ?>
								<li>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="newspack_group_subscription_invite">
										<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
										<input type="hidden" name="newspack-group-subscription-invite-email" value="<?php echo esc_attr( sanitize_email( $invite['email'] ) ); ?>">
										<?php wp_nonce_field( Group_Subscription_MyAccount::INVITE_NONCE_ACTION ); ?>
										<button type="submit" class="newspack-ui__button newspack-ui__button--ghost"><?php \esc_html_e( 'Resend invite', 'newspack-plugin' ); ?></button>
									</form>
								</li>
								<?php endif; ?>
								<li>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="newspack_group_subscription_cancel_invite">
										<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
										<input type="hidden" name="email" value="<?php echo esc_attr( sanitize_email( $invite['email'] ) ); ?>">
										<?php wp_nonce_field( Group_Subscription_MyAccount::CANCEL_INVITE_NONCE_ACTION ); ?>
										<button type="submit" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--destructive"><?php \esc_html_e( 'Cancel invite', 'newspack-plugin' ); ?></button>
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
	<p class="newspack-my-account__group_subscription__panel-info" data-search-empty hidden>
		<?php esc_html_e( 'No invitations match your search.', 'newspack-plugin' ); ?>
	</p>
	<?php endif; ?>
			</div><!-- .newspack-ui__tabs__panel (invites) -->
		</div><!-- .newspack-ui__tabs__content -->
	</div><!-- .newspack-ui__tabs -->
<?php endif; // ! $is_completely_empty ?>

	<!-- .newspack-ui__modal: invite by email -->
	<dialog id="newspack-my-account__group_subscription--invite-member" class="newspack-ui__modal-container">
		<div class="newspack-ui__modal-container__overlay"></div>
		<div class="newspack-ui__modal newspack-ui__modal--small">
				<header class="newspack-ui__modal__header">
					<h2>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: lowercase singular group label. */
								__( 'Invite a %s member', 'newspack-plugin' ),
								$group_label_lower
							)
						);
						?>
					</h2>

					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
					</button>
				</header>

				<section class="newspack-ui__modal__content">
					<?php if ( $is_at_limit ) : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: lowercase singular group label, 2: lowercase singular group label (again). */
									__( 'You have reached the member limit for this %1$s. Please remove some members or cancel pending invitations before inviting more %2$s members.', 'newspack-plugin' ),
									$group_label_lower,
									$group_label_lower
								)
							);
							?>
						</p>
					<?php else : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: lowercase singular group label, 2: duration label like "30 days" or "1 hour". */
									__( 'They\'ll get an email with a link to join the %1$s. The link expires in %2$s.', 'newspack-plugin' ),
									$group_label_lower,
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

							<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide"><span><?php esc_html_e( 'Invite', 'newspack-plugin' ); ?></span></button>
							<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close"><?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
						</form>
					<?php endif; ?>
				</section>
			</div><!-- .newspack-ui__modal__small -->
	</dialog>

	<!-- .newspack-ui__modal: invite in bulk -->
	<dialog id="newspack-my-account__group_subscription--invite-bulk" class="newspack-ui__modal-container">
		<div class="newspack-ui__modal-container__overlay"></div>
		<div class="newspack-ui__modal newspack-ui__modal--small">
			<header class="newspack-ui__modal__header">
				<h2><?php esc_html_e( 'Invite in bulk', 'newspack-plugin' ); ?></h2>
				<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
					<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
					<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
				</button>
			</header>
			<section class="newspack-ui__modal__content">
				<?php if ( $is_at_limit ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: lowercase singular group label, 2: lowercase singular group label (again). */
								__( 'You have reached the member limit for this %1$s. Please remove some members or cancel pending invitations before inviting more %2$s members.', 'newspack-plugin' ),
								$group_label_lower,
								$group_label_lower
							)
						);
						?>
					</p>
				<?php else : ?>
					<div class="newspack-ui__notice newspack-ui__notice--error newspack-my-account__group_subscription__invite-bulk__error hidden" role="alert"><p class="newspack-ui__spacing-top--0 newspack-ui__spacing-bottom--0"></p></div>
					<?php // Step 1: the list. The server parses it, so the review below shows exactly what it will send. ?>
					<form
						class="newspack-my-account__group_subscription__invite-bulk__input"
						data-error-text="<?php echo esc_attr( __( 'Something went wrong. Please try again.', 'newspack-plugin' ) ); ?>"
						data-unfinished-text="<?php echo esc_attr( __( 'The request didn\'t finish, so some invitations may have been sent. Check the Invitations tab before trying again.', 'newspack-plugin' ) ); ?>"
					>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: duration label like "30 days" or "1 hour". */
									__( 'Each person gets an email with a link to join. The link expires in %s.', 'newspack-plugin' ),
									Group_Subscription_Invite::get_expiration_label()
								)
							);
							?>
						</p>
						<p>
							<label for="newspack-group-subscription-invite-bulk-emails"><?php esc_html_e( 'Email addresses', 'newspack-plugin' ); ?></label>
							<textarea id="newspack-group-subscription-invite-bulk-emails" name="emails" rows="5" aria-describedby="newspack-group-subscription-invite-bulk-help"></textarea>
							<span id="newspack-group-subscription-invite-bulk-help" class="newspack-ui__helper-text">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: the most people one list may invite. */
										__( 'Separate addresses with commas, spaces or new lines, or upload a CSV file with the addresses in its first column. Up to %d people at a time.', 'newspack-plugin' ),
										Group_Subscription_Bulk_Invite::MAX_INVITES
									)
								);
								?>
							</span>
						</p>
						<p>
							<input type="file" id="newspack-group-subscription-invite-bulk-csv" class="newspack-my-account__group_subscription__invite-bulk__file" name="csv" accept=".csv,text/csv">
							<label for="newspack-group-subscription-invite-bulk-csv" class="newspack-ui__button newspack-ui__button--secondary newspack-ui__button--small"><?php esc_html_e( 'Upload CSV', 'newspack-plugin' ); ?></label>
							<span class="newspack-my-account__group_subscription__invite-bulk__filename"></span>
							<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--small newspack-my-account__group_subscription__invite-bulk__remove-file hidden"><?php esc_html_e( 'Remove file', 'newspack-plugin' ); ?></button>
						</p>
						<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide"><span><?php esc_html_e( 'Review', 'newspack-plugin' ); ?></span></button>
						<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close"><?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
					</form>
					<?php // Step 2: the review, then the outcome. Filled in by group-subscriptions.js. ?>
					<div class="newspack-my-account__group_subscription__invite-bulk__review hidden" tabindex="-1">
						<ul class="newspack-my-account__group_subscription__invite-bulk__summary"></ul>
						<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-my-account__group_subscription__invite-bulk__send"><span><?php esc_html_e( 'Send invitations', 'newspack-plugin' ); ?></span></button>
						<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-my-account__group_subscription__invite-bulk__back"><?php esc_html_e( 'Back', 'newspack-plugin' ); ?></button>
						<a href="<?php echo esc_url( add_query_arg( 'activeTab', 'invites' ) ); ?>" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-my-account__group_subscription__invite-bulk__done hidden"><?php esc_html_e( 'Done', 'newspack-plugin' ); ?></a>
					</div>
				<?php endif; ?>
			</section>
		</div>
	</dialog>

	<!-- .newspack-ui__modal: regenerate invite link -->
	<dialog id="newspack-my-account__group_subscription--confirm-regenerate-link" class="newspack-ui__modal-container">
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
							<?php esc_html_e( 'Any invite link for this group will stop working. You\'ll get a new link to share, and anyone who hasn\'t joined yet will need the new link.', 'newspack-plugin' ); ?>
						</p>

						<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-my-account__group_subscription__invite-link__regenerate" data-error-text="<?php echo esc_attr( __( 'Could not regenerate. Please try again.', 'newspack-plugin' ) ); ?>"><span><?php esc_html_e( 'Regenerate link', 'newspack-plugin' ); ?></span></button>
						<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close"><?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
				</section>
			</div><!-- .newspack-ui__modal__small -->
	</dialog>

	<!-- .newspack-ui__modal: confirm remove member -->
	<?php
	$remove_member_title = sprintf(
		/* translators: %s: lowercase singular group label. */
		__( 'Remove %s member', 'newspack-plugin' ),
		$group_label_lower
	);
	?>
	<dialog id="newspack-my-account__group_subscription--confirm-remove-member" class="newspack-ui__modal-container">
		<div class="newspack-ui__modal-container__overlay"></div>
		<div class="newspack-ui__modal newspack-ui__modal--small">
				<header class="newspack-ui__modal__header">
					<h2><?php echo esc_html( $remove_member_title ); ?></h2>

					<button class="newspack-ui__button newspack-ui__button--icon newspack-ui__button--ghost newspack-ui__modal__close">
						<span class="screen-reader-text"><?php esc_html_e( 'Close', 'newspack-plugin' ); ?></span>
						<?php Newspack_UI_Icons::print_svg( 'close' ); ?>
					</button>
				</header>

				<form class="newspack-ui__modal__content" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<h2 class="newspack-ui__font--l"><?php esc_html_e( 'Are you sure?', 'newspack-plugin' ); ?></h2>
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: 1: member's display name or email placeholder span, 2: lowercase singular group label. */
								__( '%1$s will immediately lose access to this %2$s. You can re-invite them later if you change your mind.', 'newspack-plugin' ),
								'<span data-member-name></span>',
								esc_html( $group_label_lower )
							),
							[ 'span' => [ 'data-member-name' => true ] ]
						);
						?>
					</p>
					<input type="hidden" name="action" value="newspack_group_subscription_remove_member">
					<input type="hidden" name="subscription_id" value="<?php echo esc_attr( $subscription->get_id() ); ?>">
					<input type="hidden" name="member_id" value="" data-remove-member-id>
					<?php wp_nonce_field( Group_Subscription_MyAccount::REMOVE_MEMBER_NONCE_ACTION ); ?>
					<button type="submit" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-ui__button--destructive">
						<span><?php esc_html_e( 'Remove member', 'newspack-plugin' ); ?></span>
					</button>
					<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close">
						<?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?>
					</button>
				</form>
			</div><!-- .newspack-ui__modal__small -->
	</dialog>

	<!-- .newspack-ui__modal: disable invite link -->
	<dialog id="newspack-my-account__group_subscription--confirm-disable-link" class="newspack-ui__modal-container">
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
						<h2 class="newspack-ui__font--l"><?php esc_html_e( 'Are you sure?', 'newspack-plugin' ); ?></h2>
						<p>
							<?php esc_html_e( 'Any invite link for this group will stop working. Anyone who hasn\'t joined yet will no longer be able to. You can create a new link at any time.', 'newspack-plugin' ); ?>
						</p>

						<button type="button" class="newspack-ui__button newspack-ui__button--primary newspack-ui__button--wide newspack-ui__button--destructive newspack-my-account__group_subscription__invite-link__disable" data-error-text="<?php echo esc_attr( __( 'Could not disable. Please try again.', 'newspack-plugin' ) ); ?>"><span><?php esc_html_e( 'Disable link', 'newspack-plugin' ); ?></span></button>
						<button type="button" class="newspack-ui__button newspack-ui__button--ghost newspack-ui__button--wide newspack-ui__modal__close"><?php esc_html_e( 'Cancel', 'newspack-plugin' ); ?></button>
				</section>
			</div><!-- .newspack-ui__modal__small -->
	</dialog>
</div>
