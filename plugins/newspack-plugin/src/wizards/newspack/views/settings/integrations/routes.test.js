/**
 * Internal dependencies
 */
import { getLogsTabs, withLogsSection } from './routes';

describe( 'getLogsTabs', () => {
	it( 'routes each tab under the integration, sync activity first', () => {
		expect( getLogsTabs( { integrationId: 'sample' } ) ).toEqual( [
			{ label: 'Sync Activity', path: '/integrations/sample/logs', exact: true },
			{ label: 'Scheduled Actions', path: '/integrations/sample/logs/scheduled-actions', exact: true },
		] );
	} );
} );

describe( 'withLogsSection', () => {
	const render = () => null;
	const sections = [
		{ path: '/', label: 'Connections' },
		{ path: '/integrations', label: 'Integrations', activeTabPaths: [ '/integrations/*' ], render },
		{ path: '/social', label: 'Social' },
	];

	// The Wizard's Switch takes the first section whose path matches, and the
	// Integrations section matches every route under it.
	it( 'puts the Logs section ahead of Integrations, with the Logs tabs in place of the Settings tabs', () => {
		const result = withLogsSection( sections );

		expect( result.map( section => section.path ) ).toEqual( [ '/', '/integrations/:integrationId/logs/:tab?', '/integrations', '/social' ] );
		expect( result[ 1 ] ).toMatchObject( { isHidden: true, fullWidth: true, render, activeTabPaths: undefined } );
		expect( result[ 1 ].tabbedNavigation( { integrationId: 'esp' } ) ).toEqual( getLogsTabs( { integrationId: 'esp' } ) );
	} );

	it( 'leaves the sections alone when Integrations is not among them', () => {
		const withoutIntegrations = [ sections[ 0 ], sections[ 2 ] ];

		expect( withLogsSection( withoutIntegrations ) ).toBe( withoutIntegrations );
	} );
} );
