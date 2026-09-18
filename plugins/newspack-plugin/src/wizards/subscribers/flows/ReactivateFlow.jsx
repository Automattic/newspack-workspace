/**
 * Flow — bring an on-hold subscription back to active (NPPD-1753 on-hold
 * recovery), ported from the signed-off prototype's guided fix flow.
 *
 * Two steps: pick the method, confirm its details. Three methods:
 *
 * - Charge the customer now: process a renewal against the saved payment
 *   method. Offered only when the server says a charge could be attempted
 *   (`canCharge`), so a manual subscription never shows a dead option.
 * - Send a payment link: email the customer the unpaid renewal order's pay
 *   link. The subscription stays on hold until they pay.
 * - Reactivate for free: no payment now; billing resumes on the regular
 *   schedule. (The prototype's free-for-N-cycles comp is a later, separate
 *   pricing concern — this flow resumes billing unchanged.)
 *
 * The server owns the outcome: a charge is confirmed from the subscription's
 * resulting status, and its refusal messages are surfaced verbatim.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { RadioControl, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Modal, Notice } from '../../../../packages/components/src';
import { fmtCurrency } from '../format';
import { StepButtons, useTwoStep } from './steps';

// One-line explanation of the selected method, shown under the radio.
const MODE_DETAILS = {
	charge: __( 'Charge the payment method on file now. Reactivates immediately once the payment succeeds.', 'newspack-plugin' ),
	link: __( 'Email the subscriber a link to pay. Stays on hold until they pay.', 'newspack-plugin' ),
	free: __( 'Reactivate now without taking a payment. Billing resumes on the regular schedule.', 'newspack-plugin' ),
};

export default function ReactivateFlow( { subscription, email, actions, onClose, onDone } ) {
	const canCharge = !! subscription.canCharge;
	const step = useTwoStep();
	const [ mode, setMode ] = useState( canCharge ? 'charge' : 'link' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const planName = subscription.plan || __( '(Subscription)', 'newspack-plugin' );
	const price = fmtCurrency( subscription.amount, subscription.currency );

	const modeOptions = [
		...( canCharge ? [ { label: __( 'Charge the customer now', 'newspack-plugin' ), value: 'charge' } ] : [] ),
		{ label: __( 'Send a payment link', 'newspack-plugin' ), value: 'link' },
		{ label: __( 'Reactivate for free', 'newspack-plugin' ), value: 'free' },
	];

	const onConfirm = async () => {
		setBusy( true );
		setError( '' );
		try {
			if ( 'link' === mode ) {
				const result = await actions.sendPaymentLink( subscription.id );
				// The server is honest about whether an email went out; so is the
				// UI. When it couldn't send one, the admin gets the link itself to
				// pass along instead of a false "sent" confirmation.
				if ( result?.emailSent ) {
					// translators: %s is the subscriber's email address.
					onDone( sprintf( __( 'Payment link sent to %s.', 'newspack-plugin' ), email ) );
				} else {
					setError(
						sprintf(
							// translators: %s is a checkout payment URL.
							__( 'The email could not be sent. Share this payment link with the subscriber directly: %s', 'newspack-plugin' ),
							result?.paymentUrl || ''
						)
					);
					setBusy( false );
				}
			} else {
				const result = await actions.reactivate( subscription.id, mode );
				// A charge can legitimately end with the money still in flight
				// (asynchronous capture, customer authentication). Confirmed by
				// the server as pending — not a failure, and not "reactivated".
				if ( result?.pendingConfirmation ) {
					// A promise the server can stand behind: it knows the payment was
					// submitted, not that confirmation will arrive.
					onDone( __( "Payment submitted — check the subscription's renewal order to confirm the outcome.", 'newspack-plugin' ) );
				} else {
					// translators: %s is the plan name.
					onDone( sprintf( __( '%s reactivated.', 'newspack-plugin' ), planName ) );
				}
			}
		} catch ( e ) {
			// The server's refusal explains itself (declined card, wrong state);
			// surface it rather than a generic failure.
			setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
			setBusy( false );
		}
	};

	// The step swap and an arriving error both change the dialog's content in
	// place; announce them so non-visual users hear the change.
	useEffect( () => {
		speak( step.isMethod ? __( 'Choose how to reactivate.', 'newspack-plugin' ) : __( 'Confirm the reactivation details.', 'newspack-plugin' ) );
	}, [ step.isMethod ] );
	useEffect( () => {
		if ( error ) {
			speak( error, 'assertive' );
		}
	}, [ error ] );

	// The mode-specific confirmation note for step two.
	let detail;
	if ( 'charge' === mode ) {
		// translators: %s is a formatted price.
		const chargeCopy = __(
			"The payment method on file will be charged %s now. If the payment succeeds, the subscription reactivates immediately and the renewal receipt is emailed per the store's email settings.",
			'newspack-plugin'
		);
		detail = price
			? sprintf( chargeCopy, price )
			: __(
					'The payment method on file will be charged now. If the payment succeeds, the subscription reactivates immediately.',
					'newspack-plugin'
			  );
	} else if ( 'link' === mode ) {
		// translators: %s is the subscriber's email address.
		detail = sprintf( __( 'A payment link will be emailed to %s. The subscription reactivates once they pay.', 'newspack-plugin' ), email );
	} else {
		detail = __(
			'The subscription reactivates now without taking a payment. Billing resumes on the regular schedule at the next renewal.',
			'newspack-plugin'
		);
	}

	return (
		<Modal
			title={ __( 'Reactivate subscription', 'newspack-plugin' ) }
			// While a request is in flight the modal must not close — hiding the
			// dismiss affordances is honest about that, where an inert X is not.
			isDismissible={ ! busy }
			shouldCloseOnEsc={ ! busy }
			shouldCloseOnClickOutside={ ! busy }
			onRequestClose={ busy ? () => {} : onClose }
			size="small"
		>
			<VStack spacing={ 4 }>
				{ error && (
					<div role="alert">
						<Notice isError noticeText={ error } />
					</div>
				) }
				<p>
					<strong>{ planName }</strong>
				</p>
				{ step.isMethod ? (
					<>
						<RadioControl
							label={ __( 'How would you like to reactivate?', 'newspack-plugin' ) }
							selected={ mode }
							options={ modeOptions }
							onChange={ setMode }
							help={ MODE_DETAILS[ mode ] }
						/>
						<StepButtons
							leftLabel={ __( 'Cancel', 'newspack-plugin' ) }
							onLeft={ onClose }
							rightLabel={ __( 'Continue', 'newspack-plugin' ) }
							onRight={ step.toDetails }
						/>
					</>
				) : (
					<>
						<p>{ detail }</p>
						<StepButtons
							leftLabel={ __( 'Back', 'newspack-plugin' ) }
							onLeft={ step.toMethod }
							rightLabel={ __( 'Confirm', 'newspack-plugin' ) }
							onRight={ onConfirm }
							busy={ busy }
							disabled={ busy }
						/>
					</>
				) }
			</VStack>
		</Modal>
	);
}
