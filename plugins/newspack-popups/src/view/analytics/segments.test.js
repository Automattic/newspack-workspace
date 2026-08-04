import { reportMatchedSegments, EVENT_NAME, SESSION_KEY, EMPTY_VALUE } from './segments';
import { getMatchingSegmentIds, sendEvent } from '../utils';

jest.mock( '../utils', () => ( {
	getMatchingSegmentIds: jest.fn(),
	sendEvent: jest.fn(),
} ) );

describe( 'reportMatchedSegments', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		window.sessionStorage.clear();
		global.gtag = jest.fn();
		window.newspack_popups_view = { segments: { 12: {}, 45: {} } };
	} );

	afterEach( () => {
		// Restore any Storage.prototype spies even when a test fails partway
		// through, so one failure here cannot cascade into unrelated tests.
		jest.restoreAllMocks();
	} );

	it( 'reports one event per matched segment', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '12' }, EVENT_NAME );
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: '45' }, EVENT_NAME );
	} );

	it( 'stays silent when the same segments match again', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'reports only the segment newly matched mid-session', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
		expect( sendEvent ).toHaveBeenLastCalledWith( { segment_id: '45' }, EVENT_NAME );
	} );

	it( 'does not report a segment again after it stops and resumes matching', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		const reportedIds = sendEvent.mock.calls.map( call => call[ 0 ].segment_id );
		expect( reportedIds ).toEqual( [ '12', EMPTY_VALUE ] );
	} );

	it( 'reports an empty match explicitly so "matches nothing" is measurable', () => {
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledWith( { segment_id: EMPTY_VALUE }, EVENT_NAME );
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
		const reportedIds = sendEvent.mock.calls.map( call => call[ 0 ].segment_id );
		expect( reportedIds ).toEqual( [ EMPTY_VALUE, '12' ] );
	} );

	it( 'does nothing, and remembers nothing, when gtag is unavailable', () => {
		delete global.gtag;
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
		expect( window.sessionStorage.getItem( SESSION_KEY ) ).toBeNull();
	} );

	it( 'does nothing when segmentation is not active on the page', () => {
		window.newspack_popups_view = {};
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		expect( sendEvent ).not.toHaveBeenCalled();
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
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
	} );
} );
