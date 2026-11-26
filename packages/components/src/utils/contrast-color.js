const normalizeHex = color => {
	let hex = String( color ).trim().replace( /^#/, '' ).toLowerCase();
	if ( hex.length === 3 ) {
		hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
	} else if ( hex.length === 8 ) {
		hex = hex.substring( 0, 6 );
	}
	return /^[a-f\d]{6}$/.test( hex ) ? hex : null;
};

const toChannels = hex => hex.match( /.{2}/g ).map( x => parseInt( x, 16 ) );

const apcaLuminance = channels => {
	const [ r, g, b ] = channels;
	let y = 0.2126729 * Math.pow( r / 255, 2.4 ) + 0.7151522 * Math.pow( g / 255, 2.4 ) + 0.072175 * Math.pow( b / 255, 2.4 );
	if ( y <= 0.022 ) {
		y += Math.pow( 0.022 - y, 1.414 );
	}
	return y;
};

const apcaContrast = ( backgroundY, textY ) => {
	if ( Math.abs( backgroundY - textY ) < 0.0005 ) {
		return 0;
	}
	if ( backgroundY > textY ) {
		const sapc = ( Math.pow( backgroundY, 0.56 ) - Math.pow( textY, 0.57 ) ) * 1.14;
		return sapc < 0.1 ? 0 : ( sapc - 0.027 ) * 100;
	}
	const sapc = ( Math.pow( backgroundY, 0.65 ) - Math.pow( textY, 0.62 ) ) * 1.14;
	return sapc > -0.1 ? 0 : ( sapc + 0.027 ) * 100;
};

/**
 * Pick black or white text for a background: whichever reads better by APCA (ties to black).
 *
 * Keep in sync with: Newspack_Blocks::get_color_for_contrast() and getColorForContrast() in donate/utils.ts
 * (newspack-blocks), newspack_get_color_contrast() (newspack-theme), \Newspack\newspack_get_color_contrast()
 * (newspack-plugin), Newspack_Popups_Model::contrast_text_color() (newspack-popups)
 * and Newspack_Listings\Curated_List_Block\contrast_text_color() (newspack-listings). The block theme's
 * Newspack_Block_Theme\Contrast::get_text_color() makes the same pick (it leaves the property unset for unparseable input).
 *
 * @param {string} color Hex background (#RGB, #RRGGBB or #RRGGBBAA, with or without #).
 * @return {'black'|'white'} The text color. Unparseable input is treated as white, so black.
 */
export const getContrastColor = color => {
	const hex = color === undefined || color === null ? null : normalizeHex( color );
	const channels = hex ? toChannels( hex ) : [ 255, 255, 255 ];

	const backgroundY = apcaLuminance( channels );
	const blackLc = apcaContrast( backgroundY, apcaLuminance( [ 0, 0, 0 ] ) );
	const whiteLc = apcaContrast( backgroundY, apcaLuminance( [ 255, 255, 255 ] ) );

	return Math.abs( whiteLc ) > Math.abs( blackLc ) ? 'white' : 'black';
};

/**
 * Same pick for an RGB triple, for callers that read computed styles.
 *
 * @param {number[]} channels [ r, g, b ], 0-255.
 * @return {'black'|'white'} The text color.
 */
export const getContrastColorFromRGB = ( [ r, g, b ] ) => {
	const toHex = n =>
		Math.max( 0, Math.min( 255, Math.round( n ) ) )
			.toString( 16 )
			.padStart( 2, '0' );
	return getContrastColor( toHex( r ) + toHex( g ) + toHex( b ) );
};
