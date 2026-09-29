/**
 * Direct coverage of the A/B + override composition in handleSegmentation.
 *
 * The POC bug this feature replaces was positional: a variant the reader is not
 * assigned to still claimed the single-overlay slot, so a later legitimate overlay
 * never showed. getAbOverride() unit tests cannot catch that -- they prove the
 * override value, not what handleSegmentation does with it -- so this exercises
 * `getOverride( ... ) ?? getAbOverride( prompt )` through the real loop.
 */

// Real getOverride / getAbOverride / getRawId: they are the composition under test.
// Everything else is stubbed so display turns only on the override.
jest.mock( './utils', () => {
	const actual = jest.requireActual( './utils' );
	return {
		...actual,
		debug: () => {},
		closeOverlay: () => {},
		handleSeen: () => {},
		getIntersectionObserver: () => ( { observe: () => {} } ),
		getBestPrioritySegment: () => null,
		shouldPromptBeDisplayed: ( prompt, segment, ras, override ) => override ?? true,
	};
} );

import { handleSegmentation } from './segmentation';
import { computeBucket } from './utils';

const READER_ID = 'reader-fixture-1';
const TEST_ID = 'slot-test';
const CONFIG = { variants: [ 'a', 'b' ], control_share: 50 };

const makePrompt = ( { overlay = false, variant = null } = {} ) => {
	const prompt = document.createElement( 'div' );
	prompt.setAttribute( 'id', `id_${ Math.floor( Math.random() * 1e6 ) }` );
	prompt.classList.add( 'hidden' );
	if ( overlay ) {
		prompt.classList.add( 'newspack-lightbox' );
	}
	if ( variant ) {
		prompt.setAttribute( 'data-ab-test-id', TEST_ID );
		prompt.setAttribute( 'data-ab-variant', variant );
	}
	return prompt;
};

const isVisible = prompt => ! prompt.classList.contains( 'hidden' );

describe( 'handleSegmentation A/B composition', () => {
	let assigned;
	let unassigned;

	beforeEach( () => {
		jest.useFakeTimers();
		global.newspack_popups_view = { ab_tests: { [ TEST_ID ]: CONFIG }, cid_cookie: 'newspack-cid' };
		document.cookie = `newspack-cid=${ READER_ID }`;
		document.body.className = '';
		// Derive the arm from the real hash rather than hard-coding it, so the test
		// keeps testing suppression if the bucketing math is ever retuned.
		assigned = computeBucket( READER_ID, TEST_ID, CONFIG );
		unassigned = 'a' === assigned ? 'b' : 'a';
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'does not let a suppressed overlay variant claim the single-overlay slot', () => {
		const suppressed = makePrompt( { overlay: true, variant: unassigned } );
		const legitimate = makePrompt( { overlay: true } );

		handleSegmentation( [ suppressed, legitimate ] );
		jest.runAllTimers();

		expect( isVisible( suppressed ) ).toBe( false );
		expect( isVisible( legitimate ) ).toBe( true );
	} );

	it( 'displays only the assigned arm when both inline variants are present', () => {
		const armA = makePrompt( { variant: assigned } );
		const armB = makePrompt( { variant: unassigned } );

		handleSegmentation( [ armA, armB ] );
		jest.runAllTimers();

		expect( isVisible( armA ) ).toBe( true );
		expect( isVisible( armB ) ).toBe( false );
	} );

	it( 'still enforces one overlay at a time among assigned prompts', () => {
		const first = makePrompt( { overlay: true, variant: assigned } );
		const second = makePrompt( { overlay: true } );

		handleSegmentation( [ first, second ] );
		jest.runAllTimers();

		expect( isVisible( first ) ).toBe( true );
		expect( isVisible( second ) ).toBe( false );
	} );
} );
