import { __, _x, sprintf } from '@wordpress/i18n';
import type { DonationFrequencySlug } from './types';

const hexToRGB = ( hex: string ): number[] => {
	const parts = hex
		.replace( /^#?([a-f\d])([a-f\d])([a-f\d])$/i, ( m, r, g, b ) => '#' + r + r + g + g + b + b )
		.substring( 1 )
		.match( /.{2}/g );
	if ( parts === null ) {
		return [ 0, 0, 0 ];
	}
	return parts.map( x => parseInt( x, 16 ) );
};

// APCA soft-clamps near-black luminance and treats unparseable input as white
// (luminance 1) so callers fall back to black text.
const apcaLuminance = ( color: string ): number => {
	const [ r, g, b ] = hexToRGB( color );
	if ( Number.isNaN( r ) || Number.isNaN( g ) || Number.isNaN( b ) ) {
		return 1;
	}

	let y = 0.2126729 * Math.pow( r / 255, 2.4 ) + 0.7151522 * Math.pow( g / 255, 2.4 ) + 0.072175 * Math.pow( b / 255, 2.4 );

	if ( y <= 0.022 ) {
		y += Math.pow( 0.022 - y, 1.414 );
	}

	return y;
};

// APCA lightness contrast (Lc): positive is dark text on a lighter background,
// negative is light text on a darker background. Both luminances are clamped.
const apcaContrast = ( backgroundY: number, textY: number ): number => {
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
 * Pick either black or white text, whichever reads better on the given background.
 *
 * Scores pure black and pure white as text against the background and returns
 * whichever produces the greater APCA lightness contrast (Lc); ties fall to
 * black. The constants are the SA98G set from apca-w3 0.1.9.
 *
 * Keep in sync with Newspack_Blocks::get_color_for_contrast() in
 * includes/class-newspack-blocks.php.
 */
export const getColorForContrast = ( color?: string ): string => {
	const blackColor = '#000000';
	const whiteColor = '#ffffff';
	if ( color === undefined ) {
		return blackColor;
	}

	const backgroundY = apcaLuminance( color );
	const blackLc = apcaContrast( backgroundY, apcaLuminance( blackColor ) );
	const whiteLc = apcaContrast( backgroundY, apcaLuminance( whiteColor ) );

	return Math.abs( whiteLc ) > Math.abs( blackLc ) ? whiteColor : blackColor;
};

export const getMigratedAmount = (
	frequency: DonationFrequencySlug,
	amounts: [ number, number, number ],
	untieredAmount: number
): [ number, number, number, number ] => {
	const multiplier = frequency === 'month' ? 1 : 12;
	return [ amounts[ 0 ] * multiplier, amounts[ 1 ] * multiplier, amounts[ 2 ] * multiplier, untieredAmount * multiplier ];
};

export const getFrequencyLabel = ( frequencySlug: DonationFrequencySlug, hideOnceLabel = false ) => {
	// eslint-disable-next-line no-nested-ternary
	return frequencySlug === 'once'
		? hideOnceLabel
			? ''
			: __( 'once', 'newspack-blocks' )
		: ' ' +
				sprintf(
					// Translators: %s is the frequency (e.g. per month, per year).
					_x( 'per %s', 'per `Frequency`', 'newspack-blocks' ),
					frequencySlug
				);
};

export const getFormattedAmount = ( amount: number, withCurrency = false ) => {
	type NumberFormatOptions = {
		minimumFractionDigits: number;
		style?: string;
		currency?: string;
	};
	const options = < NumberFormatOptions >{
		minimumFractionDigits: 0 === amount % 1 ? 0 : 2,
	};
	if ( withCurrency ) {
		options.style = 'currency';
		options.currency = window.newspack_blocks_data?.currency || 'USD';
	}
	const formatter = new Intl.NumberFormat( navigator?.language || 'en-US', options as object );

	return formatter.format( amount );
};

export const getFrequencyLabelWithAmount = ( amount: number, frequencySlug: DonationFrequencySlug, hideOnceLabel = false ) => {
	const template = window.newspack_blocks_data?.tier_amounts_template;

	if ( ! template ) {
		try {
			const frequencyString =
				frequencySlug === 'once'
					? frequencySlug
					: sprintf(
							// Translators: %s is the %s is the frequency.
							__( 'per %s', 'newspack-blocks' ),
							frequencySlug
					  );

			const formattedPrice =
				'<span class="price-amount">' +
				getFormattedAmount( amount, true ) +
				'</span> <span class="tier-frequency">' +
				frequencyString +
				'</span>';

			return formattedPrice.replace( '.00', '' );
		} catch ( e ) {
			return '<span class="price-amount">' + amount + '</span>';
		}
	}

	const frequency = getFrequencyLabel( frequencySlug, hideOnceLabel );

	return template.replace( 'AMOUNT_PLACEHOLDER', getFormattedAmount( amount ) ).replace( 'FREQUENCY_PLACEHOLDER', frequency );
};
