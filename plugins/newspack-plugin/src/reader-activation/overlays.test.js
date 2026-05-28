import { on } from './events';
import overlays from './overlays';

describe( 'overlays', () => {
	beforeEach( () => {
		// Clear overlays.
		overlays.get().forEach( overlayId => overlays.remove( overlayId ) );
	} );
	it( 'should return an array', () => {
		expect( Array.isArray( overlays.get() ) ).toBe( true );
	} );
	it( 'should add an overlay and emit the overlay event', () => {
		const callback = jest.fn();
		on( 'overlay', callback );
		const overlayId = overlays.add();
		expect( overlays.get() ).toEqual( [ overlayId ] );
		expect( callback ).toHaveBeenCalled();
	} );
	it( 'should remove an overlay and emit the overlay event', () => {
		const callback = jest.fn();
		on( 'overlay', callback );
		const overlayId = overlays.add();
		overlays.remove( overlayId );
		expect( overlays.get() ).toEqual( [] );
		expect( callback ).toHaveBeenCalled();
	} );
} );
