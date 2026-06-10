/**
 * useProgressiveMessages (NPPD-1684).
 *
 * Advances through a list of `{ text, delay }` messages by their absolute delay
 * (ms from mount) and returns the current message text. The first message
 * (delay 0) shows immediately; each later message swaps in at its `delay`. All
 * timers are cleared on unmount or when the `messages` array identity changes,
 * so a load that completes before a later delay never fires its timer.
 *
 * Lifted from newspack-blocks' `processing_payment_messages` pattern in
 * `class-modal-checkout.php`: a quick second entry (~250ms) bridges fast loads
 * so the first message doesn't visibly flash before the content arrives.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

export interface LoadingMessage {
	text: string;
	delay: number;
}

const EMPTY: readonly LoadingMessage[] = [];

/**
 * Returns the current message text for a progressive loading sequence.
 *
 * @param messages Ordered messages with absolute `delay` (ms from mount).
 */
export const useProgressiveMessages = ( messages: readonly LoadingMessage[] = EMPTY ): string => {
	const [ index, setIndex ] = useState( 0 );

	useEffect( () => {
		setIndex( 0 );
		// One timer per message (the first shows immediately). Each fires at its
		// absolute delay and advances to that message; later delays win over
		// earlier ones, so the visible text always reflects elapsed time.
		const timers = messages.map( ( message, i ) => ( i === 0 ? null : setTimeout( () => setIndex( i ), message.delay ) ) );
		return () => {
			timers.forEach( timer => {
				if ( null !== timer ) {
					clearTimeout( timer );
				}
			} );
		};
	}, [ messages ] );

	if ( messages.length === 0 ) {
		return '';
	}
	// Guard the index against a shrunk array on a fast prop change before reset.
	return ( messages[ index ] ?? messages[ 0 ] ).text;
};
