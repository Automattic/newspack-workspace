/**
 * Determine if black or white should be used based on a contrast ratio.
 *
 * @param hexcolor Hex code for determining contrast
 * @return black or white string
 */
export function getContrast( hexcolor: string ) {
	if ( hexcolor.charAt( 0 ) === '#' ) {
		hexcolor = hexcolor.slice( 1 );
	}

	// Normalize to 6 character hex code if needed
	if ( hexcolor.length === 3 ) {
		hexcolor = hexcolor
			.split( '' )
			.map( function ( hex ) {
				return hex + hex;
			} )
			.join( '' );
	}

	// Convert to RGB value
	const r = parseInt( hexcolor.substring( 0, 2 ), 16 );
	const g = parseInt( hexcolor.substring( 2, 4 ), 16 );
	const b = parseInt( hexcolor.substring( 4 ), 16 );

	// Get YIQ ratio
	const yiq = ( r * 299 + g * 587 + b * 114 ) / 1000;

	// Check contrast
	return yiq >= 128 ? 'black' : 'white';
}
