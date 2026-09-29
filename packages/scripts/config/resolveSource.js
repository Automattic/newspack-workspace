/**
 * Extension-agnostic source file resolution for webpack configs.
 *
 * Entry points are declared extensionless; the file on disk may be TypeScript
 * or JavaScript. TypeScript is probed first so a converted file wins over a
 * leftover JS twin.
 */

'use strict';

const fs = require( 'fs' );

const SOURCE_EXTENSIONS = [ '.tsx', '.ts', '.jsx', '.js' ];
const SOURCE_FILE_REGEX = /\.(j|t)sx?$/;

/**
 * Find the source file for an extensionless path.
 *
 * @param {string} basePath Absolute path without extension.
 * @return {string|undefined} The existing file path, or undefined when absent.
 */
const findSourceFile = basePath => SOURCE_EXTENSIONS.map( ext => basePath + ext ).find( fs.existsSync );

/**
 * Like findSourceFile, but throws so a missing entry fails the build loudly
 * instead of being silently dropped.
 *
 * @param {string} basePath Absolute path without extension.
 * @return {string} The existing file path.
 */
const resolveSourceFile = basePath => {
	const found = findSourceFile( basePath );
	if ( ! found ) {
		throw new Error( `No source file found for webpack entry: ${ basePath }` );
	}
	return found;
};

module.exports = {
	SOURCE_EXTENSIONS,
	SOURCE_FILE_REGEX,
	findSourceFile,
	resolveSourceFile,
};
