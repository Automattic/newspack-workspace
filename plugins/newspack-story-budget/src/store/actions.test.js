import { fetchStories, refreshStories } from './actions';

// The real control registers fetch middleware and reads a page global on import.
jest.mock( './controls', () => ( {
	apiFetch: request => ( { type: 'STORY_BUDGET_FETCH', request } ),
} ) );

const mockLastRefresh = 1792690000000;
jest.mock( '@wordpress/data', () => ( {
	select: () => ( { getLastRefresh: () => mockLastRefresh } ),
	resolveSelect: jest.fn(),
	dispatch: jest.fn(),
} ) );

const SERVER_TIME = 1792690060; // Seconds, as the API sends it.
const BROWSER_TIME = ( SERVER_TIME + 175 ) * 1000; // A browser clock running 175 s ahead.

/**
 * Step a generator action until it yields an action of the given type.
 *
 * @param {Generator} generator Generator action.
 * @param {string}    type      Action type to stop at.
 * @param {Object}    response  Value to resume the generator with after the API request.
 *
 * @return {Object} The matching action.
 */
const runUntil = ( generator, type, response ) => {
	let step = generator.next();
	while ( ! step.done ) {
		if ( step.value?.type === type ) {
			return step.value;
		}
		step = generator.next( step.value?.type === 'STORY_BUDGET_FETCH' ? response : undefined );
	}
	return null;
};

describe( 'refresh time', () => {
	beforeEach( () => {
		jest.spyOn( Date, 'now' ).mockReturnValue( BROWSER_TIME );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'refreshStories keeps the server time, not the browser clock, for the next refresh', () => {
		const action = runUntil( refreshStories( false ), 'REFRESH_SUCCESS', { stories: [], total: 0, timestamp: SERVER_TIME } );
		expect( action.payload.timestamp ).toBe( SERVER_TIME * 1000 );
	} );

	it( 'fetchStories keeps the server time, not the browser clock, for the next refresh', () => {
		const action = runUntil( fetchStories(), 'FETCH_SUCCESS', { stories: [], total: 0, timestamp: SERVER_TIME } );
		expect( action.payload.timestamp ).toBe( SERVER_TIME * 1000 );
	} );
} );
