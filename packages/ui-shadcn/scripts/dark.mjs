import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const darkPalettePath = fileURLToPath( new URL( '../build/dark-palette', import.meta.url ) );

const read = name => {
	const file = join( darkPalettePath, name );
	return existsSync( file ) ? readFileSync( file, 'utf8' ) : null;
};

const tokensJson = read( 'dark-tokens.json' );
const scalesJson = read( 'dark-scales.json' );

export const darkTokens = tokensJson ? JSON.parse( tokensJson ) : {};
export const darkRule = scalesJson ? JSON.parse( scalesJson ).rule : null;
export const darkCss = read( 'dark.css' );
