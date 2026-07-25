/**
 * Style helpers for the Contextual Prompts Style section.
 */

const parseHex = value => {
	if ( 'string' !== typeof value ) {
		return null;
	}
	let hex = value.trim().replace( /^#/, '' );
	if ( 3 === hex.length ) {
		hex = hex
			.split( '' )
			.map( c => c + c )
			.join( '' );
	}
	if ( ! /^[0-9a-f]{6}$/i.test( hex ) ) {
		return null;
	}
	return [ parseInt( hex.slice( 0, 2 ), 16 ), parseInt( hex.slice( 2, 4 ), 16 ), parseInt( hex.slice( 4, 6 ), 16 ) ];
};

const luminance = channels => {
	const [ r, g, b ] = channels.map( channel => {
		const srgb = channel / 255;
		return srgb <= 0.03928 ? srgb / 12.92 : Math.pow( ( srgb + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

// WCAG relative luminance, which the contrast ratio rests on: null when the
// value is not a color this module can read. Exported for the tests alone, which
// use it to pin where it diverges from the perceived brightness the suggestion
// below follows.
export const relativeLuminance = value => {
	const channels = parseHex( value );
	return channels ? luminance( channels ) : null;
};

// Perceived brightness, the metric core's ContrastChecker orders a pair by when
// it picks which way to move them. It disagrees with relative luminance on
// saturated hues, so the editor's suggestion follows this one.
export const perceivedBrightness = value => {
	const channels = parseHex( value );
	if ( ! channels ) {
		return null;
	}
	const [ r, g, b ] = channels;
	return ( 299 * r + 587 * g + 114 * b ) / 1000;
};

export const contrastRatio = ( a, b ) => {
	const channelsA = parseHex( a );
	const channelsB = parseHex( b );
	if ( ! channelsA || ! channelsB ) {
		return null;
	}
	const lumA = luminance( channelsA );
	const lumB = luminance( channelsB );
	const [ darker, lighter ] = lumA < lumB ? [ lumA, lumB ] : [ lumB, lumA ];
	return ( lighter + 0.05 ) / ( darker + 0.05 );
};

const PRESET_COLOR_PREFIX = 'var:preset|color|';

export const resolveColor = ( value, palette ) => {
	if ( 'string' !== typeof value || ! value ) {
		return null;
	}
	if ( value.startsWith( PRESET_COLOR_PREFIX ) ) {
		const slug = value.slice( PRESET_COLOR_PREFIX.length );
		const entry = ( palette || [] ).find( color => color.slug === slug );
		return entry ? entry.color : null;
	}
	return parseHex( value ) ? value : null;
};

export const presetRefForColor = ( hex, palette ) => {
	const entry = ( palette || [] ).find( color => color.color?.toLowerCase() === hex?.toLowerCase() );
	return entry ? PRESET_COLOR_PREFIX + entry.slug : hex;
};

const PRESET_SPACING_PREFIX = 'var:preset|spacing|';
// Step 0 of the spacing scale. Core stores it as the bare string, not a preset
// reference, since no preset defines it.
export const SPACING_NONE = '0';

// The spacing steps a slider offers: None, then the site's presets in order, as
// core's own spacing control builds them.
export const spacingSteps = ( presets, noneLabel ) => [
	{ name: noneLabel, slug: SPACING_NONE, size: 0 },
	...( presets || [] ).map( ( { name, slug, size } ) => ( { name, slug, size } ) ),
];

export const isSpacingPreset = value => SPACING_NONE === value || ( 'string' === typeof value && value.includes( PRESET_SPACING_PREFIX ) );

// A stored value as a step index, or null when it is a custom value the slider
// cannot represent.
export const spacingStepOf = ( value, steps ) => {
	if ( undefined === value || '' === value ) {
		return null;
	}
	const slug = SPACING_NONE === value ? SPACING_NONE : String( value ).replace( PRESET_SPACING_PREFIX, '' );
	const index = steps.findIndex( step => String( step.slug ) === slug );
	return -1 === index ? null : index;
};

// What a step writes back: None is the bare zero, every other step the preset
// reference, so the value tracks the preset if the theme redefines it.
export const spacingRefForStep = ( step, steps ) => ( 0 === step ? SPACING_NONE : `${ PRESET_SPACING_PREFIX }${ steps[ step ]?.slug }` );

// A resolved value (`1.5rem`) as the preset reference it came from, so a default
// the wizard receives already resolved still lands on its own slider step.
export const spacingPresetOf = ( value, steps ) => {
	if ( undefined === value || isSpacingPreset( value ) ) {
		return value;
	}
	const match = steps.find( step => String( step.size ).trim() === String( value ).trim() );
	return match ? spacingRefForStep( steps.indexOf( match ), steps ) : value;
};

// The concrete CSS value behind a step, for the custom input to start from.
export const spacingValueOf = ( value, steps ) => {
	const step = spacingStepOf( value, steps );
	return null === step ? value : steps[ step ].size;
};
