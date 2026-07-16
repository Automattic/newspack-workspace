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
	// Some browser/OS combinations render `datetime-local` as date-only (e.g. Firefox
	// in certain locales), emitting `YYYY-MM-DD` with no time. `new Date( 'YYYY-MM-DD' )`
	// parses as UTC midnight — a day-boundary shift in most timezones — so normalize a
	// bare date to local midnight before parsing, rather than dropping it as unparseable.
	const normalized = /^\d{4}-\d{2}-\d{2}$/.test( value ) ? `${ value }T00:00` : value;
	const ms = new Date( normalized ).getTime();
	return Number.isNaN( ms ) ? null : Math.floor( ms / 1000 );
}
