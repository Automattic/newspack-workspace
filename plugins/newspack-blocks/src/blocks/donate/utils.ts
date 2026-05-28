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

export const getColorForContrast = ( color?: string ): string => {
	const blackColor = '#000000';
	const whiteColor = '#ffffff';
	if ( color === undefined ) {
		return blackColor;
	}

	const backgroundColorRGB = hexToRGB( color );
	const blackRGB = hexToRGB( blackColor );

	const l1 =
		0.2126 * Math.pow( backgroundColorRGB[ 0 ] / 255, 2.2 ) +
		0.7152 * Math.pow( backgroundColorRGB[ 1 ] / 255, 2.2 ) +
		0.0722 * Math.pow( backgroundColorRGB[ 2 ] / 255, 2.2 );
	const l2 =
		0.2126 * Math.pow( blackRGB[ 0 ] / 255, 2.2 ) + 0.7152 * Math.pow( blackRGB[ 1 ] / 255, 2.2 ) + 0.0722 * Math.pow( blackRGB[ 2 ] / 255, 2.2 );

	const contrastRatio = l1 > l2 ? ( l1 + 0.05 ) / ( l2 + 0.05 ) : ( l2 + 0.05 ) / ( l1 + 0.05 );

	return contrastRatio > 5 ? blackColor : whiteColor;
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
