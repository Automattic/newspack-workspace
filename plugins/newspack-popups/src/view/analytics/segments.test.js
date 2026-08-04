import { reportMatchedSegments, joinWithinLimit, EVENT_NAME, SESSION_KEY } from './segments';
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

	it( 'reports the matched set on the first evaluation of a session', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledWith( { segments: '12,45' }, EVENT_NAME );
	} );

	it( 'stays silent when the matched set has not changed', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports again when the matched set changes mid-session', () => {
		getMatchingSegmentIds.mockReturnValue( [ '12' ] );
		reportMatchedSegments();
		getMatchingSegmentIds.mockReturnValue( [ '12', '45' ] );
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledTimes( 2 );
		expect( sendEvent ).toHaveBeenLastCalledWith( { segments: '12,45' }, EVENT_NAME );
	} );

	it( 'reports an empty match explicitly so "matches nothing" is measurable', () => {
		getMatchingSegmentIds.mockReturnValue( [] );
		reportMatchedSegments();
		expect( sendEvent ).toHaveBeenCalledWith( { segments: 'none' }, EVENT_NAME );
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

describe( 'joinWithinLimit', () => {
	it( 'joins IDs with commas', () => {
		expect( joinWithinLimit( [ '12', '45' ] ) ).toBe( '12,45' );
	} );

	it( 'drops whole IDs rather than letting GA4 cut mid-number', () => {
		// 25 four-digit IDs joined would be 124 characters, over GA4's 100-char cap.
		const ids = Array.from( { length: 25 }, ( _, index ) => String( 1000 + index ) );
		const value = joinWithinLimit( ids );
		const kept = value.split( ',' );
		expect( value.length ).toBeLessThanOrEqual( 100 );
		expect( kept ).toEqual( ids.slice( 0, kept.length ) );
		expect( ids ).toContain( kept[ kept.length - 1 ] );
	} );
} );
