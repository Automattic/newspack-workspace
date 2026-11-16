const fs = require( 'fs' );
const path = require( 'path' );

const resolveExisting = ( dir, target ) => {
	const resolved = path.resolve( dir, target );
	if ( ! fs.existsSync( resolved ) ) {
		throw new Error( `playground: ${ resolved } does not exist` );
	}
	return resolved;
};

const loadConfig = dir => {
	const root = path.resolve( dir );
	const file = path.join( root, 'playground.config.js' );
	if ( ! fs.existsSync( file ) ) {
		throw new Error( `playground: no playground.config.js in ${ root }` );
	}
	const config = require( file );
	if ( typeof config.entry !== 'string' ) {
		throw new Error( 'playground: playground.config.js has no entry' );
	}
	const aliases = {};
	for ( const [ name, target ] of Object.entries( config.aliases || {} ) ) {
		aliases[ name ] = resolveExisting( root, target );
	}
	return {
		title: config.title,
		entry: resolveExisting( root, config.entry ),
		aliases,
	};
};

module.exports = { loadConfig };
