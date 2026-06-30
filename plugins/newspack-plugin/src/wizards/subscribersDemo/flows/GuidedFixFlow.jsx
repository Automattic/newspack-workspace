/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow E — Bring a subscription back to active.
 *
 * Used for both reactivating an on-hold subscription/group and resubscribing a
 * cancelled group (a group is a subscription, so the choice — charge the card,
 * send a payment link, or grant free access — is identical; only the verb in the
 * copy differs, driven by `verb`). Mirrors the two-step Add subscription flow:
 * step one picks the method, step two confirms its details. The charge, link and
 * free building blocks are shared with the Add subscription flow so the two stay
 * in sync.
 */

import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack,
	CheckboxControl,
} from '@wordpress/components';
import { Modal } from '../../../../packages/components/src';
import { ChargeNote, FreeAccessFields, ModeRadio, PaymentLinkNote, useFreeAccess } from './free-access';
import { StepButtons, useTwoStep } from './steps';

// Verb-specific copy. A cancelled group "resubscribes"; an on-hold target
// "reactivates" — the mechanics are identical, only the wording changes.
const COPY = {
	reactivate: {
		modeTitle: __( 'How would you like to reactivate?', 'newspack-plugin' ),
		freeLabel: __( 'Reactivate for free', 'newspack-plugin' ),
		detail: {
			charge: __( 'Charge the card on file now. Reactivates immediately once the payment succeeds.', 'newspack-plugin' ),
			link: __( 'Email the subscriber a link to update their own card. Stays on hold until they pay.', 'newspack-plugin' ),
			free: __( 'Reactivate now without taking a payment, and choose how long it stays free.', 'newspack-plugin' ),
		},
		forever: __( 'Reactivates <name>%s</name> with ongoing free access, no billing.', 'newspack-plugin' ),
	},
	resubscribe: {
		modeTitle: __( 'How would you like to resubscribe?', 'newspack-plugin' ),
		freeLabel: __( 'Resubscribe for free', 'newspack-plugin' ),
		detail: {
			charge: __( 'Charge the card on file now. Resubscribes immediately once the payment succeeds.', 'newspack-plugin' ),
			link: __( 'Email the subscriber a link to pay. Stays cancelled until they do.', 'newspack-plugin' ),
			free: __( 'Resubscribe now without taking a payment, and choose how long it stays free.', 'newspack-plugin' ),
		},
		forever: __( 'Resubscribes <name>%s</name> with ongoing free access, no billing.', 'newspack-plugin' ),
	},
};

export default function GuidedFixFlow( {
	target,
	email,
	modalTitle,
	canCharge,
	verb = 'reactivate',
	onClose,
	onReactivateCharge,
	onSendPaymentLink,
	onReactivateFree,
} ) {
	const copy = COPY[ verb ] || COPY.reactivate;
	const step = useTwoStep();
	const [ mode, setMode ] = useState( canCharge ? 'charge' : 'link' );
	const free = useFreeAccess();
	const [ notify, setNotify ] = useState( true );

	// Step two runs the selected mode.
	const onConfirm = () => {
		if ( mode === 'charge' ) {
			onReactivateCharge( notify );
		} else if ( mode === 'link' ) {
			onSendPaymentLink();
		} else {
			onReactivateFree( free.freeCyclesRemaining, notify );
		}
	};

	// copy.forever is a checked "<name>%s</name>" format string from COPY above.
	// eslint-disable-next-line @wordpress/valid-sprintf
	const foreverCopy = createInterpolateElement( sprintf( copy.forever, target.plan ), { name: <strong /> } );

	let body;
	if ( step.isMethod ) {
		body = (
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
				<p>
					<strong>{ target.plan }</strong>
				</p>
				<ModeRadio
					title={ copy.modeTitle }
					freeOption={ { label: copy.freeLabel, value: 'free' } }
					canCharge={ canCharge }
					details={ copy.detail }
					mode={ mode }
					setMode={ setMode }
				/>
				<StepButtons
					leftLabel={ __( 'Cancel', 'newspack-plugin' ) }
					onLeft={ onClose }
					rightLabel={ __( 'Continue', 'newspack-plugin' ) }
					onRight={ step.toDetails }
				/>
			</VStack>
		);
	} else {
		body = (
			<VStack spacing={ 4 } className="newspack-subscribers-demo__flow">
				<p>
					<strong>{ target.plan }</strong>
				</p>
				{ mode === 'charge' && <ChargeNote amount={ target.amount } cadence={ target.cadence } /> }
				{ mode === 'link' && <PaymentLinkNote email={ email } /> }
				{ mode === 'free' && (
					<FreeAccessFields free={ free } planName={ target.plan } amount={ target.amount } foreverCopy={ foreverCopy } />
				) }
				{ mode !== 'link' && (
					<CheckboxControl
						label={ __( 'Send a confirmation email', 'newspack-plugin' ) }
						help={ __( 'Email the subscriber to confirm their subscription is active again.', 'newspack-plugin' ) }
						checked={ notify }
						onChange={ setNotify }
						__nextHasNoMarginBottom
					/>
				) }
				<StepButtons
					leftLabel={ __( 'Back', 'newspack-plugin' ) }
					onLeft={ step.toMethod }
					rightLabel={ __( 'Confirm', 'newspack-plugin' ) }
					onRight={ onConfirm }
				/>
			</VStack>
		);
	}

	return (
		<Modal title={ modalTitle } onRequestClose={ onClose } size="small">
			{ body }
		</Modal>
	);
}
