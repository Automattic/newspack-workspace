import { generateID } from './utils';

describe( 'generateID', () => {
	it( 'should generate a random ID', () => {
		const id = generateID();
		expect( id ).toHaveLength( 9 );
	} );
	it( 'should generate a random ID of the given length', () => {
		const id = generateID( 10 );
		expect( id ).toHaveLength( 10 );
	} );
	it( 'should always generate a random ID of the given length', () => {
		let failedIds = 0;
		for ( let i = 0; i < 1000; i++ ) {
			const id = generateID( 10 );
			if ( id.length !== 10 ) {
				failedIds++;
			}
		}
		expect( failedIds ).toEqual( 0 );
	} );
	it( 'should be unique among 10000 generated IDs', () => {
		const ids = [];
		for ( let i = 0; i < 10000; i++ ) {
			ids[ generateID() ] = true;
		}
		expect( Object.keys( ids ) ).toHaveLength( 10000 );
	} );
} );
