/**
 * handleSegmentation in a switched session: the display segment comes from the
 * reader's stored snapshot, never from a live match computed in the admin's
 * browser, and the snapshot is not rewritten.
 */

let mockSwitched = false;

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
		getBestPrioritySegmentFromSnapshot: () => 'snapshot-segment',
		syncMatchedSegments: jest.fn(),
		shouldPromptBeDisplayed: () => false,
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
		delete window.newspackRAS;
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
} );
