// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, waitFor, act } from '@testing-library/react';

const mockApiFetch = jest.fn();
const mockAddNotice = jest.fn();
const mockDataViewsProps = { current: null };

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: ( ...args ) => mockApiFetch( ...args ) } ) );

// One object for the life of the suite: the fetch callback closes over these,
// and a fresh object per render would re-run the fetch effect on every render.
jest.mock( '@wordpress/data', () => {
	const dispatch = { addNotice: ( ...args ) => mockAddNotice( ...args ), removeNotice: jest.fn() };
	return { useDispatch: () => dispatch };
} );

jest.mock( '@wordpress/components', () => ( { Spinner: () => 'Loading' } ) );

jest.mock( '@wordpress/dataviews', () => {
	const Part = () => null;
	return { DataViews: { Search: Part, FiltersToggle: Part, FiltersToggled: Part, Layout: Part, Footer: Part } };
} );

// Captured rather than rendered: the real DataViews cannot load in this jsdom env.
jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		DataViews: props => {
			mockDataViewsProps.current = props;
			return React.createElement( 'div', null, 'DataViews' );
		},
		StatusIndicator: ( { children } ) => React.createElement( 'span', null, children ),
	};
} );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

jest.mock( './sync-activity-details', () => ( { SyncActivityDetails: () => null } ) );

import { SyncActivity } from './sync-activity';

const retryingItem = {
	id: 57,
	email: 'reader@example.test',
	user_id: 12,
	operation: 'upsert',
	status: 'retrying',
	attempts: 3,
	max_attempts: 6,
	repeat_count: 0,
	context: 'Reader login',
	error_class: 'transient',
	error_code: 'provider_down',
	error_message: 'ESP 503',
	created_at: '2026-09-10 10:00:00',
	updated_at: '2026-09-10 10:00:30',
	retry: { action_id: 9001, is_pending: true, scheduled_at: '2026-09-10 10:02:30' },
};

const renderLoaded = async ( response = { items: [ retryingItem ], total: 1, retention_days: { success: 30, failed: 90 } } ) => {
	mockApiFetch.mockResolvedValue( response );
	render( <SyncActivity integrationId="sample" /> );
	await waitFor( () => expect( mockDataViewsProps.current ).not.toBeNull() );
};

const lastPath = () => mockApiFetch.mock.calls[ mockApiFetch.mock.calls.length - 1 ][ 0 ].path;

