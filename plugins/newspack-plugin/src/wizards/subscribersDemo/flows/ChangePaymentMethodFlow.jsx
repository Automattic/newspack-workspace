/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Change a subscription's payment method.
 *
 * Points a subscription at a different card the reader already has on file.
 * Expired cards are excluded since they can't be charged.
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Modal, SelectControl } from '../../../../packages/components/src';
import { isCardExpired } from './free-access';

const label = pm => sprintf( __( '%1$s ending in %2$s', 'newspack-plugin' ), pm.type, pm.last4 );

export default function ChangePaymentMethodFlow( { subscription, subscriber, onClose, onComplete } ) {
	const cards = subscriber.paymentMethods || [];
	// Only a non-expired card can be charged, so it's the only kind worth offering.
	const usable = cards.filter( pm => ! isCardExpired( pm.expiry ) );
	const currentId = subscription.paymentMethodId || ( cards.find( c => c.isDefault ) || cards[ 0 ] )?.id;
	const [ selectedId, setSelectedId ] = useState( () => ( usable.some( c => c.id === currentId ) ? currentId : usable[ 0 ]?.id || '' ) );
	const [ state, setState ] = useState( 'choose' );
	const selected = cards.find( c => c.id === selectedId );

	const submit = () => {
		setState( 'loading' );
		setTimeout( () => {
			onComplete( {
				type: 'success',
				message: sprintf(
					// translators: 1: subscription plan, 2: card description.
					__( 'Payment method for %1$s changed to %2$s.', 'newspack-plugin' ),
					subscription.plan,
					label( selected )
				),
				mutate: s => ( {
					...s,
					subscriptions: s.subscriptions.map( sub => ( sub.id === subscription.id ? { ...sub, paymentMethodId: selected.id } : sub ) ),
				} ),
			} );
		}, 700 );
	};

	if ( usable.length === 0 ) {
		return (
			<Modal title={ __( 'Change payment method', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
				<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
					<Notice status="warning" isDismissible={ false }>
						{ __( 'There are no usable cards on file. Add a payment method first.', 'newspack-plugin' ) }
					</Notice>
					<HStack spacing={ 2 } justify="flex-end">
						<Button variant="tertiary" size="compact" onClick={ onClose }>
							{ __( 'Close', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Modal>
		);
	}

	const unchanged = ! selected || selected.id === currentId;

	return (
		<Modal title={ __( 'Change payment method', 'newspack-plugin' ) } onRequestClose={ onClose } size="small">
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
				<p>
					<strong>{ sprintf( __( 'Subscription: %s', 'newspack-plugin' ), subscription.plan ) }</strong>
				</p>
				<SelectControl
					label={ __( 'Card to charge', 'newspack-plugin' ) }
					value={ selectedId }
					options={ usable.map( pm => ( {
						label: pm.isDefault ? sprintf( __( '%s (default)', 'newspack-plugin' ), label( pm ) ) : label( pm ),
						value: pm.id,
					} ) ) }
					onChange={ setSelectedId }
				/>
				<p>
					{ unchanged
						? __( 'This is the card the subscription already uses.', 'newspack-plugin' )
						: sprintf( __( 'Future renewals will be charged to %s.', 'newspack-plugin' ), label( selected ) ) }
				</p>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ state === 'loading' } onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						size="compact"
						isBusy={ state === 'loading' }
						disabled={ state === 'loading' || unchanged }
						onClick={ submit }
					>
						{ __( 'Confirm', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
