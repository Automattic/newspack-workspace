/**
 * handleSegmentation in a switched session: the display segment comes from the
 * reader's stored snapshot, never from a live match computed in the admin's
 * browser, and the snapshot is not rewritten.
 */

let mockSwitched = false;
let mockSnapshotSegment = 'snapshot-segment';
let mockShouldDisplay = false;

jest.mock( './utils', () => {
	const actual = jest.requireActual( './utils' );
	return {
		...actual,
		debug: () => {},
		closeOverlay: () => {},
		handleSeen: () => {},
		getIntersectionObserver: () => ( { observe: () => {} } ),
		isSwitchedSession: () => mockSwitched,
		getBestPrioritySegment: () => 'live-segment',
		getBestPrioritySegmentFromSnapshot: () => mockSnapshotSegment,
		syncMatchedSegments: jest.fn(),
		shouldPromptBeDisplayed: () => mockShouldDisplay,
	};
} );

import { handleSegmentation } from './segmentation';
import { syncMatchedSegments } from './utils';

const makeRas = () => ( {
	segments: { register: jest.fn(), setMatch: jest.fn() },
	store: { get: () => undefined, set: () => {} },
} );

describe( 'handleSegmentation in a switched session', () => {
	let ras;

	beforeEach( () => {
		ras = makeRas();
		global.newspack_popups_view = { segments: { 'snapshot-segment': { criteria: [], priority: 0 } } };
		// Deliver the RAS object synchronously, as the library does once initialized.
		window.newspackRAS = { push: callback => callback( ras ) };
		syncMatchedSegments.mockClear();
	} );

	afterEach( () => {
		mockSwitched = false;
		mockSnapshotSegment = 'snapshot-segment';
		mockShouldDisplay = false;
		delete window.newspackRAS;
		jest.useRealTimers();
	} );

	it( 'sets the match from the stored snapshot while switched', () => {
		mockSwitched = true;
		handleSegmentation( [] );
		expect( ras.segments.setMatch ).toHaveBeenCalledWith( 'snapshot-segment' );
		expect( ras.segments.setMatch ).not.toHaveBeenCalledWith( 'live-segment' );
	} );

	it( "sets the match from the live evaluation for the reader's own session", () => {
		handleSegmentation( [] );
		expect( ras.segments.setMatch ).toHaveBeenCalledWith( 'live-segment' );
	} );

	it( 'reads the stored snapshot again when a delayed prompt re-checks before unhiding', () => {
		// A delayed overlay re-evaluates the match when its timer fires; while
		// switched that re-check must stay on the snapshot, whatever the live
		// evaluation would say by then.
		jest.useFakeTimers();
		mockSwitched = true;
		mockShouldDisplay = true;
		const prompt = document.createElement( 'div' );
		prompt.setAttribute( 'id', 'id_7' );
		prompt.setAttribute( 'data-delay', '500' );
		prompt.classList.add( 'newspack-lightbox', 'hidden' );
		handleSegmentation( [ prompt ] );
		mockSnapshotSegment = 'snapshot-later';
		jest.advanceTimersByTime( 500 );
		expect( ras.segments.setMatch ).toHaveBeenLastCalledWith( 'snapshot-later' );
		expect( ras.segments.setMatch ).not.toHaveBeenCalledWith( 'live-segment' );
		expect( prompt.classList.contains( 'hidden' ) ).toBe( false );
	} );
} );
