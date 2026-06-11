/**
 * useCountdown — live mm:ss string counting down to a deadline.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

const format = ( ms: number ): string => {
	const totalSec = Math.max( 0, Math.floor( ms / 1000 ) );
	const m = Math.floor( totalSec / 60 );
	const s = totalSec % 60;
	return `${ String( m ).padStart( 2, '0' ) }:${ String( s ).padStart( 2, '0' ) }`;
};

const useCountdown = ( until: string | null ): string | null => {
	const [ remaining, setRemaining ] = useState< string | null >( () => {
		if ( ! until ) {
			return null;
		}
		const ms = new Date( until ).getTime() - Date.now();
		return ms > 0 ? format( ms ) : null;
	} );

	useEffect( () => {
		if ( ! until ) {
			setRemaining( null );
			return;
		}
		const tick = () => {
			const ms = new Date( until ).getTime() - Date.now();
			if ( ms <= 0 ) {
				setRemaining( null );
				return;
			}
			setRemaining( format( ms ) );
		};
		tick();
		const id = window.setInterval( tick, 1000 );
		return () => window.clearInterval( id );
	}, [ until ] );

	return remaining;
};

export default useCountdown;
