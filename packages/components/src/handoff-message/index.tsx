/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { HANDOFF_KEY } from '../consts';
import { Notice } from '../';

type HandoffPayload = {
	message?: string;
	url?: string;
} | null;

/**
 * Handoff Message Component.
 */
export default function HandoffMessage() {
	const [ handoffMessage, setHandoffMessage ] = useState< string | false >( false );
	useEffect( () => {
		// Slight delay to allow for localStorage to be updated by RAS component.
		setTimeout( () => {
			// A missing key stringifies to 'null', which parses back to null.
			const handoff: HandoffPayload = JSON.parse( String( localStorage.getItem( HANDOFF_KEY ) ) );
			if ( handoff?.message ) {
				setHandoffMessage( handoff.message );
			} else {
				setHandoffMessage( false );
			}

			// Clean up the notification if navigating away from the relevant page.
			if ( handoff?.url && -1 === window.location.href.indexOf( handoff.url ) ) {
				window.localStorage.removeItem( HANDOFF_KEY );
				setHandoffMessage( false );
			}
		}, 100 );
	}, [] );
	if ( ! handoffMessage ) {
		return null;
	}
	// isDismissible is not a typed Notice prop; forwarded via spread for prop-parity.
	return <Notice isHandoff { ...{ isDismissible: false } } rawHTML noticeText={ handoffMessage } />;
}
