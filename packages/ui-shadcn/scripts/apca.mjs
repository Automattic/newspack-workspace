import { parse, converter } from 'culori';

const toRgb = converter( 'rgb' );

export function rgba255( color ) {
	const c = toRgb( typeof color === 'string' ? parse( color ) : color );
	const ch = v => Math.round( Math.min( 1, Math.max( 0, v ?? 0 ) ) * 255 );
	return [ ch( c.r ), ch( c.g ), ch( c.b ), c.alpha ?? 1 ];
}

export function hex255( [ r, g, b ] ) {
	return '#' + [ r, g, b ].map( v => v.toString( 16 ).padStart( 2, '0' ) ).join( '' );
}

export function composite( fg, bg ) {
	const f = rgba255( fg );
	const b = rgba255( bg );
	const a = f[ 3 ];
	return hex255( [ 0, 1, 2 ].map( i => Math.round( f[ i ] * a + b[ i ] * ( 1 - a ) ) ) );
}

function apcaLuminance( hex ) {
	const [ r, g, b ] = hex.replace( '#', '' ).match( /.{2}/g ).map( x => parseInt( x, 16 ) / 255 );
	const y = 0.2126729 * Math.pow( r, 2.4 ) + 0.7151522 * Math.pow( g, 2.4 ) + 0.072175 * Math.pow( b, 2.4 );
	return y > 0.022 ? y : y + Math.pow( 0.022 - y, 1.414 );
}

function apcaContrast( textY, backgroundY ) {
	if ( Math.abs( backgroundY - textY ) < 0.0005 ) {
		return 0;
	}
	if ( backgroundY > textY ) {
		const sapc = ( Math.pow( backgroundY, 0.56 ) - Math.pow( textY, 0.57 ) ) * 1.14;
		return sapc < 0.1 ? 0 : ( sapc - 0.027 ) * 100;
	}
	const sapc = ( Math.pow( backgroundY, 0.65 ) - Math.pow( textY, 0.62 ) ) * 1.14;
	return sapc > -0.1 ? 0 : ( sapc + 0.027 ) * 100;
}

export function apcaLc( fg, bg, page = bg ) {
	const bgHex = composite( bg, page );
	const fgHex = composite( fg, bgHex );
	return apcaContrast( apcaLuminance( fgHex ), apcaLuminance( bgHex ) );
}
