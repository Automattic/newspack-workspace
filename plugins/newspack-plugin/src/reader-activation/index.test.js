import { store, dispatchActivity, getActivities, getUniqueActivitiesBy, setReaderEmail, setAuthenticated, getReader } from './index';
import { on, off } from './events';

describe( 'newspackReaderActivation', () => {
	it( 'should emit an event on dispatchActivity', () => {
		const callback = jest.fn();
		on( 'activity', callback );
		dispatchActivity( 'test-emit-on', { test: 'test' } );
		expect( callback ).toHaveBeenCalled();
	} );
	it( 'should not emit to removed listener', () => {
		const callback = jest.fn();
		on( 'activity', callback );
		off( 'activity', callback );
		dispatchActivity( 'test-emit-off', { test: 'test' } );
		expect( callback ).not.toHaveBeenCalled();
	} );
	it( 'should store data and emit an event when setting store key', () => {
		const callback = jest.fn();
		on( 'data', callback );
		store.set( 'test-set', 'test' );
		expect( callback ).toHaveBeenCalled();
		expect( store.get( 'test-set' ) ).toEqual( 'test' );
	} );
	it( 'should dispatchActivity activities', () => {
		const activity = {
			action: 'test',
			data: {
				test: 'test',
			},
			timestamp: 1234567890,
		};
		dispatchActivity( activity.action, activity.data, activity.timestamp );
		expect( getActivities( 'test' ) ).toEqual( [ activity ] );
	} );
	it( 'should dispatchActivity activities with a timestamp', () => {
		const activity = {
			action: 'test-timestamp',
			data: {
				test: 'test',
			},
		};
		dispatchActivity( activity.action, activity.data );
		expect( typeof getActivities( 'test-timestamp' )[ 0 ].timestamp ).toBe( 'number' );
	} );
	it( 'should get unique activities by key', () => {
		const activity1 = {
			action: 'test-unique',
			data: {
				foo: 'bar',
				test: 'test',
			},
		};
		const activity2 = {
			action: 'test-unique',
			data: {
				test: 'test',
			},
		};
		dispatchActivity( activity1.action, activity1.data );
		dispatchActivity( activity2.action, activity2.data );
		expect( getUniqueActivitiesBy( 'test-unique', 'test' ).length ).toEqual( 1 );
	} );
	it( 'should get unique activities by iteratee', () => {
		const activity1 = {
			action: 'test-unique-iteratee',
			data: {
				test: 'test',
			},
		};
		const activity2 = {
			action: 'test-unique-iteratee',
			data: {
				test: 'test',
			},
		};
		dispatchActivity( activity1.action, activity1.data );
		dispatchActivity( activity2.action, activity2.data );
		expect( getUniqueActivitiesBy( 'test-unique-iteratee', activity => activity.data.test ).length ).toEqual( 1 );
	} );
	it( 'should store reader email', () => {
		const email = 'test@example.com';
		setReaderEmail( email );
		expect( getReader().email ).toEqual( email );
	} );
	it( 'should store reader authentication', () => {
		expect( getReader().authenticated ).toBeFalsy();
		setAuthenticated( true );
		expect( getReader().authenticated ).toEqual( true );
	} );
	it( 'should emit an event when reader is updated', () => {
		const callback = jest.fn();
		on( 'reader', callback );
		setReaderEmail( 'test@example.com' );
		expect( callback ).toHaveBeenCalled();
	} );
} );
