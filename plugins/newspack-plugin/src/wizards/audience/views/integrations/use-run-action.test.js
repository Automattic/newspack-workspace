// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, act } from '@testing-library/react';

const mockApiFetch = jest.fn();
const mockAddNotice = jest.fn();
const mockRemoveNotice = jest.fn();

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: ( ...args ) => mockApiFetch( ...args ) } ) );

// One object for the life of the suite: the hook's callback closes over these,
// and a fresh object per render would give it a new identity on every render.
jest.mock( '@wordpress/data', () => {
	const dispatch = { addNotice: ( ...args ) => mockAddNotice( ...args ), removeNotice: ( ...args ) => mockRemoveNotice( ...args ) };
	return { useDispatch: () => dispatch };
} );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

import { useRunAction } from './use-run-action';

const hook = { current: null };

const Harness = ( { onSettled, completeNotice } ) => {
	hook.current = useRunAction( 'sample', { onSettled, completeNotice } );
	return null;
};

const runToCompletion = async ( response, props = {} ) => {
	mockApiFetch.mockResolvedValue( response );
	render( <Harness { ...props } /> );
	await act( async () => {
		await hook.current.runAction( 9001 );
	} );
};

const lastNotice = () => mockAddNotice.mock.calls[ mockAddNotice.mock.calls.length - 1 ][ 0 ];

describe( 'useRunAction', () => {
	beforeEach( () => {
		mockApiFetch.mockReset();
		mockAddNotice.mockReset();
		mockRemoveNotice.mockReset();
		hook.current = null;
	} );

	it( 'runs the action through the integration it belongs to', async () => {
		await runToCompletion( { status: 'complete', message: '' } );

		expect( mockApiFetch ).toHaveBeenCalledWith( { path: expect.stringContaining( '/settings/sample/logs/9001/run' ), method: 'POST' } );
	} );

	it( 'replaces the running notice rather than stacking one on top of it', async () => {
		await runToCompletion( { status: 'complete', message: '' } );

		expect( mockAddNotice ).toHaveBeenNthCalledWith(
			1,
			expect.objectContaining( { message: 'Running action…', id: 'integration-action-run-9001' } )
		);
		expect( mockRemoveNotice ).toHaveBeenCalledWith( 'integration-action-run-9001' );
		expect( lastNotice().id ).toBe( 'integration-action-run-9001' );
	} );

	it( 'reports an action that ran to the end in the caller’s own words', async () => {
		const completeNotice = { message: 'The retry ran.', type: 'info' };
		await runToCompletion( { status: 'complete', message: '' }, { completeNotice } );

		expect( lastNotice() ).toEqual( { ...completeNotice, id: 'integration-action-run-9001' } );
	} );

	it( 'reports a failed action as an error, in the message the run came back with', async () => {
		await runToCompletion( { status: 'failed', message: 'The callback threw.' } );

		expect( lastNotice() ).toEqual( expect.objectContaining( { type: 'error', message: 'The callback threw.' } ) );
	} );

	it( 'reports any other outcome as processed rather than as a success or a failure', async () => {
		await runToCompletion( { status: 'pending', message: 'Could not run; please refresh and try again.' } );

		expect( lastNotice() ).toEqual( expect.objectContaining( { type: 'success', message: 'Could not run; please refresh and try again.' } ) );
	} );

	it( 'reports a request that never arrived', async () => {
		mockApiFetch.mockRejectedValue( new Error( 'Network down.' ) );
		render( <Harness /> );
		await act( async () => {
			await hook.current.runAction( 9001 );
		} );

		expect( lastNotice() ).toEqual( expect.objectContaining( { type: 'error', message: 'Network down.' } ) );
	} );

	it( 'refreshes with the callback it was last given, not the one the request started with', async () => {
		// The view can change while the POST is in flight; refreshing through
		// the callback the run started with would fill the table with the
		// previous view's rows.
		const beforeTheChange = jest.fn();
		const afterTheChange = jest.fn();
		let completeThePost;
		mockApiFetch.mockReturnValue( new Promise( resolve => ( completeThePost = resolve ) ) );

		const { rerender } = render( <Harness onSettled={ beforeTheChange } /> );
		let pending;
		act( () => {
			pending = hook.current.runAction( 9001 );
		} );
		rerender( <Harness onSettled={ afterTheChange } /> );

		await act( async () => {
			completeThePost( { status: 'complete', message: '' } );
			await pending;
		} );

		expect( afterTheChange ).toHaveBeenCalled();
		expect( beforeTheChange ).not.toHaveBeenCalled();
	} );
} );
