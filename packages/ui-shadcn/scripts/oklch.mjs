import { converter } from 'culori';

const toOklch = converter( 'oklch' );

export function formatOklch( color ) {
	const { l, c, h, alpha } = toOklch( color );
	const round = ( n, d ) => Number( n.toFixed( d ) );
	const chroma = round( c ?? 0, 4 );
	const hue = chroma ? round( h ?? 0, 2 ) : 0;
	const a = alpha === undefined || alpha >= 1 ? '' : ` / ${ round( alpha, 3 ) }`;
	return `oklch(${ round( l, 4 ) } ${ chroma } ${ hue }${ a })`;
}
