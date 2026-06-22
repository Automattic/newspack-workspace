<?php
/**
 * Picker shown to managers of multiple groups when they enter the Group endpoint without a subscription ID.
 *
 * @var WC_Subscription[] $managed Group subscriptions the current user manages.
 *
 * @author   Newspack
 * @category WooCommerce Subscriptions/Templates
 * @package  Newspack
 */

use Newspack\Group_Subscription;
use Newspack\Group_Subscription_MyAccount;
use Newspack\Group_Subscription_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Sort most-recently-created first.
usort(
	$managed,
	function( $a, $b ) {
		$ta = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
		$tb = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
		return $tb <=> $ta;
	}
);
?>
<div class="newspack-my-account__group-picker">
	<header class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-1">
		<h1 class="newspack-ui__font--m">
			<?php echo esc_html( Group_Subscription::get_label( 'plural' ) ); ?>
		</h1>
		<p>
			<?php
			/* translators: %s: singular group label (e.g. "group", "team"). */
			printf( esc_html__( 'Pick a %s to manage its members.', 'newspack-plugin' ), esc_html( Group_Subscription::get_label( 'singular' ) ) );
			?>
		</p>
	</header>

	<div class="newspack-ui__grid">
		<?php foreach ( $managed as $subscription ) : ?>
			<?php
			$settings            = Group_Subscription_Settings::get_subscription_settings( $subscription );
			$members             = Group_Subscription::get_members( $subscription );
			$member_count        = count( $members );
			$limit               = $settings['limit'] > 0 ? $settings['limit'] : null;
			$count_label         = $limit
				? sprintf(
					/* translators: 1: member count, 2: member limit */
					_x( '%1$d of %2$d members', 'group member count', 'newspack-plugin' ),
					$member_count,
					$limit
				)
				: sprintf(
					/* translators: %d: member count */
					_n( '%d member', '%d members', $member_count, 'newspack-plugin' ),
					$member_count
				);
			$subscription_status = $subscription->get_status();
			$status_classes      = [ 'newspack-ui__badge' ];
			if ( in_array( $subscription_status, [ 'cancelled', 'expired' ], true ) ) {
				$status_classes[] = 'newspack-ui__badge--error';
			} elseif ( in_array( $subscription_status, [ 'pending-cancel', 'on-hold', 'pending', 'processing' ], true ) ) {
				$status_classes[] = 'newspack-ui__badge--warning';
			} elseif ( 'active' === $subscription_status ) {
				$status_classes[] = 'newspack-ui__badge--success';
			} else {
				$status_classes[] = 'newspack-ui__badge--secondary';
			}
			?>
			<a href="<?php echo esc_url( Group_Subscription_MyAccount::get_group_url( $subscription ) ); ?>" class="newspack-ui__box newspack-ui__box--border newspack-my-account__group-picker__card">
				<div class="newspack-my-account__group-picker__card__content">
					<span class="newspack-my-account__group-picker__card__name"><?php echo esc_html( $settings['name'] ); ?></span>
					<span class="newspack-my-account__group-picker__card__meta"><?php echo esc_html( $count_label ); ?></span>
				</div>
				<div class="newspack-ui__box__actions">
					<div class="newspack-ui__box__badges">
						<span class="<?php echo esc_attr( implode( ' ', $status_classes ) ); ?>"><?php echo esc_html( wcs_get_subscription_status_name( $subscription_status ) ); ?></span>
					</div>
				</div>
			</a>
		<?php endforeach; ?>
	</div>
</div>
