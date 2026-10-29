/**
 * handleSegmentation after a mid-page sign-in: once the reader-activation
 * session hydrates the reader's server data, the match and the stored snapshot
 * are computed again, so a checkout opened right after sign-in prices against
 * this reader rather than against the anonymous evaluation made on page load.
 */

let mockSegment = 'anonymous-match';

jest.mock( './utils', () => {
	const actual = jest.requireActual( './utils' );
	return {
		...actual,
		debug: () => {},
		closeOverlay: () => {},
		handleSeen: () => {},
		getIntersectionObserver: () => ( { observe: () => {} } ),
		getBestPrioritySegment: () => mockSegment,
		syncMatchedSegments: jest.fn(),
		shouldPromptBeDisplayed: jest.fn( () => false ),
	};
} );

import { handleSegmentation } from './segmentation';
import { shouldPromptBeDisplayed, syncMatchedSegments } from './utils';

describe( 'handleSegmentation on session hydration', () => {
	let ras;
	let handlers;

	beforeEach( () => {
		handlers = {};
		ras = {
			segments: { register: jest.fn(), setMatch: jest.fn() },
			store: { get: () => undefined, set: () => {} },
			on: jest.fn( ( event, callback ) => {
				handlers[ event ] = callback;
			} ),
		};
		global.newspack_popups_view = { segments: { 'signed-in-match': { criteria: [], priority: 0 } } };
		window.newspackRAS = { push: callback => callback( ras ) };
		syncMatchedSegments.mockClear();
		shouldPromptBeDisplayed.mockClear();
		mockSegment = 'anonymous-match';
	} );

	afterEach( () => {
		delete window.newspackRAS;
	} );

	it( 'recomputes the match and the snapshot when the session hydrates', () => {
		handleSegmentation( [] );
		expect( ras.segments.setMatch ).toHaveBeenLastCalledWith( 'anonymous-match' );
		expect( syncMatchedSegments ).toHaveBeenCalledTimes( 1 );

		mockSegment = 'signed-in-match';
		expect( handlers.session ).toEqual( expect.any( Function ) );
		handlers.session();

		expect( ras.segments.setMatch ).toHaveBeenLastCalledWith( 'signed-in-match' );
		expect( syncMatchedSegments ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'keeps evaluating prompts on a reader activation that has no session event', () => {
		// newspack-plugin before 6.38.0 throws on an event name it does not know;
		// a version skew between the two plugins must not take prompts down.
		ras.on = jest.fn( () => {
			throw new Error( 'Invalid event' );
		} );
		const prompt = document.createElement( 'div' );
		prompt.setAttribute( 'id', 'id_1' );

		expect( () => handleSegmentation( [ prompt ] ) ).not.toThrow();

		expect( shouldPromptBeDisplayed ).toHaveBeenCalledTimes( 1 );
		expect( shouldPromptBeDisplayed.mock.calls[ 0 ][ 0 ] ).toBe( prompt );
	} );
} );
