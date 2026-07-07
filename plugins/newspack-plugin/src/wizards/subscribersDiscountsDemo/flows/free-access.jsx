/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Shared building blocks for the "how to add or reactivate a subscription" flows.
 *
 * AddSubscriptionFlow and GuidedFixFlow both let an admin charge, send a payment
 * link, or grant free access, and the free path offers the same indefinitely vs.
 * number-of-cycles choice. Keeping that surface here means the wording and
 * behaviour cannot drift between the two flows. The free radio label stays
 * contextual (grant vs reactivate), so each flow passes its own option.
 */

import { createInterpolateElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { fmtCurrency } from '../format';

// The charge and link modes are shared verbatim; the free option differs by
// context (grant vs reactivate), so each flow passes its own.
export function paymentModeOptions( freeOption ) {
	return [
		{ label: __( 'Charge the customer now', 'newspack-plugin' ), value: 'charge' },
		{ label: __( 'Send a payment link', 'newspack-plugin' ), value: 'link' },
		freeOption,
	];
}

// State for the free-access duration choice plus the resolved value the host
// records: null = indefinite comp, a number = free cycles before billing starts.
export function useFreeAccess() {
	const [ freeDuration, setFreeDuration ] = useState( 'cycles' );
	const [ freeCycles, setFreeCycles ] = useState( '3' );
	const freeForever = freeDuration === 'forever';
	const cyclesNum = Math.max( 1, Number( freeCycles ) || 1 );
	return {
		freeDuration,
		setFreeDuration,
		freeCycles,
		setFreeCycles,
		freeForever,
		cyclesNum,
		freeCyclesRemaining: freeForever ? null : cyclesNum,
	};
}

// A card (MM/YY) is usable through the end of its expiry month.
export function isCardExpired( expiry ) {
	if ( ! expiry ) {
		return true;
	}
	const [ mm, yy ] = String( expiry )
		.split( '/' )
		.map( n => parseInt( n, 10 ) );
	if ( ! mm || ! yy ) {
		return false;
	}
	const now = new Date();
	const year = 2000 + yy;
	return year < now.getFullYear() || ( year === now.getFullYear() && mm < now.getMonth() + 1 );
}

// Whether the subscriber has at least one non-expired card we could charge.
export function hasUsableCard( subscriber ) {
	return ( ( subscriber && subscriber.paymentMethods ) || [] ).some( pm => ! isCardExpired( pm.expiry ) );
}

// The shared "how would you like to..." chooser: the charge/link/free radio with
// "Charge the customer now" hidden when no card is chargeable, plus a one-line
// explanation of the selected option. Both the add and reactivate flows render
// this so the gating and help text stay identical.
export function ModeRadio( { title, freeOption, canCharge, details, mode, setMode } ) {
	const options = paymentModeOptions( freeOption ).filter( o => canCharge || o.value !== 'charge' );
	return <RadioControl label={ title } selected={ mode } options={ options } onChange={ setMode } help={ details[ mode ] } />;
}

// The "you'll be charged today" summary shown before confirming a charge, shared
// by the add and reactivate flows.
export function ChargeNote( { amount, cadence } ) {
	return (
		<p>
			{ sprintf(
				__( 'Billing will start today. First charge: %1$s. Next renewal in %2$s.', 'newspack-plugin' ),
				fmtCurrency( amount ),
				cadence === 'Monthly' ? '30 days' : '1 year'
			) }
		</p>
	);
}

// The "a link will be emailed" summary shown before sending a payment link.
export function PaymentLinkNote( { email } ) {
	return <p>{ sprintf( __( 'A payment link will be emailed to %s. The subscription activates once they pay.', 'newspack-plugin' ), email ) }</p>;
}

// The free-access duration fields: the Indefinitely / number-of-cycles segmented
// control, the cycle count when finite, and a summary line. The indefinite copy is
// the only contextual text, so callers pass it prebuilt as `foreverCopy`.
export function FreeAccessFields( { free, planName, amount, foreverCopy } ) {
	const { freeDuration, setFreeDuration, freeCycles, setFreeCycles, freeForever, cyclesNum } = free;
	return (
		<>
			<ToggleGroupControl
				label={ __( 'Free access duration', 'newspack-plugin' ) }
				value={ freeDuration }
				onChange={ setFreeDuration }
				isBlock
				__nextHasNoMarginBottom
			>
				<ToggleGroupControlOption value="cycles" label={ __( 'For a number of cycles', 'newspack-plugin' ) } />
				<ToggleGroupControlOption value="forever" label={ __( 'Indefinitely', 'newspack-plugin' ) } />
			</ToggleGroupControl>
			{ ! freeForever && (
				<TextControl
					type="number"
					label={ __( 'Free cycles before billing starts', 'newspack-plugin' ) }
					value={ freeCycles }
					onChange={ setFreeCycles }
					help={ sprintf( __( 'Then converts to %s at the catalog price.', 'newspack-plugin' ), fmtCurrency( amount ) ) }
				/>
			) }
			<p>
				{ freeForever
					? foreverCopy
					: createInterpolateElement(
							sprintf(
								// translators: 1: cycle count, 2: plan name (bold), 3: price.
								__( 'Free for %1$s cycles, then <name>%2$s</name> bills at %3$s.', 'newspack-plugin' ),
								cyclesNum,
								planName,
								fmtCurrency( amount )
							),
							{ name: <strong /> }
					  ) }
			</p>
		</>
	);
}
