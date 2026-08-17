/**
 * Tests for the authenticated gate on carried segment IDs staying live
 * across a delayed prompt re-check.
 *
 * The carried set and the `authenticated` check both used to be computed
 * once in maybeDisplayPrompts() and closed over by the delayed unhide()
 * path. RAS's setAuthenticated() can flip a reader to authenticated mid-page
 * with no reload, so a still-pending delayed or scroll-triggered prompt's
 * re-check must reflect that change — a signed-in reader's live matching
 * always wins over a carried snapshot from a previous, logged-out visit.
 *
 * ./utils and ./utils/carried-segments are mocked so this test isolates
 * segmentation.js's own closure/gating logic from the deeper segment-match
 * and cookie-parsing logic those modules already cover in their own tests
 * (segments.test.js, carried-segments.test.js).
 */

jest.mock( './utils', () => ( {
	debug: jest.fn(),
	closeOverlay: jest.fn(),
	getBestPrioritySegment: jest.fn( () => null ),
	getIntersectionObserver: jest.fn(),
	getRawId: jest.fn( () => 1 ),
	getOverride: jest.fn( () => null ),
	handleSeen: jest.fn(),
	shouldPromptBeDisplayed: jest.fn( () => true ),
	syncMatchedSegments: jest.fn(),
} ) );

jest.mock( './utils/carried-segments', () => ( {
	getCarriedSegmentIds: jest.fn( () => [ '5' ] ),
} ) );

import { getBestPrioritySegment } from './utils';
import { handleSegmentation } from './segmentation';

const testSegments = { s1: {} };

/**
 * An overlay prompt that displays on a delay rather than immediately, so its
 * re-check in unhide() runs after a setTimeout — the only path this bug can
 * reach. (A non-overlay prompt calls unhide() synchronously and never
 * re-checks; see handleSegmentation()'s forEach.)
 *
 * @param {string} delay Milliseconds to delay, as the data-delay attribute expects.
 * @return {HTMLElement} Prompt element.
 */
const createDelayedOverlayPrompt = ( delay = '1000' ) => {
	const prompt = document.createElement( 'div' );
	prompt.setAttribute( 'id', 'id_1' );
	prompt.setAttribute( 'data-delay', delay );
	prompt.classList.add( 'newspack-lightbox', 'hidden' );
	return prompt;
};

/**
 * A minimal RAS stand-in whose 'reader' record is a single mutable object —
 * standing in for RAS's real store, where setAuthenticated() mutates the
 * same record callers already hold a reference to, with no reload.
 *
 * @param {boolean} authenticated Initial authenticated state.
 * @return {{ras: Object, reader: Object}} The mock RAS object and its mutable reader record.
 */
const createMockRas = authenticated => {
	const reader = { authenticated };
	const ras = {
		store: {
			get: key => ( 'reader' === key ? reader : undefined ),
		},
	};
	return { ras, reader };
};

describe( 'handleSegmentation authenticated gate on carried IDs', () => {
	beforeEach( () => {
		window.newspackRAS = [];
		window.newspack_popups_view = { segments: testSegments };
		getBestPrioritySegment.mockClear();
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 're-reads the authenticated flag when a delayed prompt re-checks, dropping carried IDs if RAS authenticated mid-delay', () => {
		const prompt = createDelayedOverlayPrompt( '1000' );
		handleSegmentation( [ prompt ] );

		// RAS becomes ready and pushes the reader in as logged out — the state
		// a still-anonymous visitor has when the prompt is first evaluated and
		// its display delayed.
		const { ras, reader } = createMockRas( false );
		const maybeDisplayPrompts = window.newspackRAS[ 0 ];
		maybeDisplayPrompts( ras );

		// Initial, synchronous evaluation: not yet authenticated, so the
		// carried snapshot from the newsletter click applies.
		expect( getBestPrioritySegment ).toHaveBeenLastCalledWith( testSegments, null, [ '5' ] );

		// RAS authenticates the reader mid-page — e.g. a magic-link or OTP
		// completion — with no reload, while the prompt is still pending its
		// delay.
		reader.authenticated = true;
		jest.advanceTimersByTime( 1000 );

		// The delayed re-check must reflect the reader's now-live authenticated
		// state and drop the carried snapshot, not reuse the frozen,
		// pre-authentication value.
		expect( getBestPrioritySegment ).toHaveBeenCalledTimes( 2 );
		expect( getBestPrioritySegment ).toHaveBeenLastCalledWith( testSegments, null, [] );
	} );
} );
