/**
 * Build TypeScript declarations for newspack-icons
 *
 * This script automatically generates TypeScript declaration files
 * for all icon exports, organized in a build-types folder structure
 * following the @wordpress/icons pattern.
 */

/* eslint-disable @typescript-eslint/no-var-requires */

const fs = require( 'fs' );
const path = require( 'path' );

// Create build-types directory
const buildTypesDir = path.join( __dirname, 'build-types' );
if ( ! fs.existsSync( buildTypesDir ) ) {
	fs.mkdirSync( buildTypesDir, { recursive: true } );
}

// Read the main index.js to extract all exports
const indexPath = path.join( __dirname, 'index.js' );
const indexContent = fs.readFileSync( indexPath, 'utf8' );

// Extract icon names from the export statements
const exportMatches = indexContent.match( /export \{ default as (\w+) \}/g );
const iconNames = exportMatches ? exportMatches.map( match => match.match( /as (\w+)/ )[ 1 ] ) : [];

if ( iconNames.length === 0 ) {
	process.exit( 1 );
}

// Generate main index.d.ts in build-types folder
const mainIndexContent = `/**
 * External dependencies
 */
import { ReactElement } from 'react';

// Export all icon types
${ iconNames.map( name => `export declare const ${ name }: ReactElement;` ).join( '\n' ) }

// Default export (for backwards compatibility)
export {};
`;

fs.writeFileSync( path.join( buildTypesDir, 'index.d.ts' ), mainIndexContent );

// Generate individual declaration files for each icon in build-types folder
for ( const iconName of iconNames ) {
	const declarationContent = `/**
 * External dependencies
 */
import { ReactElement } from 'react';

declare const ${ iconName }: ReactElement;
export default ${ iconName };
`;

	const filePath = path.join( buildTypesDir, `${ iconName }.d.ts` );
	fs.writeFileSync( filePath, declarationContent );
}

// Generate package.json for build-types (following @wordpress/icons pattern)
const typesPackageContent = `{
	"name": "newspack-icons/build-types",
	"private": true,
	"main": "index.d.ts",
	"types": "index.d.ts"
}
`;

fs.writeFileSync( path.join( buildTypesDir, 'package.json' ), typesPackageContent );
