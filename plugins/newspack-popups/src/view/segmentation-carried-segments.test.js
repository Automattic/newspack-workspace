/**
 * Carried-segments consumption in handleSegmentation: the authenticated gate
 * must be re-read on a delayed prompt's re-check, since RAS can authenticate a
 * reader mid-page without a reload. Segment matching and cookie parsing are
 * covered by segments.test.js and carried-segments.test.js; both modules are
 * mocked here.
 */

jest.mock( './utils', () => ( {
	debug: jest.fn(),
	closeOverlay: jest.fn(),
	getBestPrioritySegment: jest.fn( () => null ),
	getIntersectionObserver: jest.fn(),
	getRawId: jest.fn( () => 1 ),
	getOverride: jest.fn( () => null ),
	getAbOverride: jest.fn( () => null ),
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
 * A delayed overlay prompt — the only shape whose unhide() re-check runs after
 * a setTimeout.
 *
 * @param {string} delay Milliseconds, as the data-delay attribute expects.
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
 * A RAS stand-in whose reader record is mutable, like the real store.
 *
 * @param {boolean} authenticated Initial authenticated state.
 * @return {{ras: Object, reader: Object}} Mock RAS and its reader record.
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

	it( 'drops carried IDs from a delayed re-check when RAS authenticated mid-delay', () => {
		const prompt = createDelayedOverlayPrompt( '1000' );
		handleSegmentation( [ prompt ] );

		// Reader arrives logged out; carried IDs apply.
		const { ras, reader } = createMockRas( false );
		window.newspackRAS[ 0 ]( ras );
		expect( getBestPrioritySegment ).toHaveBeenLastCalledWith( testSegments, null, [ '5' ] );

		// Reader authenticates mid-delay; the re-check must see it.
		reader.authenticated = true;
		jest.advanceTimersByTime( 1000 );
		expect( getBestPrioritySegment ).toHaveBeenCalledTimes( 2 );
		expect( getBestPrioritySegment ).toHaveBeenLastCalledWith( testSegments, null, [] );
	} );
} );
