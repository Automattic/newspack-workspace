// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, waitFor, act, fireEvent } from '@testing-library/react';

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

// A passthrough: the real package does not load in this jsdom env, and the
// layout it brings is not what these tests are about.
jest.mock( '@wordpress/ui', () => {
	const React = require( 'react' );
	return { Stack: ( { children } ) => React.createElement( 'div', null, children ) };
} );

jest.mock( '@wordpress/dataviews', () => {
	const Part = () => null;
	return { DataViews: { Search: Part, Filters: Part, Layout: Part, Footer: Part } };
} );

// Captured rather than rendered: the real DataViews cannot load in this jsdom env.
jest.mock( '../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		DataViews: props => {
			mockDataViewsProps.current = props;
			// One row per item, carrying the first field's cell, so its details link can be tested.
			const rows = ( props.data || [] ).map( item =>
				React.createElement(
					'tr',
					{ key: item.id, className: 'dataviews-view-table__row' },
					React.createElement( 'td', null, props.fields[ 0 ].render( { item } ) )
				)
			);
			return React.createElement( 'table', null, React.createElement( 'tbody', null, rows ) );
		},
		StatusIndicator: ( { children } ) => React.createElement( 'span', null, children ),
		Drawer: {
			Root: ( { isOpen, children } ) => ( isOpen ? React.createElement( 'div', { role: 'dialog' }, children ) : null ),
			Header: ( { children } ) => React.createElement( 'div', null, children ),
			Title: ( { children } ) => React.createElement( 'h2', null, children ),
			CloseIcon: () => null,
			Content: ( { children } ) => React.createElement( 'div', null, children ),
		},
	};
} );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

jest.mock( './scheduled-action-details', () => ( { ScheduledActionDetails: ( { actionId } ) => `Details of ${ actionId }` } ) );

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

	it( 'opens the details in a drawer from the row link and from its action', async () => {
		mockApiFetch.mockResolvedValue( { items: [ { id: 12, timestamp: '2026-09-10 10:00:00', status: 'complete' } ], total: 1 } );
		render( <ScheduledActions integrationId="sample" /> );
		await waitFor( () => expect( mockDataViewsProps.current ).not.toBeNull() );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();

		fireEvent.click( document.querySelector( '.newspack-integration-logs__details-link' ) );
		expect( screen.getByRole( 'heading', { name: 'Action Details' } ) ).toBeTruthy();
		expect( screen.getByRole( 'dialog' ).textContent ).toContain( 'Details of 12' );

		const viewDetails = mockDataViewsProps.current.actions.find( action => action.id === 'view-details' );
		act( () => viewDetails.callback( [ { id: 34 } ] ) );
		expect( screen.getByRole( 'dialog' ).textContent ).toContain( 'Details of 34' );
	} );
} );
