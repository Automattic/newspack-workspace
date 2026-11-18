const fs = require( 'fs' );
const path = require( 'path' );
const { PINNED, packageRoot } = require( './pinned' );

const resolveExisting = ( dir, target ) => {
	const resolved = path.resolve( dir, target );
	if ( ! fs.existsSync( resolved ) ) {
		throw new Error( `playground: ${ resolved } does not exist` );
	}
	return resolved;
};

const packageName = specifier => specifier.split( '/' ).slice( 0, specifier.startsWith( '@' ) ? 2 : 1 ).join( '/' );

const resolveReplace = ( root, file, specifier, target ) => {
	const fail = reason => new Error( `playground: replace "${ specifier }" in ${ file }: ${ reason }` );
	const name = packageName( specifier );
	if ( ! PINNED.includes( name ) ) {
		throw fail( `${ name } is not a package the playground pins` );
	}
	const original = path.join( packageRoot( name ), specifier.slice( name.length ) );
	if ( ! fs.statSync( original, { throwIfNoEntry: false } )?.isFile() ) {
		throw fail( `no file at ${ original }` );
	}
	const replacement = path.resolve( root, target );
	if ( ! fs.existsSync( replacement ) ) {
		throw fail( `${ replacement } does not exist` );
	}
	return [ original, replacement ];
};

const loadConfig = dir => {
	const root = path.resolve( dir );
	const file = path.join( root, 'playground.config.js' );
	if ( ! fs.existsSync( file ) ) {
		throw new Error( `playground: no playground.config.js in ${ root }` );
	}
	let config;
	try {
		config = require( file );
	} catch ( error ) {
		throw new Error( `playground: could not load ${ file }`, { cause: error } );
	}
	if ( ! config.entry || typeof config.entry !== 'string' ) {
		throw new Error( 'playground: playground.config.js has no entry' );
	}
	const aliases = {};
	for ( const [ name, target ] of Object.entries( config.aliases || {} ) ) {
		aliases[ name ] = resolveExisting( root, target );
	}
	const replace = Object.fromEntries(
		Object.entries( config.replace || {} ).map( ( [ specifier, target ] ) => resolveReplace( root, file, specifier, target ) )
	);
	return {
		title: config.title,
		entry: resolveExisting( root, config.entry ),
		aliases,
		replace,
	};
};

module.exports = { loadConfig };
