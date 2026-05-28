import { on, off } from './events';
import segments, { reset } from './segments';

const sampleSegments = {
	42: { name: 'Loyal Readers', criteria: [ { criteria_id: 'articles_read', value: { min: 5 } } ], priority: 0 },
	43: { name: 'New Visitors', criteria: [ { criteria_id: 'articles_read', value: { max: 2 } } ], priority: 1 },
};

describe( 'segments', () => {
	beforeEach( () => {
		reset();
	} );
	it( 'should return empty object initially', () => {
		expect( segments.getAll() ).toEqual( {} );
	} );
	it( 'should return null for match initially', () => {
		expect( segments.getMatch() ).toBeNull();
	} );
	it( 'should register segments and retrieve them', () => {
		segments.register( sampleSegments );
		const all = segments.getAll();
		expect( all[ '42' ].name ).toBe( 'Loyal Readers' );
		expect( all[ '43' ].name ).toBe( 'New Visitors' );
	} );
	it( 'should set match and emit segment event', () => {
		const callback = jest.fn();
		on( 'segment', callback );
		segments.register( sampleSegments );
		segments.setMatch( '42' );
		expect( callback ).toHaveBeenCalled();
		const detail = callback.mock.calls[ 0 ][ 0 ].detail;
		expect( detail.segmentId ).toBe( '42' );
		expect( detail.segment.name ).toBe( 'Loyal Readers' );
		expect( detail.all ).toEqual( expect.objectContaining( { 42: expect.any( Object ) } ) );
		off( 'segment', callback );
	} );
	it( 'should not re-emit when setting same match', () => {
		const callback = jest.fn();
		on( 'segment', callback );
		segments.register( sampleSegments );
		segments.setMatch( '42' );
		callback.mockClear();
		segments.setMatch( '42' );
		expect( callback ).not.toHaveBeenCalled();
		off( 'segment', callback );
	} );
	it( 'should return matched segment via getMatch', () => {
		segments.register( sampleSegments );
		segments.setMatch( '42' );
		const match = segments.getMatch();
		expect( match.id ).toBe( '42' );
		expect( match.name ).toBe( 'Loyal Readers' );
		expect( match.priority ).toBe( 0 );
	} );
	it( 'should clear match and emit event', () => {
		const callback = jest.fn();
		on( 'segment', callback );
		segments.register( sampleSegments );
		segments.setMatch( '42' );
		callback.mockClear();
		segments.setMatch( null );
		expect( segments.getMatch() ).toBeNull();
		expect( callback ).toHaveBeenCalled();
		expect( callback.mock.calls[ 0 ][ 0 ].detail.segmentId ).toBeNull();
		off( 'segment', callback );
	} );
	it( 'should re-emit when register resolves a pending match', () => {
		const callback = jest.fn();
		on( 'segment', callback );
		segments.setMatch( '42' );
		expect( segments.getMatch() ).toBeNull();
		callback.mockClear();
		segments.register( sampleSegments );
		expect( callback ).toHaveBeenCalled();
		const detail = callback.mock.calls[ 0 ][ 0 ].detail;
		expect( detail.segmentId ).toBe( '42' );
		expect( detail.segment.name ).toBe( 'Loyal Readers' );
		expect( segments.getMatch().id ).toBe( '42' );
		off( 'segment', callback );
	} );
} );
