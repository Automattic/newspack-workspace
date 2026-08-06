import { reportMatchedSegments, EVENT_NAME, STORE_KEY, EMPTY_VALUE, SESSION_TIMEOUT } from './segments';
import { getCarriedSegmentIds, getMatchingSegmentIds, getPreviewedPromptId, sendEvent } from '../utils';
import { getCriteria } from '../../criteria/utils';

jest.mock( '../utils', () => ( {
	getCarriedSegmentIds: jest.fn(),
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

const makeRas = () => {
	const data = {};
	return {
		store: {
			get: jest.fn( key => data[ key ] ),
			set: jest.fn( ( key, value ) => {
				data[ key ] = value;
			} ),
		},
	};
};

describe( 'reportMatchedSegments', () => {
	let ras;

	beforeEach( () => {
		jest.clearAllMocks();
		getPreviewedPromptId.mockReturnValue( null );
		getCarriedSegmentIds.mockReturnValue( [] );
		getCriteria.mockReturnValue( { id: 'registered' } );
		global.gtag = jest.fn();
		// Criteria-less segments match every reader and are always reportable.
		window.newspack_popups_view = { segments: { 12: { criteria: [] }, 45: { criteria: [] } } };
		window.history.replaceState( {}, '', '/' );
		clearGaCookies();
		ras = makeRas();
	} );

	afterEach( () => {
		// Restore any spies even when a test fails partway through, so one
		// failure here cannot cascade into unrelated tests.
		jest.restoreAllMocks();
	} );

	it( 'reports one event per matched segment, with device-only bookkeeping', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '12' }, EVENT_NAME );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '45' }, EVENT_NAME );
		// The reported set is stored without syncing to reader meta.
		expect( ras.store.set ).toHaveBeenCalledWith( STORE_KEY, expect.objectContaining( { value: [ '12', '45' ] } ), false );
	} );

	it( 'stays silent when the same segments match again', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments( ras );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'reports only the segment newly matched mid-session', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
		expect( sendEvent ).toHaveBeenLastCalledWith( { segment_id: '45' }, EVENT_NAME );
	} );

	it( 'does not report a segment again after it stops and resumes matching', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments( ras );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		const reportedIds = sendEvent.mock.calls.map( call => call[ 0 ].segment_id );
		expect( reportedIds ).toEqual( [ '12', EMPTY_VALUE ] );
	} );

	it( 'reports an empty match explicitly so "matches nothing" is measurable', () => {
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: EMPTY_VALUE }, EVENT_NAME );
	} );

	it( 'reports the empty match only once per session', () => {
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments( ras );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports a segment matched after an earlier empty match', () => {
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments( ras );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		const reportedIds = sendEvent.mock.calls.map( call => call[ 0 ].segment_id );
		expect( reportedIds ).toEqual( [ EMPTY_VALUE, '12' ] );
	} );

	it( 'does nothing, and remembers nothing, when gtag is unavailable', () => {
		delete global.gtag;
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		expect( sendEvent ).not.toHaveBeenCalled();
		expect( ras.store.set ).not.toHaveBeenCalled();
	} );

	it( 'does nothing when segmentation is not active on the page', () => {
		window.newspack_popups_view = {};
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		expect( sendEvent ).not.toHaveBeenCalled();
	} );

	it( 'does nothing on a site with no segments configured', () => {
		// "Matched nothing" is only meaningful against segments that exist;
		// an empty segments object must not produce a `none` event stream.
		window.newspack_popups_view = { segments: {} };
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments( ras );
		expect( sendEvent ).not.toHaveBeenCalled();
		expect( ras.store.set ).not.toHaveBeenCalled();
	} );

	it( 'does not count preview traffic toward reach', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		getPreviewedPromptId.mockReturnValue( 123 );
		reportMatchedSegments( ras );
		getPreviewedPromptId.mockReturnValue( null );
		window.history.replaceState( {}, '', '/?view_as=segment:12' );
		reportMatchedSegments( ras );
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
		reportMatchedSegments( ras );
		// Only the fully registered segment is evaluated and reported.
		expect( getMatchingSegmentIds ).toHaveBeenCalledWith( { 45: segments[ 45 ] } );
		expect( sendEvent ).toHaveBeenCalledTimes( 1 );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '45' }, EVENT_NAME );
	} );

	it( 'reports nothing when no segment has fully registered criteria', () => {
		window.newspack_popups_view = {
			segments: { 12: { criteria: [ { criteria_id: 'active_memberships' } ] } },
		};
		getCriteria.mockReturnValue( undefined );
		reportMatchedSegments( ras );
		expect( sendEvent ).not.toHaveBeenCalled();
		expect( ras.store.set ).not.toHaveBeenCalled();
	} );

	it( 'resets the reported set when the GA4 session ID changes', () => {
		setGaSession( '1700000001' );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 1 );
		// A new GA4 session (30 minutes of inactivity or the midnight cutover
		// mints a new session ID) reports the still-matching segment again.
		setGaSession( '1700009999' );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'expires the fallback session window after 30 minutes of inactivity', () => {
		const now = 1700000000000;
		const dateSpy = jest.spyOn( Date, 'now' ).mockReturnValue( now );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		// Within the window: same session, nothing new to report.
		dateSpy.mockReturnValue( now + SESSION_TIMEOUT - 1000 );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 1 );
		// The window slides with activity: measured from the quiet pageview
		// above, not from the first report.
		dateSpy.mockReturnValue( now + 2 * SESSION_TIMEOUT );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'contains a throwing dispatch, recording only the IDs that sent', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		sendEvent
			.mockImplementationOnce( () => {} )
			.mockImplementationOnce( () => {
				throw new Error( 'consent shim' );
			} );
		// A throwing gtag shim must not unwind into the RAS queue drain.
		expect( () => reportMatchedSegments( ras ) ).not.toThrow();
		// The ID sent before the throw is recorded; the unsent one retries.
		sendEvent.mockImplementation( () => {} );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 3 );
		expect( sendEvent ).toHaveBeenLastCalledWith( { segment_id: '45' }, EVENT_NAME );
	} );

	it( 'dispatches every pageview when the store is unusable, without throwing', () => {
		ras.store.get.mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		ras.store.set.mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		expect( () => reportMatchedSegments( ras ) ).not.toThrow();
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'reports carried segments alongside local matches, once per session', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		getCarriedSegmentIds.mockReturnValue( [ '45' ] );
		reportMatchedSegments( ras );
		reportMatchedSegments( ras );
		const reportedIds = sendEvent.mock.calls.map( call => call[ 0 ].segment_id );
		expect( reportedIds ).toEqual( [ '12', '45' ] );
	} );

	it( 'reports a carried segment even when no local segment is reportable', () => {
		// Every page segment uses an unregistered criterion, so local
		// evaluation is withheld entirely...
		window.newspack_popups_view = {
			segments: { 12: { criteria: [ { criteria_id: 'active_memberships' } ] } },
		};
		getCriteria.mockReturnValue( undefined );
		getMatchingSegmentIds.mockReturnValue( [] );
		// ...but the newsletter link asserted one of them.
		getCarriedSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 1 );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '12' }, EVENT_NAME );
	} );

	it( 'dedupes against the real getMatchingSegmentIds return type', () => {
		// The dedup rests on a cross-module invariant: getMatchingSegmentIds
		// returns object keys, i.e. strings, and the stored set compares
		// strictly. Run the real implementation so a future change to its
		// return type fails here instead of silently breaking the dedup.
		const { getMatchingSegmentIds: realGetMatchingSegmentIds } = jest.requireActual( '../utils' );
		getMatchingSegmentIds.mockImplementation( realGetMatchingSegmentIds );
		reportMatchedSegments( ras );
		reportMatchedSegments( ras );
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '12' }, EVENT_NAME );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '45' }, EVENT_NAME );
	} );
} );
