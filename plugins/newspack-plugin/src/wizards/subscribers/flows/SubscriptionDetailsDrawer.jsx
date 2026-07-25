/**
 * "View subscription" drawer for the group detail screen.
 *
 * A right-edge slide-in panel holding a self-contained snapshot of the group's
 * subscription: an identity tier (status, owner, seats) and then the billing tier
 * (first subscribed, billing rate, last payment, next billing or cancelled).
 *
 * The billing rows are rendered with the shared subscription card's `CardRow`
 * (§3.2) and the shared `billingText()` formatter, so the rate and cadence shown
 * here and on a person's profile come from one implementation and cannot drift.
 *
 * NO FOOTER ACTIONS. The design's Change subscription / Refund or cancel /
 * Reactivate / Resubscribe buttons are all money surfaces belonging to the payment
 * and on-hold-recovery workstreams, which are not in this batch. The drawer ships
 * read-only rather than with dead buttons.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Modal, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { close } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import { Badge, Button, Divider } from '../../../../packages/components/src';
import { CardRow } from '../components/SubscriptionCard';
import { billingText, fmtDate, orDash, scheduleRow } from '../format';
import { STATUS_LABELS, STATUS_BADGE_LEVEL } from '../status';
import { seatCountText } from './capacity';

export default function SubscriptionDetailsDrawer( { group, onViewOwner, onClose } ) {
	const billing = group.billing || {};
	// The closing row (Next billing / Ends / Ended) is derived from the dates by the
	// shared scheduleRow(), not from the status, so a pending-cancel group — which
	// maps to the "active" status but has no next-payment date — reads "Ends <date>"
	// instead of a bare "Next billing —". Shared with the person-profile card so the
	// two cannot disagree.
	const schedule = scheduleRow( billing );

	// Close through the Modal's own Escape handler so the slide-out animation runs;
	// calling onClose directly unmounts the panel with no animation, because the
	// Modal only animates closes it initiates itself.
	const requestClose = event => {
		const frame = event.currentTarget.closest( '.components-modal__frame' );
		if ( frame ) {
			frame.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape', bubbles: true } ) );
		} else {
			onClose();
		}
	};

	return (
		<Modal
			__experimentalHideHeader
			onRequestClose={ onClose }
			className="newspack-subscribers__sub-detail-modal"
			overlayClassName="newspack-subscribers__sub-detail-overlay"
		>
			<HStack className="newspack-subscribers__sub-detail-header" spacing={ 2 } alignment="center">
				<h2 className="newspack-subscribers__sub-detail-title">{ group.plan }</h2>
				<Button icon={ close } size="small" label={ __( 'Close', 'newspack-plugin' ) } onClick={ requestClose } />
			</HStack>
			<div className="newspack-subscribers__sub-detail-content">
				<VStack spacing={ 4 }>
					<CardRow label={ __( 'Status', 'newspack-plugin' ) }>
						<Badge level={ STATUS_BADGE_LEVEL[ group.status ] } text={ STATUS_LABELS[ group.status ] } />
					</CardRow>
					<CardRow label={ __( 'Owner', 'newspack-plugin' ) }>
						{ group.owner?.name ? (
							<Button variant="link" onClick={ onViewOwner }>
								{ group.owner.name }
							</Button>
						) : (
							__( 'Unknown', 'newspack-plugin' )
						) }
					</CardRow>
					<CardRow label={ __( 'Seats', 'newspack-plugin' ) }>{ seatCountText( group ) }</CardRow>
					<Divider marginTop={ 0 } marginBottom={ 0 } />
					<CardRow label={ __( 'First subscribed', 'newspack-plugin' ) }>{ orDash( fmtDate( billing.startDate ) ) }</CardRow>
					<CardRow label={ __( 'Billing', 'newspack-plugin' ) }>{ billingText( billing ) }</CardRow>
					<CardRow label={ __( 'Last payment', 'newspack-plugin' ) }>{ orDash( fmtDate( billing.lastPayment ) ) }</CardRow>
					<CardRow label={ schedule.label }>{ orDash( schedule.value ) }</CardRow>
					{ group.editUrl && (
						<CardRow label={ __( 'WooCommerce', 'newspack-plugin' ) }>
							<Button variant="link" href={ group.editUrl }>
								{ sprintf(
									// translators: %d is a subscription ID.
									__( 'Open subscription #%d', 'newspack-plugin' ),
									group.id
								) }
							</Button>
						</CardRow>
					) }
				</VStack>
			</div>
		</Modal>
	);
}
