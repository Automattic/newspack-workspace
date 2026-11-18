import core from './wp-core-theme.json';

/**
 * The theme.json preset paths: WordPress's `WP_Theme_JSON::PRESETS_METADATA`, which the editor
 * reads by origin, and `typography.textShadowPresets`, which @wordpress/global-styles-engine reads.
 */
const PRESET_PATHS = [
	[ 'border', 'radiusSizes' ],
	[ 'color', 'duotone' ],
	[ 'color', 'gradients' ],
	[ 'color', 'palette' ],
	[ 'dimensions', 'aspectRatios' ],
	[ 'dimensions', 'dimensionSizes' ],
	[ 'shadow', 'presets' ],
	[ 'spacing', 'spacingSizes' ],
	[ 'typography', 'fontFamilies' ],
	[ 'typography', 'fontSizes' ],
	[ 'typography', 'textShadowPresets' ],
];

/**
 * The settings `appearanceTools: true` turns on: `WP_Theme_JSON::APPEARANCE_TOOLS_OPT_INS` in
 * WordPress 7.1.4. WordPress expands the flag on the server, so the editor never sees it.
 */
const APPEARANCE_TOOLS = [
	[ 'background', 'backgroundImage' ],
	[ 'background', 'backgroundSize' ],
	[ 'background', 'gradient' ],
	[ 'border', 'color' ],
	[ 'border', 'radius' ],
	[ 'border', 'style' ],
	[ 'border', 'width' ],
	[ 'color', 'link' ],
	[ 'color', 'heading' ],
	[ 'color', 'button' ],
	[ 'color', 'caption' ],
	[ 'dimensions', 'aspectRatio' ],
	[ 'dimensions', 'height' ],
	[ 'dimensions', 'minHeight' ],
	[ 'dimensions', 'minWidth' ],
	[ 'dimensions', 'width' ],
	[ 'position', 'sticky' ],
	[ 'spacing', 'blockGap' ],
	[ 'spacing', 'margin' ],
	[ 'spacing', 'padding' ],
	[ 'typography', 'lineHeight' ],
	[ 'typography', 'textColumns' ],
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

const optIn = settings => {
	if ( settings.appearanceTools !== true ) {
		return settings;
	}
	const { appearanceTools, ...out } = settings;
	for ( const [ group, key ] of APPEARANCE_TOOLS ) {
		if ( out[ group ]?.[ key ] === undefined ) {
			out[ group ] = { ...out[ group ], [ key ]: true };
		}
	}
	return out;
};

const expandAppearanceTools = ( settings = {} ) => {
	const out = optIn( settings );
	if ( isObject( out.blocks ) ) {
		out.blocks = Object.fromEntries( Object.entries( out.blocks ).map( ( [ name, block ] ) => [ name, optIn( block ) ] ) );
	}
	return out;
};

/**
 * WordPress's v2 to v3 migration of a theme file (`WP_Theme_JSON_Schema::migrate_v2_to_v3`): a
 * v2 file that sets its own font or spacing sizes turns core's off, as v2 always did. A file
 * with no version is left as it is, where WordPress would discard it.
 */
const migrate = layer => {
	if ( ! layer?.settings || ! ( layer.version < 3 ) ) {
		return layer;
	}
	const { typography, spacing } = layer.settings;
	return {
		...layer,
		settings: {
			...layer.settings,
			...( typography?.fontSizes !== undefined && { typography: { ...typography, defaultFontSizes: false } } ),
			...( ( spacing?.spacingSizes !== undefined || spacing?.spacingScale !== undefined ) && { spacing: { ...spacing, defaultSpacingSizes: false } } ),
		},
	};
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
 * over it in order, replacing the arrays and values it sets, its presets under `theme`. Each
 * file is first migrated to schema v3 and has its `appearanceTools` expanded into the settings
 * it turns on, as WordPress does per file.
 *
 * @param {Object}    theme      The theme's theme.json.
 * @param {...Object} variations Style variations from the theme's styles/ folder.
 * @return {Object} The resolved config.
 */
export const themeConfig = ( theme, ...variations ) => {
	const [ base, ...rest ] = [ theme, ...variations ].map( layer => {
		const migrated = migrate( layer );
		return migrated?.settings ? { ...migrated, settings: expandAppearanceTools( migrated.settings ) } : migrated;
	} );
	const merged = rest.reduce( merge, base );
	return {
		...merged,
		settings: merge( byOrigin( 'default', core.settings ), byOrigin( 'theme', merged.settings ) ),
		styles: merge( core.styles, merged.styles ),
	};
};
