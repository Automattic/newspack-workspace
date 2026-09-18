// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const mockApiFetch = jest.fn();

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: ( ...args ) => mockApiFetch( ...args ) } ) );

jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	return {
		Spinner: () => 'Loading',
		Notice: ( { children } ) => React.createElement( 'div', { role: 'alert' }, children ),
		Button: ( { children, onClick } ) => React.createElement( 'button', { onClick }, children ),
	};
} );

jest.mock( '@wordpress/ui', () => {
	const React = require( 'react' );
	return { Badge: ( { children } ) => React.createElement( 'span', null, children ) };
} );

import { SyncActivityDetails } from './sync-activity-details';

const entry = overrides => ( {
	id: 57,
	email: 'reader@example.test',
	user_id: 12,
	operation: 'upsert',
	status: 'success',
	attempts: 1,
	max_attempts: 6,
	repeat_count: 0,
	context: 'Reader login',
	error_class: null,
	error_code: null,
	error_message: null,
	created_at: '2026-09-10 10:00:00',
	updated_at: '2026-09-10 10:00:00',
	retry: null,
	...overrides,
} );

const fields = [
	{ key: 'NP_Total Paid', label: 'Total Paid', before: '120', after: '180', changed: true, volatile: false },
	{ key: 'NP_Last Active', label: 'Last Active', before: '2026-09-03', after: '2026-09-10', changed: true, volatile: true },
	{ key: 'email', label: 'Email', before: 'reader@example.test', after: 'reader@example.test', changed: false, volatile: false },
	{ key: 'NP_Membership Status', label: 'Membership Status', before: 'active', after: 'active', changed: false, volatile: false },
];

const renderDetails = async response => {
	mockApiFetch.mockResolvedValue( response );
	render( <SyncActivityDetails integrationId="sample" entryId={ 57 } /> );
	await waitFor( () => expect( screen.queryByText( 'Loading' ) ).toBeNull() );
};

describe( 'SyncActivityDetails', () => {
	beforeEach( () => mockApiFetch.mockReset() );

	it( 'lists what changed since the previous push, and the rest on request', async () => {
		await renderDetails( { entry: entry(), compared_to: { id: 41, updated_at: '2026-09-03 10:42:00' }, fields } );

		expect( mockApiFetch ).toHaveBeenCalledWith( { path: expect.stringContaining( '/settings/sample/push-log/57' ) } );
		expect( screen.getByRole( 'heading', { name: /^Changed since / } ) ).toBeTruthy();
		expect( screen.getByText( 'Total Paid' ) ).toBeTruthy();
		expect( screen.getByText( '120' ) ).toBeTruthy();
		expect( screen.getByText( '180' ) ).toBeTruthy();
		expect( screen.getByText( 'Last Active' ) ).toBeTruthy();
		expect( screen.queryByText( 'Membership Status' ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Show all 4 fields sent' } ) );

		expect( screen.getByText( 'Membership Status' ) ).toBeTruthy();
		expect( screen.getByRole( 'button', { name: 'Show changed fields only' } ) ).toBeTruthy();
	} );

	it( 'says so when nothing changed', async () => {
		const unchanged = fields.map( field => ( { ...field, before: field.after, changed: false } ) );
		await renderDetails( { entry: entry(), compared_to: { id: 41, updated_at: '2026-09-03 10:42:00' }, fields: unchanged } );

		expect( screen.getByText( 'No fields changed.' ) ).toBeTruthy();
		expect( screen.getByRole( 'button', { name: 'Show all 4 fields sent' } ) ).toBeTruthy();
	} );

	it( 'lists every field, with nothing to compare, on a first push', async () => {
		const firstPush = fields.map( field => ( { ...field, before: null, changed: false } ) );
		await renderDetails( { entry: entry(), compared_to: null, fields: firstPush } );

		expect( screen.getByRole( 'heading', { name: 'Fields sent' } ) ).toBeTruthy();
		expect( screen.getByText( 'Membership Status' ) ).toBeTruthy();
		expect( screen.queryByRole( 'columnheader', { name: 'Before' } ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: /Show all/ } ) ).toBeNull();
	} );

	it( 'reads a failed push as data that did not arrive, with the error', async () => {
		await renderDetails( {
			entry: entry( { status: 'failed', attempts: 6, error_class: 'transient', error_code: 'provider_down', error_message: 'ESP 503' } ),
			compared_to: { id: 41, updated_at: '2026-09-03 10:42:00' },
			fields,
		} );

		expect( screen.getByRole( 'heading', { name: 'Not delivered' } ) ).toBeTruthy();
		expect( screen.getByRole( 'heading', { name: 'Error' } ) ).toBeTruthy();
		expect( screen.getByText( 'Temporary error' ) ).toBeTruthy();
		expect( screen.getByText( 'ESP 503' ) ).toBeTruthy();
		expect( screen.getByText( 'Retry 5 of 5' ) ).toBeTruthy();
	} );

	it( 'keeps the earlier error on a push that got through in the end', async () => {
		await renderDetails( {
			entry: entry( { attempts: 2, error_class: 'transient', error_code: 'provider_down', error_message: 'ESP 503' } ),
			compared_to: null,
			fields,
		} );

		expect( screen.getByRole( 'heading', { name: 'Earlier attempts failed with' } ) ).toBeTruthy();
	} );

	it( 'says when the next retry is due, and how often the same data was sent again', async () => {
		await renderDetails( {
			entry: entry( {
				status: 'retrying',
				attempts: 2,
				repeat_count: 0,
				retry: { action_id: 9001, is_pending: true, scheduled_at: '2026-09-10 10:02:30' },
			} ),
			compared_to: null,
			fields,
		} );
		expect( screen.getByText( 'Next retry' ) ).toBeTruthy();

		await renderDetails( { entry: entry( { repeat_count: 6 } ), compared_to: null, fields } );
		expect( screen.getByText( 'Sent 6 more times unchanged' ) ).toBeTruthy();
	} );

	it( 'explains a deletion that sent no data', async () => {
		await renderDetails( { entry: entry( { operation: 'delete' } ), compared_to: null, fields: [] } );

		expect( screen.getByText( 'The contact was deleted. No data was sent.' ) ).toBeTruthy();
	} );

	it( 'says so when the entry is gone', async () => {
		mockApiFetch.mockRejectedValue( { data: { status: 404 } } );
		render( <SyncActivityDetails integrationId="sample" entryId={ 57 } /> );

		expect( await screen.findByText( 'This entry no longer exists.' ) ).toBeTruthy();
	} );
} );
