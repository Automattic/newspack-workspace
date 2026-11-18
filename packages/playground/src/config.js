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

const isFile = file => fs.statSync( file, { throwIfNoEntry: false } )?.isFile();

const resolveReplace = ( root, file, key, target ) => {
	const fail = reason => new Error( `playground: replace "${ key }" in ${ file }: ${ reason }` );
	if ( /^[./]/.test( key ) ) {
		throw fail( 'the key must start with a package name' );
	}
	const name = packageName( key );
	if ( ! PINNED.includes( name ) ) {
		throw fail( `${ name } is not a package the playground pins` );
	}
	const packagePath = packageRoot( name );
	const original = path.join( packagePath, key.slice( name.length ) );
	const inside = path.relative( packagePath, original );
	if ( inside === '..' || inside.startsWith( `..${ path.sep }` ) ) {
		throw fail( `${ original } is outside ${ name }` );
	}
	if ( ! isFile( original ) ) {
		throw fail( `no file at ${ original }` );
	}
	const replacement = path.resolve( root, target );
	if ( ! isFile( replacement ) ) {
		throw fail( `no file at ${ replacement }` );
	}
	return { key, name, original, replacement };
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
	const replace = Object.entries( config.replace || {} ).map( ( [ key, target ] ) => resolveReplace( root, file, key, target ) );
	return {
		title: config.title,
		entry: resolveExisting( root, config.entry ),
		file,
		aliases,
		replace,
	};
};

module.exports = { loadConfig };
