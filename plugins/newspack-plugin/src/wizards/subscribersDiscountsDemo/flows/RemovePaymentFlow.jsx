/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Remove a saved payment method.
 *
 * Confirmation modal; deleting a card is destructive and can't be undone.
 */

import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ConfirmFlow from './ConfirmFlow';

export default function RemovePaymentFlow( { paymentMethod, onClose, onComplete } ) {
	const label = sprintf( __( '%1$s ending in %2$s', 'newspack-plugin' ), paymentMethod.type, paymentMethod.last4 );

	const remove = () => {
		onComplete( {
			message: sprintf( __( '%s removed.', 'newspack-plugin' ), label ),
			mutate: s => ( {
				...s,
				paymentMethods: ( s.paymentMethods || [] ).filter( m => m.id !== paymentMethod.id ),
			} ),
		} );
	};

	return (
		<ConfirmFlow
			title={ __( 'Remove payment method', 'newspack-plugin' ) }
			confirmLabel={ __( 'Remove', 'newspack-plugin' ) }
			isDestructive
			onCancel={ onClose }
			onConfirm={ remove }
		>
			{ createInterpolateElement(
				sprintf( __( 'Remove <strong>%s</strong>? It can no longer be used for future payments.', 'newspack-plugin' ), label ),
				{ strong: <strong /> }
			) }
		</ConfirmFlow>
	);
}
