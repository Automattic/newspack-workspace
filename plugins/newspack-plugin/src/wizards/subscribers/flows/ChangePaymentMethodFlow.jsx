/**
 * Flow — change which saved card a subscription charges.
 *
 * A pure re-point between cards already on file: the picker offers the
 * subscriber's non-expired cards on the subscription's own gateway, and
 * confirming asks the server to swap the token. No card data is ever entered
 * here — an admin keying a reader's card number would put this screen in PCI
 * scope, so adding a card stays a reader-side (checkout / My Account) act.
 */

/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal, Notice } from '../../../../packages/components/src';
import { cardLabel } from '../components/PaymentMethodsList';
import { usableCardsFor } from '../data/use-payments';

/**
 * @param {Object}   props                Component props.
 * @param {Object}   props.subscription   The profile subscription entry.
 * @param {Array}    props.paymentMethods The subscriber's saved payment methods.
 * @param {Object}   props.actions        The payment write calls (usePaymentActions).
 * @param {Function} props.onClose        Close without acting.
 * @param {Function} props.onDone         Called with a success message after the swap.
 */
export default function ChangePaymentMethodFlow( { subscription, paymentMethods, actions, onClose, onDone } ) {
	const cards = usableCardsFor( subscription, paymentMethods );
	const currentId = subscription.paymentTokenId;
	const [ selectedId, setSelectedId ] = useState( () => {
		const firstOther = cards.find( card => card.id !== currentId );
		return firstOther ? firstOther.id : currentId || 0;
	} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const selected = cards.find( card => card.id === Number( selectedId ) );
	const unchanged = ! selected || selected.id === currentId;

	const submit = async () => {
		setBusy( true );
		setError( '' );
		try {
			await actions.changePaymentMethod( subscription.id, selected.id );
			onDone(
				sprintf(
					// translators: 1: subscription plan name, 2: card label (e.g. "Visa ending in 4242").
					__( 'Payment method for %1$s changed to %2$s.', 'newspack-plugin' ),
					subscription.plan,
					cardLabel( selected )
				)
			);
		} catch ( e ) {
			// The server knows why it said no; its message travels verbatim.
			setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
			setBusy( false );
		}
	};

	return (
		<Modal title={ __( 'Change payment method', 'newspack-plugin' ) } onRequestClose={ busy ? () => {} : onClose } size="small">
			<VStack spacing={ 4 }>
				{ /* role=alert: a refused swap must be announced, not appear silently while focus sits on the re-enabled Confirm. */ }
				{ error && (
					<div role="alert">
						<Notice isError noticeText={ error } />
					</div>
				) }
				<p className="newspack-subscribers__modal-text">
					<strong>
						{
							// translators: %s is the subscription plan name.
							sprintf( __( 'Subscription: %s', 'newspack-plugin' ), subscription.plan )
						}
					</strong>
				</p>
				<SelectControl
					label={ __( 'Card to charge', 'newspack-plugin' ) }
					value={ String( selectedId ) }
					options={ cards.map( card => ( {
						label: card.isDefault
							? // translators: %s is a card label (e.g. "Visa ending in 4242").
							  sprintf( __( '%s (default)', 'newspack-plugin' ), cardLabel( card ) )
							: cardLabel( card ),
						value: String( card.id ),
					} ) ) }
					onChange={ value => setSelectedId( Number( value ) ) }
					__nextHasNoMarginBottom
				/>
				<p className="newspack-subscribers__modal-text">
					{ unchanged
						? __( 'This is the card the subscription already uses.', 'newspack-plugin' )
						: // translators: %s is a card label (e.g. "Visa ending in 4242").
						  sprintf( __( 'Future renewals will be charged to %s.', 'newspack-plugin' ), cardLabel( selected ) ) }
				</p>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || unchanged } onClick={ submit }>
						{ __( 'Confirm', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
