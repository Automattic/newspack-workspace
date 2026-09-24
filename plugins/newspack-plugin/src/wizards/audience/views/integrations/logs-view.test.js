// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

const mockSetHeaderData = jest.fn();

jest.mock( '@wordpress/data', () => {
	const dispatch = { setHeaderData: ( ...args ) => mockSetHeaderData( ...args ) };
	return { useDispatch: () => dispatch };
} );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

jest.mock( '../../../../../packages/components/src/proxied-imports/router', () => ( {
	Redirect: ( { to } ) => `Redirect to ${ to }`,
} ) );

jest.mock( './sync-activity', () => ( { SyncActivity: ( { integrationId } ) => `Sync activity of ${ integrationId }` } ) );
jest.mock( './scheduled-actions', () => ( { ScheduledActions: ( { integrationId } ) => `Scheduled actions of ${ integrationId }` } ) );

import { LogsView, getLogsTabs } from './logs-view';

const integrations = { sample: { name: 'Sample' } };
const matchFor = tab => ( { params: { integrationId: 'sample', tab } } );

describe( 'LogsView', () => {
	it( 'opens on the sync activity', () => {
		render( <LogsView integrations={ integrations } match={ matchFor() } /> );

		expect( screen.getByText( 'Sync activity of sample' ) ).toBeTruthy();
		expect( screen.queryByText( 'Scheduled actions of sample' ) ).toBeNull();
	} );

	it( 'shows the scheduled actions on their route', () => {
		render( <LogsView integrations={ integrations } match={ matchFor( 'scheduled-actions' ) } /> );

		expect( screen.getByText( 'Scheduled actions of sample' ) ).toBeTruthy();
		expect( screen.queryByText( 'Sync activity of sample' ) ).toBeNull();
	} );

	it( 'sends an unknown tab back to the Logs route', () => {
		render( <LogsView integrations={ integrations } match={ matchFor( 'nope' ) } /> );

		expect( screen.getByText( 'Redirect to /settings/sample/logs' ) ).toBeTruthy();
	} );

	it( 'keeps the Logs breadcrumb under the integration', () => {
		render( <LogsView integrations={ integrations } match={ matchFor() } /> );

		expect( mockSetHeaderData ).toHaveBeenCalledWith(
			expect.objectContaining( { sectionName: [ { label: 'Sample', url: '#/settings/sample' }, { label: 'Logs' } ] } )
		);
	} );

	it( 'renders nothing for an integration it does not know', () => {
		const { container } = render( <LogsView integrations={ {} } match={ matchFor() } /> );

		expect( container.innerHTML ).toBe( '' );
	} );
} );

describe( 'getLogsTabs', () => {
	it( 'routes each tab under the integration, sync activity first', () => {
		expect( getLogsTabs( { integrationId: 'sample' } ) ).toEqual( [
			{ label: 'Sync Activity', path: '/settings/sample/logs', exact: true },
			{ label: 'Scheduled Actions', path: '/settings/sample/logs/scheduled-actions', exact: true },
		] );
	} );
} );
