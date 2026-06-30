/**
 * "View subscription" drawer for the group detail view.
 *
 * A self-contained snapshot of the group subscription in a right-edge slide-in
 * panel, mirroring the newspack-newsletters quick-edit look: an identity tier
 * (status, owner, seats), then the billing tier (first subscribed, billing rate,
 * last payment, next billing / cancelled). The footer carries the same
 * subscription CTAs as the person profile's group card (change, refund/cancel,
 * reactivate, resubscribe), gated by status.
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
import { getPlanOptions, seatsUsed, GROUP_STATUS_LABELS, GROUP_STATUS_BADGE_LEVEL } from '../data/mock-groups';
import { latestOrderDate, lastPaidValue } from '../data/orders';
import { fmtCurrency, fmtDate } from '../format';

const cadenceSuffix = cadence => ( cadence === 'Monthly' ? __( 'month', 'newspack-plugin' ) : __( 'year', 'newspack-plugin' ) );

function Row( { label, children } ) {
	return (
		<VStack spacing={ 1 } className="newspack-subscribers-demo__sub-detail-row">
			<span className="newspack-subscribers-demo__sub-detail-label">{ label }</span>
			<span className="newspack-subscribers-demo__sub-detail-value">{ children }</span>
		</VStack>
	);
}

export default function SubscriptionDetailsDrawer( {
	group,
	owner,
	onViewOwner,
	onClose,
	onChangePlan,
	onRefundCancel,
	onReactivate,
	onResubscribe,
} ) {
	const isActive = group.status === 'active';
	const isOnHold = group.status === 'on-hold';
	const isCancelled = group.status === 'cancelled';
	// A free-forever (zero-amount) group was never charged, so there is nothing
	// to refund — the action is a plain cancel.
	const isFreeForever = group.amount === 0;
	const canChangePlan = getPlanOptions( group, true ).length > 0;
	// Close through the Modal's own Escape handler so the slide-out (exit)
	// animation runs; calling onClose directly unmounts the panel with no
	// animation, since the Modal only animates closes that it initiates.
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
			className="newspack-subscribers-demo__sub-detail-modal"
			overlayClassName="newspack-subscribers-demo__sub-detail-overlay"
		>
			<HStack className="newspack-subscribers-demo__sub-detail-header" spacing={ 2 } alignment="center">
				<h2 className="newspack-subscribers-demo__sub-detail-title">{ group.plan }</h2>
				<Button icon={ close } size="small" label={ __( 'Close', 'newspack-plugin' ) } onClick={ requestClose } />
			</HStack>
			<div className="newspack-subscribers-demo__sub-detail-content">
				<VStack spacing={ 4 }>
					<Row label={ __( 'Status', 'newspack-plugin' ) }>
						<Badge level={ GROUP_STATUS_BADGE_LEVEL[ group.status ] } text={ GROUP_STATUS_LABELS[ group.status ] } />
					</Row>
					<Row label={ __( 'Owner', 'newspack-plugin' ) }>
						<Button variant="link" onClick={ onViewOwner }>
							{ owner?.name || __( 'Unknown', 'newspack-plugin' ) }
						</Button>
					</Row>
					<Row label={ __( 'Seats', 'newspack-plugin' ) }>
						{ sprintf(
							// translators: 1: seats used, 2: seat limit.
							__( '%1$d of %2$d', 'newspack-plugin' ),
							seatsUsed( group ),
							group.seatLimit
						) }
					</Row>
					<Divider marginTop={ 0 } marginBottom={ 0 } />
					<Row label={ __( 'First subscribed', 'newspack-plugin' ) }>{ fmtDate( group.createdAt ) || '—' }</Row>
					<Row label={ __( 'Billing', 'newspack-plugin' ) }>
						{ sprintf(
							/* translators: %1$s is a price, %2$s is a billing period (month/year). */
							__( '%1$s / %2$s', 'newspack-plugin' ),
							fmtCurrency( group.amount ),
							cadenceSuffix( group.cadence )
						) }
					</Row>
					<Row label={ __( 'Last payment', 'newspack-plugin' ) }>{ lastPaidValue( owner?.orders, group.id ) }</Row>
					{ group.status === 'cancelled' ? (
						<Row label={ __( 'Cancelled', 'newspack-plugin' ) }>
							{ fmtDate( latestOrderDate( owner?.orders, group.id, 'Cancellation' ) ) || '—' }
						</Row>
					) : (
						group.nextBillingDate && <Row label={ __( 'Next billing', 'newspack-plugin' ) }>{ fmtDate( group.nextBillingDate ) }</Row>
					) }
				</VStack>
			</div>
			<HStack className="newspack-subscribers-demo__sub-detail-footer" spacing={ 2 } justify="flex-end">
				{ isActive && canChangePlan && (
					<Button variant="secondary" onClick={ onChangePlan }>
						{ __( 'Change subscription', 'newspack-plugin' ) }
					</Button>
				) }
				{ isActive && (
					<Button variant="secondary" isDestructive onClick={ onRefundCancel }>
						{ isFreeForever ? __( 'Cancel', 'newspack-plugin' ) : __( 'Refund or cancel', 'newspack-plugin' ) }
					</Button>
				) }
				{ isOnHold && (
					<Button variant="primary" onClick={ onReactivate }>
						{ __( 'Reactivate', 'newspack-plugin' ) }
					</Button>
				) }
				{ isOnHold && (
					<Button variant="secondary" isDestructive onClick={ onRefundCancel }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
				) }
				{ isCancelled && (
					<Button variant="primary" onClick={ onResubscribe }>
						{ __( 'Resubscribe', 'newspack-plugin' ) }
					</Button>
				) }
			</HStack>
		</Modal>
	);
}