describe( 'SyncActivity', () => {
	beforeEach( () => {
		mockApiFetch.mockReset();
		mockAddNotice.mockReset();
		mockDataViewsProps.current = null;
	} );

	it( 'opens on all activity, newest first', async () => {
		await renderLoaded();

		expect( lastPath() ).toContain( '/settings/sample/push-log?' );
		expect( lastPath() ).toContain( 'page=1' );
		expect( lastPath() ).toContain( 'order=DESC' );
		expect( lastPath() ).not.toContain( 'needs_attention' );
		expect( mockDataViewsProps.current.data ).toEqual( [ retryingItem ] );
		expect( mockDataViewsProps.current.paginationInfo ).toEqual( { totalItems: 1, totalPages: 1 } );
	} );

	it( 'shows the working columns and keeps the rest a click away', async () => {
		await renderLoaded();

		expect( mockDataViewsProps.current.view.fields ).toEqual( [ 'updated_at', 'email', 'operation', 'status' ] );
		const ids = mockDataViewsProps.current.fields.map( field => field.id );
		expect( ids ).toEqual( expect.arrayContaining( [ 'context', 'created_at', 'repeat_count', 'needs_attention' ] ) );
	} );

	it( 'sends the search, the filters and the page to the request', async () => {
		await renderLoaded();

		act( () => {
			mockDataViewsProps.current.onChangeView( {
				...mockDataViewsProps.current.view,
				page: 2,
				search: 'reader@example.test',
				filters: [
					{ field: 'status', operator: 'is', value: 'failed' },
					{ field: 'operation', operator: 'is', value: 'flag' },
					{ field: 'needs_attention', operator: 'is', value: 'yes' },
				],
			} );
		} );

		await waitFor( () => expect( lastPath() ).toContain( 'page=2' ) );
		expect( lastPath() ).toContain( 'search=reader%40example.test' );
		expect( lastPath() ).toContain( 'status=failed' );
		expect( lastPath() ).toContain( 'operation=flag' );
		expect( lastPath() ).toContain( 'needs_attention=true' );
	} );

	it( 'says which retry a row is waiting for, next to its status', async () => {
		await renderLoaded();

		const statusField = mockDataViewsProps.current.fields.find( field => field.id === 'status' );
		render( statusField.render( { item: retryingItem } ) );

		expect( screen.getByText( 'Retrying' ) ).toBeTruthy();
		// The same retry its pending scheduled action is titled with.
		expect( screen.getByText( 'Waiting for retry 3 of 5' ) ).toBeTruthy();
	} );

	it( 'offers to run a retry only while there is one to run', async () => {
		await renderLoaded();

		const runRetry = mockDataViewsProps.current.actions.find( action => action.id === 'run-retry' );
		expect( runRetry.isEligible( retryingItem ) ).toBe( true );
		expect( runRetry.isEligible( { ...retryingItem, retry: { ...retryingItem.retry, is_pending: false } } ) ).toBe( false );
		expect( runRetry.isEligible( { ...retryingItem, status: 'failed', retry: null } ) ).toBe( false );
	} );

	it( 'runs the retry through the scheduled action it points at', async () => {
		await renderLoaded();
		// The run answers with the action's status; the list reloads after it.
		mockApiFetch.mockImplementation( ( { method } ) =>
			Promise.resolve( method === 'POST' ? { status: 'complete', message: '' } : { items: [ retryingItem ], total: 1 } )
		);

		const runRetry = mockDataViewsProps.current.actions.find( action => action.id === 'run-retry' );
		await act( async () => {
			await runRetry.callback( [ retryingItem ] );
		} );

		expect( mockApiFetch ).toHaveBeenCalledWith( { path: expect.stringContaining( '/settings/sample/logs/9001/run' ), method: 'POST' } );
		// The action finishing does not mean the push worked, so the notice
		// points at the entry instead of calling it a success.
		expect( mockAddNotice ).toHaveBeenCalledWith(
			expect.objectContaining( { type: 'info', message: 'The retry ran. Its entry shows the result.' } )
		);
	} );

	it( 'says why the table is empty', async () => {
		await renderLoaded( { items: [], total: 0, retention_days: { success: 30, failed: 90 } } );

		render( mockDataViewsProps.current.empty );
		expect( screen.getByText( 'No pushes recorded yet.' ) ).toBeTruthy();
	} );

	it( 'names the windows the site keeps when a reader is not found', async () => {
		await renderLoaded( { items: [], total: 0, retention_days: { success: 7, failed: 14 } } );

		act( () => {
			mockDataViewsProps.current.onChangeView( { ...mockDataViewsProps.current.view, search: 'reader@example.test' } );
		} );
		await waitFor( () => expect( lastPath() ).toContain( 'search=' ) );

		render( mockDataViewsProps.current.empty );
		expect( screen.getByText( /7 days for the ones that worked, 14 for the ones that failed/ ) ).toBeTruthy();
	} );

	it( 'keeps a slow response from replacing a newer one', async () => {
		await renderLoaded();
		const deferred = () => {
			let settle;
			const promise = new Promise( resolve => ( settle = resolve ) );
			return { promise, settle };
		};
		const superseded = deferred();
		const newest = deferred();
		mockApiFetch.mockReturnValueOnce( superseded.promise ).mockReturnValueOnce( newest.promise );

		act( () => {
			mockDataViewsProps.current.onChangeView( { ...mockDataViewsProps.current.view, page: 2 } );
		} );
		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalledTimes( 2 ) );
		act( () => {
			mockDataViewsProps.current.onChangeView( { ...mockDataViewsProps.current.view, page: 3 } );
		} );
		await waitFor( () => expect( mockApiFetch ).toHaveBeenCalledTimes( 3 ) );

		const newestRow = { ...retryingItem, id: 99 };
		await act( async () => {
			newest.settle( { items: [ newestRow ], total: 1, retention_days: { success: 30, failed: 90 } } );
			await newest.promise;
		} );
		await act( async () => {
			superseded.settle( { items: [ retryingItem ], total: 5, retention_days: { success: 30, failed: 90 } } );
			await superseded.promise;
		} );

		expect( mockDataViewsProps.current.data ).toEqual( [ newestRow ] );
		expect( mockDataViewsProps.current.paginationInfo.totalItems ).toBe( 1 );
		expect( mockDataViewsProps.current.isLoading ).toBe( false );
	} );

	it( 'reports a load that failed', async () => {
		mockApiFetch.mockRejectedValue( new Error( 'nope' ) );
		render( <SyncActivity integrationId="sample" /> );

		await waitFor( () =>
			expect( mockAddNotice ).toHaveBeenCalledWith( expect.objectContaining( { type: 'error', id: 'integration-push-log-fetch-error' } ) )
		);
	} );

	it( 'does not read a failed load as a log with nothing in it', async () => {
		mockApiFetch.mockRejectedValue( new Error( 'nope' ) );
		render( <SyncActivity integrationId="sample" /> );
		await waitFor( () => expect( mockDataViewsProps.current ).not.toBeNull() );

		render( mockDataViewsProps.current.empty );
		expect( screen.getByText( 'The sync activity could not be loaded.' ) ).toBeTruthy();
		expect( screen.queryByText( 'No pushes recorded yet.' ) ).toBeNull();
	} );
} );
