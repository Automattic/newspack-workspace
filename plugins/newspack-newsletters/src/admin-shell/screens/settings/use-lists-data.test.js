import { renderHook, act } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import useListsData from './use-lists-data';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const WARMING_HEADER = 'x-newspack-newsletters-lists-warming';

const makeResponse = ( body, warming ) => ( {
	ok: true,
	headers: {
		get: name => ( name === WARMING_HEADER && warming ? '1' : null ),
	},
	json: async () => body,
} );

const audience = { id: 'a-1', db_id: 1, name: 'Audience', type: 'mailchimp' };
const group = { id: 'g-1', db_id: 2, name: 'Group', type: 'mailchimp-group' };

describe( 'useListsData', () => {
	afterEach( () => {
		apiFetch.mockReset();
		jest.useRealTimers();
	} );

	it( 'polls while the warming header is present, then stops once complete', async () => {
		jest.useFakeTimers();
		apiFetch.mockResolvedValueOnce( makeResponse( [ audience ], true ) ).mockResolvedValueOnce( makeResponse( [ audience, group ], false ) );

		const { result } = renderHook( () => useListsData() );

		// Flush the initial load: audiences render immediately, a poll is queued.
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 0 );
		} );
		expect( result.current.lists ).toHaveLength( 1 );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack-newsletters/v1/lists',
			parse: false,
		} );

		// Advance to the poll: sublists arrive and polling stops.
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 3000 );
		} );
		expect( result.current.lists ).toHaveLength( 2 );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );

		// No further polling once complete.
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 10000 );
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'does not poll when the first response is already complete', async () => {
		jest.useFakeTimers();
		apiFetch.mockResolvedValueOnce( makeResponse( [ audience, group ], false ) );

		const { result } = renderHook( () => useListsData() );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 0 );
		} );
		expect( result.current.lists ).toHaveLength( 2 );

		await act( async () => {
			await jest.advanceTimersByTimeAsync( 30000 );
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops polling after the maximum number of attempts', async () => {
		jest.useFakeTimers();
		apiFetch.mockResolvedValue( makeResponse( [ audience ], true ) );

		renderHook( () => useListsData() );
		await act( async () => {
			await jest.advanceTimersByTimeAsync( 0 );
		} );

		// Drive well past the poll budget (10 polls * 3s).
		for ( let i = 0; i < 14; i++ ) {
			await act( async () => {
				await jest.advanceTimersByTimeAsync( 3000 );
			} );
		}

		// 1 initial + 10 polls = 11 calls, then it gives up.
		expect( apiFetch ).toHaveBeenCalledTimes( 11 );
	} );
} );
