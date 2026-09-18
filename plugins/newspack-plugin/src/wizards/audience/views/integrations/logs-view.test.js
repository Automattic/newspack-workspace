// @jest-environment jsdom

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

const mockSetHeaderData = jest.fn();

jest.mock( '@wordpress/data', () => {
	const dispatch = { setHeaderData: ( ...args ) => mockSetHeaderData( ...args ) };
	return { useDispatch: () => dispatch };
} );

jest.mock( '../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

// A small controlled tabs widget: the real one is Base UI, which this jsdom
// env does not need to exercise to check which tab's content is shown.
jest.mock( '@wordpress/ui', () => {
	const React = require( 'react' );
	const TabsContext = React.createContext( {} );
	const Root = ( { value, onValueChange, children } ) => React.createElement( TabsContext.Provider, { value: { value, onValueChange } }, children );
	const List = ( { children } ) => React.createElement( 'div', { role: 'tablist' }, children );
	const Tab = ( { value, children } ) => {
		const tabs = React.useContext( TabsContext );
		return React.createElement(
			'button',
			{ role: 'tab', 'aria-selected': tabs.value === value, onClick: () => tabs.onValueChange( value ) },
			children
		);
	};
	const Panel = ( { children } ) => React.createElement( 'div', { role: 'tabpanel' }, children );
	return { Tabs: { Root, List, Tab, Panel } };
} );

jest.mock( './sync-activity', () => ( { SyncActivity: ( { integrationId } ) => `Sync activity of ${ integrationId }` } ) );
jest.mock( './scheduled-actions', () => ( { ScheduledActions: ( { integrationId } ) => `Scheduled actions of ${ integrationId }` } ) );

import { LogsView } from './logs-view';

const integrations = { sample: { name: 'Sample' } };
const match = { params: { integrationId: 'sample' } };

describe( 'LogsView', () => {
	it( 'opens on the sync activity and leaves the scheduled actions unmounted', () => {
		render( <LogsView integrations={ integrations } match={ match } /> );

		expect( screen.getByRole( 'tab', { name: 'Sync activity' } ).getAttribute( 'aria-selected' ) ).toBe( 'true' );
		expect( screen.getByText( 'Sync activity of sample' ) ).toBeTruthy();
		expect( screen.queryByText( 'Scheduled actions of sample' ) ).toBeNull();
	} );

	it( 'switches to the scheduled actions', () => {
		render( <LogsView integrations={ integrations } match={ match } /> );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Scheduled actions' } ) );

		expect( screen.getByText( 'Scheduled actions of sample' ) ).toBeTruthy();
		expect( screen.queryByText( 'Sync activity of sample' ) ).toBeNull();
	} );

	it( 'keeps the Logs breadcrumb under the integration', () => {
		render( <LogsView integrations={ integrations } match={ match } /> );

		expect( mockSetHeaderData ).toHaveBeenCalledWith(
			expect.objectContaining( { sectionName: [ { label: 'Sample', url: '#/settings/sample' }, { label: 'Logs' } ] } )
		);
	} );

	it( 'renders nothing for an integration it does not know', () => {
		const { container } = render( <LogsView integrations={ {} } match={ match } /> );

		expect( container.innerHTML ).toBe( '' );
	} );
} );
