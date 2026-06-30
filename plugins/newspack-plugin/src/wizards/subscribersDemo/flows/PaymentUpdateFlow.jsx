/* eslint-disable @wordpress/i18n-translator-comments, no-bitwise */
/**
 * Flow D — Payment method update.
 */

import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Button, Grid, Modal, TextControl } from '../../../../packages/components/src';

// Detect the card brand from its leading digits (BIN ranges), matching the keys
// in PersonProfile's CARD_ICONS. Falls back to Visa for an unrecognized prefix.
const detectCardType = digits => {
	if ( /^4/.test( digits ) ) {
		return 'Visa';
	}
	if ( /^3[47]/.test( digits ) ) {
		return 'Amex';
	}
	if ( /^(6011|65|64[4-9])/.test( digits ) ) {
		return 'Discover';
	}
	if ( /^35/.test( digits ) ) {
		return 'JCB';
	}
	if ( /^(5[1-5]|2[2-7])/.test( digits ) ) {
		return 'Mastercard';
	}
	return 'Visa';
};

export default function PaymentUpdateFlow( { onClose, onComplete, paymentMethod } ) {
	const isEdit = !! paymentMethod;
	const expiryPlaceholder = `01/${ String( new Date().getFullYear() + 2 ).slice( -2 ) }`;
	const [ number, setNumber ] = useState( isEdit ? '•••• •••• •••• ' + paymentMethod.last4 : '' );
	const [ expiry, setExpiry ] = useState( isEdit ? paymentMethod.expiry : '' );
	const [ cvc, setCvc ] = useState( '' );
	const [ state, setState ] = useState( 'form' );

	const digits = number.replace( /\D/g, '' );
	const valid = digits.length >= 12 && /^\d{2}\/\d{2}$/.test( expiry ) && cvc.length >= 3;

	const submit = () => {
		if ( ! valid ) {
			return;
		}
		setState( 'loading' );
		setTimeout( () => {
			const last4 = digits.slice( -4 );
			const type = detectCardType( digits );
			onComplete( {
				type: 'success',
				message: isEdit ? __( 'Payment method updated.', 'newspack-plugin' ) : __( 'Payment method added.', 'newspack-plugin' ),
				mutate: s => {
					if ( isEdit ) {
						return {
							...s,
							paymentMethods: s.paymentMethods.map( m => ( m.id === paymentMethod.id ? { ...m, type, last4, expiry } : m ) ),
						};
					}
					const next = { id: 'pm_' + Date.now(), type, last4, expiry, isDefault: s.paymentMethods.length === 0 };
					return { ...s, paymentMethods: [ ...s.paymentMethods, next ] };
				},
			} );
		}, 700 );
	};

	return (
		<Modal
			title={ isEdit ? __( 'Update payment method', 'newspack-plugin' ) : __( 'Add payment method', 'newspack-plugin' ) }
			onRequestClose={ onClose }
			size="small"
		>
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
				{ ! valid && number && expiry && cvc && (
					<Notice status="warning" isDismissible={ false }>
						{ __( 'Check the card details.', 'newspack-plugin' ) }
					</Notice>
				) }
				<TextControl
					label={ __( 'Card number', 'newspack-plugin' ) }
					value={ number }
					onChange={ setNumber }
					placeholder="4242 4242 4242 4242"
					withMargin={ false }
				/>
				<Grid columns={ 2 } gutter={ 16 } noMargin>
					<TextControl
						label={ __( 'Expiry (MM/YY)', 'newspack-plugin' ) }
						value={ expiry }
						onChange={ setExpiry }
						placeholder={ expiryPlaceholder }
					/>
					<TextControl label={ __( 'CVC', 'newspack-plugin' ) } value={ cvc } onChange={ setCvc } placeholder="123" />
				</Grid>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ state === 'loading' } onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						size="compact"
						isBusy={ state === 'loading' }
						onClick={ submit }
						disabled={ ! valid || state === 'loading' }
					>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
