import core from './wp-core-theme.json';

/**
 * The theme.json preset paths, as @wordpress/global-styles-engine reads them.
 */
const PRESET_PATHS = [
	[ 'border', 'radiusSizes' ],
	[ 'color', 'duotone' ],
	[ 'color', 'gradients' ],
	[ 'color', 'palette' ],
	[ 'dimensions', 'dimensionSizes' ],
	[ 'shadow', 'presets' ],
	[ 'spacing', 'spacingSizes' ],
	[ 'typography', 'fontFamilies' ],
	[ 'typography', 'fontSizes' ],
	[ 'typography', 'textShadowPresets' ],
];

const isObject = value => value !== null && typeof value === 'object' && ! Array.isArray( value );

const merge = ( base, next ) => {
	if ( ! isObject( base ) || ! isObject( next ) ) {
		return next === undefined ? base : next;
	}
	const out = { ...base };
	for ( const [ key, value ] of Object.entries( next ) ) {
		out[ key ] = merge( base[ key ], value );
	}
	return out;
};

const byOrigin = ( origin, settings = {} ) => {
	const out = { ...settings };
	for ( const [ group, key ] of PRESET_PATHS ) {
		if ( Array.isArray( settings[ group ]?.[ key ] ) ) {
			out[ group ] = { ...out[ group ], [ key ]: { [ origin ]: settings[ group ][ key ] } };
		}
	}
	if ( isObject( settings.blocks ) ) {
		out.blocks = Object.fromEntries( Object.entries( settings.blocks ).map( ( [ name, block ] ) => [ name, byOrigin( origin, block ) ] ) );
	}
	return out;
};

/**
 * A theme.json as the block editor and GlobalStylesUI read it, resolved the way WordPress
 * resolves it: core's theme.json (WordPress 7.1.4, `wp-core-theme.json`) at the bottom with
 * its presets under the `default` origin, then the theme with each style variation layered
 * over it in order, replacing the arrays and values it sets, its presets under `theme`.
 *
 * @param {Object}    theme      The theme's theme.json.
 * @param {...Object} variations Style variations from the theme's styles/ folder.
 * @return {Object} The resolved config.
 */
export const themeConfig = ( theme, ...variations ) => {
	const merged = variations.reduce( merge, theme );
	return {
		...merged,
		settings: merge( byOrigin( 'default', core.settings ), byOrigin( 'theme', merged.settings ) ),
		styles: merge( core.styles, merged.styles ),
	};
};
