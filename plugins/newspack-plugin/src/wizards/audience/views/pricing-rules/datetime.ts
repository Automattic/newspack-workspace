/**
 * The rules REST contract uses UTC unix-second timestamps for the active window
 * and for datetime eligibility conditions. These convert to/from the browser-local
 * value a `datetime-local` input expects.
 */

export function tsToLocalInput( ts: number | null ): string {
	if ( ! ts ) {
		return '';
	}
	const d = new Date( ts * 1000 );
	const pad = ( n: number ) => String( n ).padStart( 2, '0' );
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
}

export function localInputToTs( value: string ): number | null {
	if ( ! value ) {
		return null;
	}
	const ms = new Date( value ).getTime();
	return Number.isNaN( ms ) ? null : Math.floor( ms / 1000 );
}
