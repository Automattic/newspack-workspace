// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';

const mockApiFetch = jest.fn();
const mockDataViewsProps = { current: null };

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: ( ...args ) => mockApiFetch( ...args ) } ) );

// One object for the life of the suite: the fetch callback closes over these,
// and a fresh object per render would re-run the fetch effect on every render.
jest.mock( '@wordpress/data', () => {
	const dispatch = { addNotice: jest.fn(), removeNotice: jest.fn() };
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

jest.mock( './log-details-modal', () => ( { LogDetailsModal: () => null } ) );

import { ScheduledActions } from './scheduled-actions';

describe( 'ScheduledActions', () => {
	beforeEach( () => {
		mockApiFetch.mockReset();
		mockDataViewsProps.current = null;
	} );

	it( 'lists the scheduled actions of the integration', async () => {
		const items = [
			{ id: 12, timestamp: '2026-09-10 10:00:00', event: 'Contact Sync Retry 2 of 5', status: 'complete', email: 'reader@example.test' },
		];
		mockApiFetch.mockResolvedValue( { items, total: 1 } );

		render( <ScheduledActions integrationId="sample" /> );

		await waitFor( () => expect( mockDataViewsProps.current ).not.toBeNull() );
		expect( mockApiFetch.mock.calls[ 0 ][ 0 ].path ).toContain( '/settings/sample/logs?' );
		expect( mockDataViewsProps.current.data ).toEqual( items );
	} );

	it( 'says a finished action ran, in the column and in the filter', async () => {
		mockApiFetch.mockResolvedValue( { items: [], total: 0 } );
		render( <ScheduledActions integrationId="sample" /> );
		await waitFor( () => expect( mockDataViewsProps.current ).not.toBeNull() );

		const statusField = mockDataViewsProps.current.fields.find( field => field.id === 'status' );
		expect( statusField.elements ).toContainEqual( { value: 'complete', label: 'Ran' } );

		render( statusField.render( { item: { status: 'complete' } } ) );
		expect( screen.getByText( 'Ran' ) ).toBeTruthy();
	} );

	it( 'offers to run only an action that is still pending', async () => {
		mockApiFetch.mockResolvedValue( { items: [], total: 0 } );
		render( <ScheduledActions integrationId="sample" /> );
		await waitFor( () => expect( mockDataViewsProps.current ).not.toBeNull() );

		const runNow = mockDataViewsProps.current.actions.find( action => action.id === 'run-now' );
		expect( runNow.isEligible( { id: 1, status: 'pending' } ) ).toBe( true );
		expect( runNow.isEligible( { id: 1, status: 'complete' } ) ).toBe( false );
	} );
} );
