const path = require( 'path' );

const packageDir = path.resolve( __dirname, '..' );

// Each of these holds a store or registry, or must match one that does, so every import gets the playground's pinned copy.
const PINNED = [
	'@wordpress/api-fetch',
	'@wordpress/block-editor',
	'@wordpress/block-library',
	'@wordpress/blocks',
	'@wordpress/components',
	'@wordpress/core-data',
	'@wordpress/data',
	'@wordpress/dataviews',
	'@wordpress/global-styles-engine',
	'@wordpress/global-styles-ui',
	'@wordpress/hooks',
	'@wordpress/interface',
];

const packageRoot = name => path.dirname( require.resolve( `${ name }/package.json`, { paths: [ packageDir ] } ) );

const linkedDeps = name => path.resolve( packageRoot( name ), ...name.split( '/' ).map( () => '..' ) );

module.exports = { PINNED, linkedDeps, packageDir, packageRoot };
