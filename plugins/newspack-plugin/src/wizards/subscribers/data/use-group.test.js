/**
 * The write conventions use-group.js declares for the rest of the wizard.
 *
 * Two of the three are behavioural and can drift silently: a rejection must reach
 * the caller carrying the server's own sentence (the endpoint knows why it said
 * no; a generic string throws that away), and resolveReaderId must answer "not a
 * reader we can add" for anything short of an exact address match — that answer is
 * the switch AddMembersFlow uses to invite instead of adding.
 */

/**
 * External dependencies
 */
import { renderHook, act, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { useGroup, useGroupActions } from './use-group';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

beforeEach( () => apiFetch.mockReset() );

describe( 'useGroup', () => {
	it( "reports the server's message rather than collapsing a failed read into 'not found'", async () => {
		apiFetch.mockRejectedValue( { message: 'That group could not be found.' } );
		const { result } = renderHook( () => useGroup( 7 ) );

		await waitFor( () => expect( result.current.loading ).toBe( false ) );
		expect( result.current.group ).toBeNull();
		expect( result.current.error ).toBe( 'That group could not be found.' );
	} );

	it( 'refetches on reload, so a completed write re-renders from what the server stored', async () => {
		apiFetch.mockResolvedValueOnce( { id: 7, seatLimit: 5 } ).mockResolvedValueOnce( { id: 7, seatLimit: 8 } );
		const { result } = renderHook( () => useGroup( 7 ) );

		await waitFor( () => expect( result.current.group ).toEqual( { id: 7, seatLimit: 5 } ) );
		act( () => result.current.reload() );
		await waitFor( () => expect( result.current.group ).toEqual( { id: 7, seatLimit: 8 } ) );
	} );
} );

describe( 'useGroupActions', () => {
	it( "rejects with the server's own message, not a substituted failure string", async () => {
		apiFetch.mockRejectedValue( { code: 'newspack_group_subscription_not_manageable', message: 'This group is no longer active.' } );
		const { result } = renderHook( () => useGroupActions( 7 ) );

		await expect( result.current.setManagerRole( 42, 'manager' ) ).rejects.toMatchObject( {
			message: 'This group is no longer active.',
		} );
	} );

	it( 'resolves an address to its reader id, and to null when the picker has no exact match', async () => {
		// /search-users returns `email (#id)`, matches the term loosely, and excludes
		// people already in the group — so a near miss must not be read as a hit.
		apiFetch.mockResolvedValue( [
			{ id: 11, text: 'not-mel@example.com (#11)' },
			{ id: 12, text: 'mel@example.com (#12)' },
		] );
		const { result } = renderHook( () => useGroupActions( 7 ) );

		await expect( result.current.resolveReaderId( 'MEL@example.com' ) ).resolves.toBe( 12 );
		await expect( result.current.resolveReaderId( 'nobody@example.com' ) ).resolves.toBeNull();
	} );
} );
