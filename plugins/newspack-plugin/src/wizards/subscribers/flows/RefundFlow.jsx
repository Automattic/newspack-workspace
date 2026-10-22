/**
 * Flow — refund and/or cancel a subscription.
 *
 * An active paid subscription offers the three-way choice (refund only, cancel
 * only, both); a non-active or free one has no live payment to give back, so
 * the flow drops to a straight cancel confirmation. The server refunds the
 * latest paid order and refuses half-states — a declined refund surfaces here
 * and no cancel happens after it.
 */

/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { RadioControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal, Notice } from '../../../../packages/components/src';
import { refundIntent } from '../data/use-payments';
import { fmtCurrency } from '../format';

/**
 * @param {Object}   props                Component props.
 * @param {Object}   props.subscription   The profile subscription entry.
 * @param {string}   props.subscriberName The subscriber's display name.
 * @param {Object}   props.actions        The payment write calls (usePaymentActions).
 * @param {Function} props.onClose        Close without acting.
 * @param {Function} props.onDone         Called with a success message afterwards.
 */
export default function RefundFlow( { subscription, subscriberName, actions, onClose, onDone } ) {
	const [ choice, setChoice ] = useState( 'refund-only' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	// The refund choice is only offered when the server reports money to give
	// back: an active subscription with a refundable balance on its latest paid
	// order. Anything else — on hold, free, or already fully refunded — drops to
	// a plain cancel. The promised amount is the server's refundableAmount, not
	// the plan price: after a plan change or partial refund the two differ, and
	// this copy is a promise about real money.
	const cancelOnly = 'active' !== subscription.status || ! subscription.refundableAmount;
	// The refunded money goes back in the refunded order's own currency, which
	// on a multi-currency store can differ from the subscription's price.
	const refundCurrency = subscription.refundableCurrency || subscription.currency;
	const amount = fmtCurrency( subscription.refundableAmount, refundCurrency );
	const name = subscriberName || __( 'The subscriber', 'newspack-plugin' );

	const submit = async () => {
		const { willRefund, willCancel } = refundIntent( choice, cancelOnly );
		setBusy( true );
		setError( '' );
		try {
			const result = await actions.refund( subscription.id, {
				refund: willRefund,
				cancel: willCancel,
				expectedAmount: willRefund ? subscription.refundableAmount : null,
			} );
			const refunded = fmtCurrency( result.refunded, refundCurrency );
			let message;
			// The first branch covers both refund-and-cancel and a refund-only that
			// WCS itself turned into a cancellation (full refund of a pending-cancel
			// subscription) — the admin must not walk away believing access continues.
			if ( willRefund && result.cancelled ) {
				// translators: %s is the refunded amount.
				message = sprintf( __( 'Refund of %s processed and subscription cancelled.', 'newspack-plugin' ), refunded );
			} else if ( willRefund ) {
				// translators: %s is the refunded amount.
				message = sprintf( __( 'Refund of %s processed.', 'newspack-plugin' ), refunded );
			} else {
				// translators: %s is the subscription plan name.
				message = sprintf( __( '%s cancelled.', 'newspack-plugin' ), subscription.plan );
			}
			if ( willRefund && ! result.gatewayRefund ) {
				message +=
					' ' +
					__( 'The gateway does not refund automatically, so the payment must be returned to the subscriber manually.', 'newspack-plugin' );
			}
			onDone( message );
		} catch ( e ) {
			// The gateway knows why it declined; its message travels verbatim.
			let message = e?.message || __( 'Something went wrong.', 'newspack-plugin' );
			if ( e?.data?.refunded ) {
				// The refund half already moved money before the cancel failed —
				// the admin must know, or a natural retry double-charges the flow.
				message = sprintf(
					// translators: 1: the refunded amount, 2: the server's error message.
					__( 'A refund of %1$s was processed, but the cancellation failed: %2$s Use “Cancel only” to finish.', 'newspack-plugin' ),
					fmtCurrency( e.data.refunded, refundCurrency ),
					message
				);
			}
			setError( message );
			setBusy( false );
		}
	};

	// What the selected choice does, spelled out before the destructive click.
	let confirmDetail;
	if ( cancelOnly ) {
		if ( ! subscription.amount ) {
			confirmDetail = __(
				'This subscription is free, so there is no payment to refund. Cancelling it ends access immediately.',
				'newspack-plugin'
			);
		} else if ( 'active' !== subscription.status ) {
			confirmDetail = __(
				'This subscription is not active, so there is no payment to refund. Cancelling it ends access immediately.',
				'newspack-plugin'
			);
		} else {
			confirmDetail = __(
				'There is no refundable payment left on this subscription. Cancelling it ends access immediately.',
				'newspack-plugin'
			);
		}
	} else if ( 'cancel-only' === choice ) {
		// translators: %s is the subscriber's name.
		confirmDetail = sprintf( __( '%s’s access ends immediately. No refund is issued.', 'newspack-plugin' ), name );
	} else if ( 'refund-only' === choice ) {
		// A pending-cancel subscription (badged Active) will never renew, so the
		// copy must not promise a renewal — and a full refund may end it at once.
		confirmDetail = subscription.isPendingCancel
			? sprintf(
					// translators: 1: the subscriber's name, 2: the refund amount.
					__(
						'%1$s will be refunded %2$s. Their subscription is already ending; a full refund may cancel it immediately.',
						'newspack-plugin'
					),
					name,
					amount
			  )
			: sprintf(
					// translators: 1: the subscriber's name, 2: the refund amount.
					__( '%1$s will be refunded %2$s. Their access continues and they’ll renew normally.', 'newspack-plugin' ),
					name,
					amount
			  );
	} else {
		confirmDetail = sprintf(
			// translators: 1: the subscriber's name, 2: the refund amount.
			__( '%1$s will be refunded %2$s and their access ends immediately.', 'newspack-plugin' ),
			name,
			amount
		);
	}

	return (
		<Modal
			title={ cancelOnly ? __( 'Cancel subscription', 'newspack-plugin' ) : __( 'Refund or cancel', 'newspack-plugin' ) }
			onRequestClose={ busy ? () => {} : onClose }
			size="small"
		>
			<VStack spacing={ 4 }>
				{ /* role=alert: a declined refund must be announced, not appear silently while focus sits on the re-enabled Confirm. */ }
				{ error && (
					<div role="alert">
						<Notice isError noticeText={ error } />
					</div>
				) }
				<p className="newspack-subscribers__modal-text">
					<strong>{ subscription.plan }</strong>
					{ !! subscription.amount && ` — ${ fmtCurrency( subscription.amount, subscription.currency ) }` }
				</p>
				{ cancelOnly ? (
					<p className="newspack-subscribers__modal-text">{ confirmDetail }</p>
				) : (
					<>
						<RadioControl
							label={ __( 'What would you like to do?', 'newspack-plugin' ) }
							selected={ choice }
							options={ [
								{ label: __( 'Refund only (keep subscription active)', 'newspack-plugin' ), value: 'refund-only' },
								{ label: __( 'Cancel only (no refund)', 'newspack-plugin' ), value: 'cancel-only' },
								{ label: __( 'Refund and cancel subscription', 'newspack-plugin' ), value: 'refund-cancel' },
							] }
							onChange={ setChoice }
						/>
						<p className="newspack-subscribers__modal-text">{ confirmDetail }</p>
					</>
				) }
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onClose }>
						{ cancelOnly ? __( 'Keep subscription', 'newspack-plugin' ) : __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						size="compact"
						isBusy={ busy }
						disabled={ busy }
						isDestructive={ cancelOnly || 'refund-only' !== choice }
						onClick={ submit }
					>
						{ cancelOnly ? __( 'Cancel subscription', 'newspack-plugin' ) : __( 'Confirm', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
