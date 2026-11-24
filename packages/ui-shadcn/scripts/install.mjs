import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { items } from '../registry/items.mjs';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
if ( ! process.argv[ 2 ] ) {
	console.error( 'Usage: node scripts/install.mjs <target-dir>' );
	process.exit( 1 );
}
const target = resolve( root, process.argv[ 2 ] );
const targets = { ui: 'components/ui', lib: 'lib' };

for ( const item of items ) {
	for ( const file of item.files ) {
		const [ kind, name ] = file.split( '/' );
		const destination = join( target, targets[ kind ], name );
		mkdirSync( dirname( destination ), { recursive: true } );
		copyFileSync( join( root, 'registry', file ), destination );
	}
}

console.log( `newspack-ui-shadcn: installed ${ items.length } registry items into ${ target }` );
