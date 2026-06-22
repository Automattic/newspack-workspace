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
	// Exercise the real, blog-scoped namespace (np_reader_<blog_id>_*), not the
	// fallback np_reader_* prefix — the bug is specifically about the blog-scoped
	// store_prefix, so a regression in its propagation must be caught here.
	const BLOG_ID = 123;
	const STORE_PREFIX = `np_reader_${ BLOG_ID }_`;
	const storeKey = key => STORE_PREFIX + key;
	const readStore = key => localStorage.getItem( storeKey( key ) );

	beforeEach( () => {
		// Each Store() re-instantiation registers a 1s sync setInterval. Fake
		// timers keep those leaked intervals inert across isolateModules reloads.
		jest.useFakeTimers();
	} );
	afterEach( () => {
		jest.clearAllTimers();
		jest.useRealTimers();
		// Reset the global between tests so a test that throws mid-bootInit can't leak
		// newspack_reader_data (items cache / read_only_keys) into the next test.
		delete window.newspack_reader_data;
	} );

	/**
	 * Boot the reader-activation module in isolation.
	 *
	 * @param {Object}   opts
	 * @param {Object}   opts.storage       Logical store keys → values, seeded under STORE_PREFIX.
	 * @param {Object}   opts.rawStorage    Literal localStorage keys → values (e.g. a sibling namespace).
	 * @param {Object}   opts.config        newspack_ras_config overrides.
	 * @param {Object}   opts.cookies       Cookie name → value.
	 * @param {Object}   opts.serverItems   Server-localized reader-data items (key → raw value). Seeded
	 *                                      into newspack_reader_data.items as encoded values, mirroring
	 *                                      how the server localizes them for rehydrate() to consume.
	 * @param {string[]} opts.readOnlyKeys  newspack_reader_data.read_only_keys (e.g. is_donor).
	 * @param {Function} opts.beforeRequire Hook run after seeding, just before the module loads.
	 */
	function bootInit( { storage = {}, rawStorage = {}, config = {}, cookies = {}, serverItems = null, readOnlyKeys = null, beforeRequire } = {} ) {
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
		// store_prefix drives the store namespace and is read by store.js at
		// module-eval time, so it must be set before the require below. Reset it
		// fresh each call so a prior test's items cache / prefix can't leak in.
		window.newspack_reader_data = { store_prefix: STORE_PREFIX };
		if ( serverItems ) {
			// The server localizes reader-data items as encoded (JSON-stringified)
			// values; store.rehydrate() decodes them. Mirror that wire shape.
			window.newspack_reader_data.items = Object.fromEntries( Object.entries( serverItems ).map( ( [ k, v ] ) => [ k, JSON.stringify( v ) ] ) );
		}
		if ( readOnlyKeys ) {
			window.newspack_reader_data.read_only_keys = readOnlyKeys;
		}
		Object.entries( storage ).forEach( ( [ k, v ] ) => {
			localStorage.setItem( storeKey( k ), JSON.stringify( v ) );
		} );
		Object.entries( rawStorage ).forEach( ( [ k, v ] ) => {
			localStorage.setItem( k, JSON.stringify( v ) );
		} );
		Object.entries( cookies ).forEach( ( [ k, v ] ) => {
			document.cookie = `${ k }=${ v }; path=/`;
		} );
		window.newspack_ras_config = { cid_cookie: 'np_cid', ...config };
		if ( beforeRequire ) {
			beforeRequire();
		}
		jest.isolateModules( () => require( './index' ) );
	}

	it( 'fresh-logout divergence triggers the clear (and leaves sibling namespaces alone)', () => {
		bootInit( {
			storage: {
				reader: { email: 'old@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				is_donor: true,
			},
			// A different blog's namespace must NOT be wiped — guards prefix scoping.
			rawStorage: { np_reader_456_is_donor: true },
			config: { authenticated_email: '' },
		} );
		expect( readStore( 'activity' ) ).toBeNull();
		expect( readStore( 'is_donor' ) ).toBeNull();
		const reader = JSON.parse( readStore( 'reader' ) );
		expect( reader.email ).toBeUndefined();
		expect( reader.authenticated ).toBe( false );
		// Sibling blog namespace untouched.
		expect( localStorage.getItem( 'np_reader_456_is_donor' ) ).not.toBeNull();
	} );

	it( 'already-contaminated divergence triggers the clear', () => {
		// State left behind by the pre-fix init(): authenticated already false,
		// but the prior email and identity artifacts are still there.
		bootInit( {
			storage: {
				reader: { email: 'old@example.com', authenticated: false },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				is_donor: true,
			},
			config: { authenticated_email: '' },
		} );
		expect( readStore( 'activity' ) ).toBeNull();
		expect( readStore( 'is_donor' ) ).toBeNull();
		const reader = JSON.parse( readStore( 'reader' ) );
		expect( reader.email ).toBeUndefined();
	} );

	it( 'anonymous-with-intention preserves activity (no clear)', () => {
		bootInit( {
			storage: {
				reader: { email: 'pending@example.com', authenticated: false },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			cookies: { np_auth_intention: 'pending@example.com' },
			config: { authenticated_email: '' },
		} );
		expect( readStore( 'activity' ) ).not.toBeNull();
	} );

	it( 'authenticated reader page refresh preserves activity (no clear)', () => {
		bootInit( {
			storage: {
				reader: { email: 'still@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			config: { authenticated_email: 'still@example.com' },
		} );
		expect( readStore( 'activity' ) ).not.toBeNull();
	} );

	it( 'stale anonymous config with a valid np_auth_reader cookie does NOT wipe (cached-page guard)', () => {
		// A full-page-cached anonymous config (empty authenticated_email) served to
		// a browser that now holds a valid auth cookie must not wipe the reader's
		// data — otherwise attachAuthCookiesListener() bails on the present cookie
		// and nothing rehydrates it. Regression guard for the cached-page scenario.
		bootInit( {
			storage: {
				reader: { email: 'member@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				is_donor: true,
			},
			cookies: { np_auth_reader: 'member@example.com' },
			config: { authenticated_email: '' },
		} );
		expect( readStore( 'activity' ) ).not.toBeNull();
		expect( readStore( 'is_donor' ) ).not.toBeNull();
	} );

	it( 'a casing-only email difference does NOT wipe (email normalization)', () => {
		// Stored email and the in-progress intention cookie are the same address with
		// different casing. Comparison must be case-insensitive so this isn't treated
		// as an orphaned identity. %40 = @ (getCookie decodeURIComponent-decodes).
		bootInit( {
			storage: {
				reader: { email: 'subscriber_1@example.com', authenticated: false },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			cookies: { np_auth_intention: 'Subscriber_1%40Example.com' },
			config: { authenticated_email: '' },
		} );
		expect( readStore( 'activity' ) ).not.toBeNull();
	} );

	it( 'post-clear init() does not re-write the reader key', () => {
		// Pins the reseed double-duty invariant: clear()'s _set('reader', ...) leaves
		// init()'s equality check satisfied so the trailing store.set('reader', ...) is
		// skipped. Counting writes to the reader key survives the isolateModules
		// boundary (Storage.prototype is global), unlike the in-module event bus. A
		// regression that dropped the equality guard would write 'reader' twice.
		const setItemSpy = jest.spyOn( Storage.prototype, 'setItem' );
		bootInit( {
			storage: {
				reader: { email: 'old@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			config: { authenticated_email: '' },
			// Discard the seeding writes; count only what init()+clear() write.
			beforeRequire: () => setItemSpy.mockClear(),
		} );
		const readerWrites = setItemSpy.mock.calls.filter( call => call[ 0 ] === storeKey( 'reader' ) );
		expect( readerWrites ).toHaveLength( 1 ); // the clear() reseed only.
		setItemSpy.mockRestore();
	} );

	it( 'pure anonymous bootstrap with no prior data does not throw', () => {
		expect( () =>
			bootInit( {
				config: { authenticated_email: '' },
			} )
		).not.toThrow();
		const reader = JSON.parse( readStore( 'reader' ) );
		expect( reader.authenticated ).toBe( false );
		expect( reader.email ).toBeUndefined();
	} );

	it( 'authenticated switch A→B wipes the prior reader data', () => {
		bootInit( {
			storage: {
				reader: { email: 'a@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				is_donor: true,
			},
			config: { authenticated_email: 'b@example.com' },
		} );
		expect( readStore( 'activity' ) ).toBeNull();
		expect( readStore( 'is_donor' ) ).toBeNull();
		const reader = JSON.parse( readStore( 'reader' ) );
		expect( reader.email ).toBe( 'b@example.com' );
		expect( reader.authenticated ).toBe( true );
	} );

	it( "authenticated switch A→B restores reader B's own server data after the clear", () => {
		// The wipe correctly drops reader A's carryover, but clearReaderStore() also
		// empties newspack_reader_data.items, which would make init()'s trailing
		// store.rehydrate() a no-op — leaving reader B briefly missing its OWN
		// server-localized read-only flags (e.g. reading as a non-donor for prompt
		// segmentation) until the next navigation. The authenticated switch pageload
		// is not full-page cached, so the server localizes B's items; they must be
		// rehydrated after the clear (NPPM-2899 inverse gap).
		bootInit( {
			storage: {
				reader: { email: 'a@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				is_donor: true, // reader A's client-persisted flag.
			},
			// Reader B's own server-side read-only state, as localized for the switch
			// pageload. is_donor:false (≠ A's true) proves it's B's value, not A's leftover.
			serverItems: { is_donor: false, is_newsletter_subscriber: true },
			readOnlyKeys: [ 'is_donor', 'is_newsletter_subscriber' ],
			config: { authenticated_email: 'b@example.com' },
		} );
		// Reader A's writable carryover is gone — cross-reader isolation still holds.
		expect( readStore( 'activity' ) ).toBeNull();
		// Reader B's own server data is restored, not lost for the switch pageload.
		expect( JSON.parse( readStore( 'is_donor' ) ) ).toBe( false );
		expect( JSON.parse( readStore( 'is_newsletter_subscriber' ) ) ).toBe( true );
		const reader = JSON.parse( readStore( 'reader' ) );
		expect( reader.email ).toBe( 'b@example.com' );
		expect( reader.authenticated ).toBe( true );
	} );

	it( "authenticated switch A→B wipes even with reader B's np_auth_reader cookie present", () => {
		// The account-switch trigger deliberately ignores np_auth_reader — that cookie
		// only guards *anonymous* full-page-cached responses, and authenticated
		// pageloads bypass the cache. A real A→B pageload carries B's own
		// np_auth_reader cookie, so init() must wire the inputs through such that the
		// clear still fires and reader A's data can't leak to B. This is the headline
		// safety claim; the predicate suite covers it directly, this proves the call site.
		bootInit( {
			storage: {
				reader: { email: 'a@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				is_donor: true,
			},
			cookies: { np_auth_reader: 'b@example.com' },
			config: { authenticated_email: 'b@example.com' },
		} );
		expect( readStore( 'activity' ) ).toBeNull();
		expect( readStore( 'is_donor' ) ).toBeNull();
		const reader = JSON.parse( readStore( 'reader' ) );
		expect( reader.email ).toBe( 'b@example.com' );
		expect( reader.authenticated ).toBe( true );
	} );

	it( 'logout clear does NOT restore server items even when present (authenticated gate)', () => {
		// The post-clear restore is gated on `authenticated`, so only an account
		// switch (A→B) repopulates the namespace. A logout pageload is anonymous, so
		// even if the server localized items they must NOT be restored — the namespace
		// stays empty. Guards against a regression that drops the `authenticated` gate
		// and wipes-then-repopulates on logout.
		bootInit( {
			storage: {
				reader: { email: 'a@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
				is_donor: true,
			},
			serverItems: { is_donor: true, is_newsletter_subscriber: true },
			readOnlyKeys: [ 'is_donor', 'is_newsletter_subscriber' ],
			config: { authenticated_email: '' },
		} );
		expect( readStore( 'activity' ) ).toBeNull();
		expect( readStore( 'is_donor' ) ).toBeNull();
		expect( readStore( 'is_newsletter_subscriber' ) ).toBeNull();
		const reader = JSON.parse( readStore( 'reader' ) );
		expect( reader.email ).toBeUndefined();
		expect( reader.authenticated ).toBe( false );
	} );

	it( 'same-reader re-auth (A→A) preserves activity', () => {
		bootInit( {
			storage: {
				reader: { email: 'a@example.com', authenticated: true },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			config: { authenticated_email: 'a@example.com' },
		} );
		expect( readStore( 'activity' ) ).not.toBeNull();
	} );

	it( 'unauthenticated email lead logging in under a different email preserves carryover', () => {
		bootInit( {
			storage: {
				reader: { email: 'lead@example.com', authenticated: false },
				activity: [ { action: 'article_view', data: { post_id: 1 }, timestamp: 0 } ],
			},
			config: { authenticated_email: 'different@example.com' },
		} );
		expect( readStore( 'activity' ) ).not.toBeNull();
	} );
} );

describe( 'shouldClearReaderData (NPPM-2899)', () => {
	let shouldClearReaderData;
	beforeAll( () => {
		// Pre-set the RAS-init guard so requiring the module doesn't run init().
		window.newspackRASInitialized = true;
		( { shouldClearReaderData } = require( './index' ) );
	} );
	afterAll( () => {
		delete window.newspackRASInitialized;
	} );

	const reader = ( email, authenticated ) => ( { email, authenticated } );

	it( 'clears on authenticated A→B switch', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: 'b@example.com',
				initialEmail: 'b@example.com',
				storedReader: reader( 'a@example.com', true ),
				hasAuthReaderCookie: true,
			} )
		).toBe( true );
	} );

	it( 'does NOT clear when an unauthenticated email lead logs in under a different email', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: 'b@example.com',
				initialEmail: 'b@example.com',
				storedReader: reader( 'lead@example.com', false ),
				hasAuthReaderCookie: true,
			} )
		).toBe( false );
	} );

	it( 'does NOT clear on same-reader re-auth (A→A), case-insensitively', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: 'A@Example.com',
				initialEmail: 'A@Example.com',
				storedReader: reader( 'a@example.com', true ),
				hasAuthReaderCookie: true,
			} )
		).toBe( false );
	} );

	it( 'does NOT clear on anonymous→login with no stored email', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: 'a@example.com',
				initialEmail: 'a@example.com',
				storedReader: {},
				hasAuthReaderCookie: true,
			} )
		).toBe( false );
	} );

	it( 'clears on fresh logout (server anonymous, stored still authenticated)', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: '',
				initialEmail: '',
				storedReader: reader( 'a@example.com', true ),
				hasAuthReaderCookie: false,
			} )
		).toBe( true );
	} );

	it( 'clears on orphaned leftover (server anonymous, stored email ≠ intention)', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: '',
				initialEmail: '',
				storedReader: reader( 'old@example.com', false ),
				hasAuthReaderCookie: false,
			} )
		).toBe( true );
	} );

	it( 'does NOT clear on a cached anonymous page served to a still-authenticated browser', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: '',
				initialEmail: '',
				storedReader: reader( 'a@example.com', true ),
				hasAuthReaderCookie: true,
			} )
		).toBe( false );
	} );

	it( 'does NOT clear on an orphaned email that matches the intention by casing only', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: '',
				initialEmail: 'A@Example.com',
				storedReader: reader( 'a@example.com', false ),
				hasAuthReaderCookie: false,
			} )
		).toBe( false );
	} );

	it( 'clears on authenticated A→B switch even with no auth cookie', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: 'b@example.com',
				initialEmail: 'b@example.com',
				storedReader: reader( 'a@example.com', true ),
				hasAuthReaderCookie: false,
			} )
		).toBe( true );
	} );

	it( 'treats a truthy-but-not-true authenticated flag as not authenticated', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: '',
				initialEmail: 'a@example.com',
				storedReader: { email: 'a@example.com', authenticated: 1 },
				hasAuthReaderCookie: false,
			} )
		).toBe( false );
	} );

	it( 'does NOT clear with empty inputs', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: '',
				initialEmail: '',
				storedReader: {},
				hasAuthReaderCookie: false,
			} )
		).toBe( false );
	} );

	it( 'does NOT clear when storedReader is null (fresh browser, getReader() returned null)', () => {
		// optional chaining + `|| ''` must tolerate a null storedReader on anonymous→login.
		expect(
			shouldClearReaderData( {
				authenticatedEmail: 'a@example.com',
				initialEmail: 'a@example.com',
				storedReader: null,
				hasAuthReaderCookie: false,
			} )
		).toBe( false );
	} );

	it( 'does NOT clear with null/undefined initialEmail and storedReader', () => {
		expect(
			shouldClearReaderData( {
				authenticatedEmail: '',
				initialEmail: undefined,
				storedReader: undefined,
				hasAuthReaderCookie: false,
			} )
		).toBe( false );
	} );
} );
