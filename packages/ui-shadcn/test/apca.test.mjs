import { test } from 'node:test';
import assert from 'node:assert/strict';
import { apcaLc, composite } from '../scripts/apca.mjs';

test( 'white on cobalt reads Lc -95', () => {
	assert.equal( Math.round( apcaLc( '#ffffff', '#003da5' ) ), -95 );
} );

test( 'black on green reads Lc 65', () => {
	assert.equal( Math.round( apcaLc( '#000000', '#26d07c' ) ), 65 );
} );

test( 'a translucent background is composited over the page first', () => {
	assert.equal( Math.round( Math.abs( apcaLc( '#000000', '#000000B3', '#ffffff' ) ) ), 14 );
} );

test( 'composite blends an 8-digit hex over its backdrop', () => {
	assert.equal( composite( '#00000080', '#ffffff' ), '#7f7f7f' );
} );
