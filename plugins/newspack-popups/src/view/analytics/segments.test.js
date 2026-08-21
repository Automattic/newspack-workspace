import { reportMatchedSegments, EVENT_NAME, WON_EVENT_NAME, STORAGE_KEY, EMPTY_VALUE, SESSION_TIMEOUT } from './segments';
import { getMatchingSegmentIds, getPreviewedPromptId, sendEvent } from '../utils';
import { getCriteria } from '../../criteria/utils';

jest.mock( '../utils', () => ( {
	getMatchingSegmentIds: jest.fn(),
	getPreviewedPromptId: jest.fn(),
	sendEvent: jest.fn(),
} ) );

jest.mock( '../../criteria/utils', () => ( {
	getCriteria: jest.fn(),
} ) );

const GA_COOKIE = '_ga_TEST123';

const setGaSession = sid => {
	document.cookie = `${ GA_COOKIE }=GS1.1.${ sid }.5.1.1700000000.60.0.0`;
};

const clearGaCookies = () => {
	document.cookie.split( '; ' ).forEach( pair => {
		const name = pair.split( '=' )[ 0 ];
		if ( name && 0 === name.indexOf( '_ga_' ) ) {
			document.cookie = `${ name }=; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
		}
	} );
};

const storedState = ( siteId = 0 ) => JSON.parse( window.localStorage.getItem( `${ STORAGE_KEY }-${ siteId }` ) );

// IDs reported through a given event, in call order.
const reportedIds = eventName => sendEvent.mock.calls.filter( call => call[ 1 ] === eventName ).map( call => call[ 0 ].segment_id );

describe( 'reportMatchedSegments', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		getPreviewedPromptId.mockReturnValue( null );
		getCriteria.mockReturnValue( { id: 'registered' } );
		window.localStorage.clear();
		global.gtag = jest.fn();
		// Criteria-less segments match every reader and are always reportable.
		// Lower priority number wins: 12 outranks 45.
		window.newspack_popups_view = {
			segments: { 12: { criteria: [], priority: 0 }, 45: { criteria: [], priority: 1 } },
		};
		window.history.replaceState( {}, '', '/' );
		clearGaCookies();
	} );

	afterEach( () => {
		// Restore any Storage.prototype spies even when a test fails partway
		// through, so one failure here cannot cascade into unrelated tests.
		jest.restoreAllMocks();
	} );

	it( 'reports each matched segment and the priority winner, with bookkeeping outside reader data', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', '45' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12' ] );
		// The reported sets live in a Campaigns-owned localStorage key — segment
		// IDs are deliberately not reader data items.
		expect( storedState().ids ).toEqual( [ '12', '45' ] );
		expect( storedState().won ).toEqual( [ '12' ] );
	} );

	it( 'stays silent when the same segments match again', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 3 );
	} );

	it( 'reports only the segment newly matched mid-session', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', '45' ] );
		// 12 outranks 45 throughout, so the win never moves.
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12' ] );
	} );

	it( 'passes the won event to a higher-priority segment that starts matching', () => {
		getMatchingSegmentIds.mockReturnValue( [ '45' ] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '45', '12' ] );
		// Each segment controlled prompt eligibility for part of the session.
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '45', '12' ] );
	} );

	it( 'passes the won event to the next segment when the winner stops matching', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '45' ] );
		reportMatchedSegments();
		// 45 was already reported as matched; inheriting the win is the only new fact.
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', '45' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12', '45' ] );
	} );

	it( 'does not report a segment again after it stops and resumes matching', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', EMPTY_VALUE ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12' ] );
	} );

	it( 'reports an empty match explicitly, with no won event', () => {
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ EMPTY_VALUE ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [] );
	} );

	it( 'reports the empty match only once per session', () => {
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments();
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports a segment matched after an earlier empty match', () => {
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ EMPTY_VALUE, '12' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12' ] );
	} );

	it( 'does nothing, and remembers nothing, when gtag is unavailable', () => {
		delete global.gtag;
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
		expect( storedState() ).toBeNull();
	} );

	it( 'does nothing when segmentation is not active on the page', () => {
		window.newspack_popups_view = {};
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
	} );

	it( 'does nothing on a site with no segments configured', () => {
		// "Matched nothing" is only meaningful against segments that exist;
		// an empty segments object must not produce a `none` event stream.
		window.newspack_popups_view = { segments: {} };
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
		expect( storedState() ).toBeNull();
	} );

	it( 'does not count preview traffic toward reach', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		getPreviewedPromptId.mockReturnValue( 123 );
		reportMatchedSegments();
		getPreviewedPromptId.mockReturnValue( null );
		window.history.replaceState( {}, '', '/?view_as=segment:12' );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
	} );

	it( 'withholds segments whose criteria are not registered on this site', () => {
		const segments = {
			12: { criteria: [ { criteria_id: 'active_memberships' } ] },
			45: { criteria: [ { criteria_id: 'articles_read' } ] },
		};
		window.newspack_popups_view = { segments };
		getCriteria.mockImplementation( id => ( 'articles_read' === id ? { id } : undefined ) );
		getMatchingSegmentIds.mockReturnValue( [ '45' ] );
		reportMatchedSegments();
		// Only the fully registered segment is evaluated and reported.
		expect( getMatchingSegmentIds ).toHaveBeenCalledWith( { 45: segments[ 45 ] } );
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '45' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '45' ] );
	} );

	it( 'reports nothing when no segment has fully registered criteria', () => {
		window.newspack_popups_view = {
			segments: { 12: { criteria: [ { criteria_id: 'active_memberships' } ] } },
		};
		getCriteria.mockReturnValue( undefined );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
		expect( storedState() ).toBeNull();
	} );

	it( 'resets the reported sets when the GA4 session ID changes', () => {
		setGaSession( '1700000001' );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
		// A new GA4 session (30 minutes of inactivity or the midnight cutover
		// mints a new session ID) reports the still-matching segment — and its
		// win — again.
		setGaSession( '1700009999' );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', '12' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12', '12' ] );
	} );

	it( 'expires the fallback session window after 30 minutes of inactivity', () => {
		const now = 1700000000000;
		const dateSpy = jest.spyOn( Date, 'now' ).mockReturnValue( now );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		// Within the window: same session, nothing new to report.
		dateSpy.mockReturnValue( now + SESSION_TIMEOUT - 1000 );
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
		// The window slides with activity: measured from the quiet pageview
		// above, not from the first report.
		dateSpy.mockReturnValue( now + 2 * SESSION_TIMEOUT );
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 4 );
	} );

	it( 'keeps sites on a shared origin from suppressing each other', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		window.newspack_popups_view.site_id = 7;
		reportMatchedSegments();
		// Same segment ID on a sibling site is a different segment — it must
		// report on its own, from its own storage key.
		window.newspack_popups_view.site_id = 8;
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 4 );
		expect( storedState( 7 ).ids ).toEqual( [ '12' ] );
		expect( storedState( 7 ).won ).toEqual( [ '12' ] );
		expect( storedState( 8 ).ids ).toEqual( [ '12' ] );
		expect( storedState( 8 ).won ).toEqual( [ '12' ] );
	} );

	it( 'contains a throwing dispatch, recording only the IDs that sent', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		sendEvent
			.mockImplementationOnce( () => {} )
			.mockImplementationOnce( () => {
				throw new Error( 'consent shim' );
			} );
		// A throwing gtag shim must not unwind into the RAS queue drain.
		expect( () => reportMatchedSegments() ).not.toThrow();
		// The ID sent before the throw is recorded; the unsent matched ID and
		// the won event retry on the next pageview.
		sendEvent.mockImplementation( () => {} );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', '45', '45' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12' ] );
	} );

	it( 'dispatches every pageview when storage is unavailable, without throwing', () => {
		jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		expect( () => reportMatchedSegments() ).not.toThrow();
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 4 );
	} );

	it( 'dedupes against the real getMatchingSegmentIds return type', () => {
		// The dedup rests on a cross-module invariant: getMatchingSegmentIds
		// returns object keys, i.e. strings, and the stored set compares
		// strictly. Run the real implementation so a future change to its
		// return type fails here instead of silently breaking the dedup.
		const { getMatchingSegmentIds: realGetMatchingSegmentIds } = jest.requireActual( '../utils' );
		getMatchingSegmentIds.mockImplementation( realGetMatchingSegmentIds );
		reportMatchedSegments();
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 3 );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '12' }, EVENT_NAME );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '45' }, EVENT_NAME );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '12' }, WON_EVENT_NAME );
	} );
} );
