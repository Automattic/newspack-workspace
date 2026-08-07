import { reportMatchedSegments, EVENT_NAME, WON_EVENT_NAME, SESSION_KEY, WON_SESSION_KEY, EMPTY_VALUE } from './segments';
import { getMatchingSegmentIds, sendEvent } from '../utils';

jest.mock( '../utils', () => ( {
	getMatchingSegmentIds: jest.fn(),
	sendEvent: jest.fn(),
} ) );

// IDs reported through a given event, in call order.
const reportedIds = eventName => sendEvent.mock.calls.filter( call => call[ 1 ] === eventName ).map( call => call[ 0 ].segment_id );

describe( 'reportMatchedSegments', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		window.sessionStorage.clear();
		global.gtag = jest.fn();
		// Lower priority number wins: 12 outranks 45.
		window.newspack_popups_view = { segments: { 12: { priority: 0 }, 45: { priority: 1 } } };
	} );

	afterEach( () => {
		// Restore any Storage.prototype spies even when a test fails partway
		// through, so one failure here cannot cascade into unrelated tests.
		jest.restoreAllMocks();
	} );

	it( 'reports one matched event per segment and one won event for the priority winner', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', '45' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12' ] );
	} );

	it( 'stays silent when the same segments match again', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', '45' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12' ] );
	} );

	it( 'reports a newly matched segment mid-session, and the won event follows the new winner', () => {
		getMatchingSegmentIds.mockReturnValue( [ '45' ] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '45', '12' ] );
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
		expect( window.sessionStorage.getItem( SESSION_KEY ) ).toBeNull();
		expect( window.sessionStorage.getItem( WON_SESSION_KEY ) ).toBeNull();
	} );

	it( 'does nothing when segmentation is not active on the page', () => {
		window.newspack_popups_view = {};
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
	} );

	it( 'does not report during a segment preview', () => {
		window.history.pushState( {}, '', '/?view_as=segment:45' );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
		window.history.pushState( {}, '', '/' );
	} );

	it( 'dispatches every time when sessionStorage is unavailable', () => {
		jest.spyOn( Storage.prototype, 'getItem' ).mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		jest.spyOn( Storage.prototype, 'setItem' ).mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		reportMatchedSegments();
		expect( reportedIds( EVENT_NAME ) ).toEqual( [ '12', '12' ] );
		expect( reportedIds( WON_EVENT_NAME ) ).toEqual( [ '12', '12' ] );
	} );
} );
