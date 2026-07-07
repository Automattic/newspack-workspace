/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * Flow A — Refund / Cancel.
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { RadioControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal } from '../../../../packages/components/src';
import { GROUP_LABEL_LOWER } from '../labels';
import { fmtCurrency } from '../format';

export default function RefundFlow( { subscription, group, subscriber, onClose, onComplete } ) {
	const [ choice, setChoice ] = useState( 'refund-only' );
	const [ state, setState ] = useState( 'choose' ); // choose | loading
	// A group subscription is billed to its owner, so it's refunded/cancelled the
	// same way; cancelling it flips the group's status rather than a personal sub.
	const item = subscription || group;
	const isGroup = !! group;
	// A non-active subscription/group has no live payment to refund, and a
	// free-forever (zero-amount) one was never charged, so the flow drops to a
	// straight cancel in both cases: skip the refund choice and just confirm.
	const cancelOnly = item.status !== 'active' || item.amount === 0;
	const amount = fmtCurrency( item.amount );
	const noun = isGroup ? GROUP_LABEL_LOWER : __( 'subscription', 'newspack-plugin' );

	// Cancel a personal subscription: mark it cancelled, drop the renewal date,
	// log the cancellation, and cancel the subscriber if nothing active remains.
	const cancelSubscription = current => {
		const subscriptions = current.subscriptions.map( s =>
			s.id === subscription.id ? { ...s, status: 'cancelled', nextBillingDate: null } : s
		);
		const hasActive = subscriptions.some( s => s.status === 'active' );
		const cancellation = {
			id: 'ord_cancel_' + subscription.id,
			date: new Date().toISOString().slice( 0, 10 ),
			amount: 0,
			type: 'Cancellation',
		};
		return {
			...current,
			status: hasActive ? current.status : 'cancelled',
			subscriptions,
			orders: [ cancellation, ...( current.orders || [] ) ],
		};
	};

	const submit = () => {
		setState( 'loading' );
		setTimeout( () => {
			if ( cancelOnly ) {
				const message = sprintf( __( '%s cancelled.', 'newspack-plugin' ), item.plan );
				onComplete( isGroup ? { type: 'success', message, groupCancel: group } : { type: 'success', message, mutate: cancelSubscription } );
				return;
			}
			const willRefund = choice !== 'cancel-only';
			const willCancel = choice !== 'refund-only';
			let message;
			if ( willRefund && willCancel ) {
				message = sprintf( __( 'Refund of %s processed and subscription cancelled.', 'newspack-plugin' ), amount );
			} else if ( willRefund ) {
				message = sprintf( __( 'Refund of %s processed.', 'newspack-plugin' ), amount );
			} else {
				message = sprintf( __( '%s cancelled.', 'newspack-plugin' ), item.plan );
			}
			if ( isGroup ) {
				onComplete( { type: 'success', message, groupCancel: willCancel ? group : null } );
				return;
			}
			onComplete( { type: 'success', message, mutate: willCancel ? cancelSubscription : current => current } );
		}, 700 );
	};

	const title = cancelOnly ? sprintf( __( 'Cancel %s', 'newspack-plugin' ), noun ) : __( 'Refund or cancel', 'newspack-plugin' );
	const busy = state === 'loading';
	const subscriberName = subscriber?.name || __( 'The subscriber', 'newspack-plugin' );

	// Why a cancel-only flow has nothing to refund: a free-forever (zero-amount)
	// sub was never charged; any other cancel-only case is a non-active (on-hold)
	// one. The member-facing variant calls out that all members lose access.
	let cancelReason;
	if ( item.amount === 0 ) {
		cancelReason = isGroup
			? // translators: %s: lowercase group label (e.g. "cohort").
			  sprintf(
					__(
						'This %s is free, so there is no payment to refund. Cancelling it ends access for all members immediately.',
						'newspack-plugin'
					),
					GROUP_LABEL_LOWER
			  )
			: __( 'This subscription is free, so there is no payment to refund. Cancelling it ends access immediately.', 'newspack-plugin' );
	} else {
		cancelReason = isGroup
			? // translators: %s: lowercase group label (e.g. "cohort").
			  sprintf(
					__(
						'This %s is on hold, so there is no payment to refund. Cancelling it ends access for all members immediately.',
						'newspack-plugin'
					),
					GROUP_LABEL_LOWER
			  )
			: __( 'This subscription is on hold, so there is no payment to refund. Cancelling it ends access immediately.', 'newspack-plugin' );
	}

	// What the selected choice does, spelled out for the active (refund) flow.
	let confirmDetail;
	if ( choice === 'cancel-only' ) {
		confirmDetail = sprintf( __( '%s’s access ends immediately. No refund is issued.', 'newspack-plugin' ), subscriberName );
	} else if ( choice === 'refund-only' ) {
		confirmDetail = sprintf(
			__( '%1$s will be refunded %2$s and notified by email. Their access continues and they’ll renew normally.', 'newspack-plugin' ),
			subscriberName,
			amount
		);
	} else {
		confirmDetail = sprintf(
			__( '%1$s will be refunded %2$s and notified by email. Their access ends immediately.', 'newspack-plugin' ),
			subscriberName,
			amount
		);
	}

	return (
		<Modal title={ title } onRequestClose={ onClose } size="small">
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
				<p>
					<strong>{ sprintf( __( '%1$s — %2$s %3$s', 'newspack-plugin' ), item.plan, amount, item.cadence.toLowerCase() ) }</strong>
				</p>
				{ cancelOnly ? (
					<p>{ cancelReason }</p>
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
						<p>{ confirmDetail }</p>
					</>
				) }
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onClose }>
						{ cancelOnly ? sprintf( __( 'Keep %s', 'newspack-plugin' ), noun ) : __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy } isDestructive={ cancelOnly } onClick={ submit }>
						{ cancelOnly ? sprintf( __( 'Cancel %s', 'newspack-plugin' ), noun ) : __( 'Confirm', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
