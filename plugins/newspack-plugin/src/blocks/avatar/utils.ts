/**
 * Generate a --overlap-mask CSS custom property for the overlapped avatar style.
 *
 * Builds an SVG mask with a <rect rx="..."> cutout that adapts to the
 * actual border-radius, producing a clean notch where the next avatar overlaps.
 *
 * @param {Object} attrs Block attributes.
 * @return {Object} Style object with --overlap-mask, or empty object if not applicable.
 */
export const getOverlapMaskStyle = attrs => {
	const className = attrs.className || '';
	if ( ! className.includes( 'is-style-overlapped' ) ) {
		return {};
	}

	let radius = attrs?.style?.border?.radius ?? '100%';

	// Per-corner object: use the value if all corners are the same.
	if ( typeof radius === 'object' ) {
		const values = Object.values( radius );
		if ( new Set( values ).size === 1 ) {
			radius = values[ 0 ];
		} else {
			return {};
		}
	}

	if ( typeof radius !== 'string' ) {
		return {};
	}

	const imageSize = attrs.size || 48;
	const cutoutScale = 1.1; // The cutout rect is slightly larger than the avatar for a visible gap.
	const cutoutXFraction = 0.75; // Horizontal position where the cutout starts, as a fraction of avatar size.
	const svgSize = imageSize * cutoutScale;
	const offset = ( svgSize - imageSize ) / 2;
	let radiusPx;

	if ( radius.endsWith( '%' ) ) {
		radiusPx = ( parseFloat( radius ) / 100 ) * imageSize;
	} else if ( radius.endsWith( 'rem' ) || radius.endsWith( 'em' ) ) {
		// Approximate em/rem using the 16px browser default base font size.
		radiusPx = parseFloat( radius ) * 16;
	} else {
		// px or plain number.
		radiusPx = parseFloat( radius );
	}

	if ( Number.isNaN( radiusPx ) ) {
		return {};
	}

	// Offset the rounded rectangle equally on all sides for a border-like shape.
	const cutoutRx = Math.round( Math.max( 0, Math.min( svgSize / 2, radiusPx + offset ) ) * 100 ) / 100;

	const svg =
		`<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 ${ imageSize } ${ imageSize }'>` +
		`<defs><mask id='m'><rect width='${ imageSize }' height='${ imageSize }' fill='white'/>` +
		`<rect x='${
			imageSize * cutoutXFraction - offset
		}' y='${ -offset }' width='${ svgSize }' height='${ svgSize }' rx='${ cutoutRx }' ry='${ cutoutRx }' fill='black'/>` +
		`</mask></defs>` +
		`<rect width='${ imageSize }' height='${ imageSize }' fill='white' mask='url(#m)'/>` +
		`</svg>`;

	return {
		'--overlap-mask': `url("data:image/svg+xml,${ encodeURIComponent( svg ) }")`,
	};
};
