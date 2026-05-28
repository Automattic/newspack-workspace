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

describe( 'init() post-logout clearing (NPPM-2721)', () => {
	function bootInit( { storage = {}, config = {}, cookies = {} } = {} ) {
		// Reset singleton flags and storage backing.
		delete window.newspackRASInitialized;
		delete window.newspackReaderActivation;
		localStorage.clear();
		// Expire all cookies first.
		document.cookie.split( ';' ).forEach( c => {
			const name = c.split( '=' )[ 0 ].trim();
			if ( name ) {
				document.cookie = `${ name }=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
			}
		} );
		Object.entries( storage ).forEach( ( [ k, v ] ) => {
			localStorage.setItem( k, JSON.stringify( v ) );
		} );
		Object.entries( cookies ).forEach( ( [ k, v ] ) => {
			document.cookie = `${ k }=${ v }; path=/`;
		} );
		window.newspack_ras_config = { cid_cookie: 'np_cid', ...config };
		jest.isolateModules( () => require( './index' ) );
	}

	it( 'fresh-logout divergence triggers the clear', () => {
		bootInit( {
			storage: {
				np_reader_reader: { email: 'old@example.com', authenticated: true },
				np_reader_activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				np_reader_is_donor: true,
			},
			config: { authenticated_email: '' },
		} );
		expect( localStorage.getItem( 'np_reader_activity' ) ).toBeNull();
		expect( localStorage.getItem( 'np_reader_is_donor' ) ).toBeNull();
		const reader = JSON.parse( localStorage.getItem( 'np_reader_reader' ) );
		expect( reader.email ).toBeUndefined();
		expect( reader.authenticated ).toBe( false );
	} );

	it( 'already-contaminated divergence triggers the clear', () => {
		// State left behind by the pre-fix init(): authenticated already false,
		// but the prior email and identity artifacts are still there.
		bootInit( {
			storage: {
				np_reader_reader: { email: 'old@example.com', authenticated: false },
				np_reader_activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				np_reader_is_donor: true,
			},
			config: { authenticated_email: '' },
		} );
		expect( localStorage.getItem( 'np_reader_activity' ) ).toBeNull();
		expect( localStorage.getItem( 'np_reader_is_donor' ) ).toBeNull();
		const reader = JSON.parse( localStorage.getItem( 'np_reader_reader' ) );
		expect( reader.email ).toBeUndefined();
	} );

	it( 'anonymous-with-intention preserves activity (no clear)', () => {
		bootInit( {
			storage: {
				np_reader_reader: { email: 'pending@example.com', authenticated: false },
				np_reader_activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			cookies: { np_auth_intention: 'pending@example.com' },
			config: { authenticated_email: '' },
		} );
		expect( localStorage.getItem( 'np_reader_activity' ) ).not.toBeNull();
	} );

	it( 'authenticated reader page refresh preserves activity (no clear)', () => {
		bootInit( {
			storage: {
				np_reader_reader: { email: 'still@example.com', authenticated: true },
				np_reader_activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			config: { authenticated_email: 'still@example.com' },
		} );
		expect( localStorage.getItem( 'np_reader_activity' ) ).not.toBeNull();
	} );

	it( 'post-clear init() does not enqueue a reader re-write', () => {
		bootInit( {
			storage: {
				np_reader_reader: { email: 'old@example.com', authenticated: true },
				np_reader_activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			config: { authenticated_email: '' },
		} );
		// The reseed double-duty invariant: clear()'s _set('reader', ...) leaves
		// the equality check in init() satisfied so the trailing store.set is
		// skipped — and 'reader' must not appear in the persisted unsynced queue.
		const unsynced = JSON.parse( localStorage.getItem( 'np_reader__unsynced' ) || '[]' );
		expect( unsynced ).not.toContain( 'reader' );
	} );

	it( 'pure anonymous bootstrap with no prior data does not throw', () => {
		expect( () =>
			bootInit( {
				config: { authenticated_email: '' },
			} )
		).not.toThrow();
		const reader = JSON.parse( localStorage.getItem( 'np_reader_reader' ) );
		expect( reader.authenticated ).toBe( false );
		expect( reader.email ).toBeUndefined();
	} );
} );
