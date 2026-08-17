/**
 * External dependencies.
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies.
 */
import Wizard from './';
import { useWizardData } from './store/utils';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

global.newspack_aux_data = { is_debug_mode: false };
// The wizard footer links out to the docs; both globals are localized onto every
// real wizard screen, and the footer's ExternalLink needs a string href.
window.newspack_urls = { support: 'https://help.newspack.com/' };

const SETTINGS = { minimumDonation: '5' };

// Stands in for a wizard section that can only render once its API data has
// arrived — the shape every Newspack wizard section has.
const Section = ( { slug } ) => {
	const wizardData = useWizardData( slug );
	return <div>{ wizardData.settings ? 'Settings form' : null }</div>;
};

// Mocks the endpoints a wizard hits, gated on whether the required plugin is
// active: the wizard's own endpoint 400s while the plugin is missing, exactly as
// Audience_Donations::api_get_donation_settings() does without WooCommerce.
// Returns a counter so a test can assert how many times the wizard refetched.
const mockEndpoints = ( slug, initialStatus = 'inactive' ) => {
	const counts = { wizard: 0 };
	let pluginStatus = initialStatus;
	const plugin = () => ( { Name: 'WooCommerce', Description: 'Store', Status: pluginStatus, Download: 'wporg' } );
	apiFetch.mockImplementation( ( { path, method } ) => {
		if ( path === `/newspack/v1/wizard/${ slug }` && 'POST' !== method ) {
			counts.wizard++;
			return 'active' === pluginStatus
				? Promise.resolve( { settings: SETTINGS } )
				: Promise.reject( {
						code: 'newspack_missing_required_plugin',
						message: 'The WooCommerce plugin is not installed and activated.',
				  } );
		}
		if ( path === '/newspack/v1/plugins/' ) {
			return Promise.resolve( { woocommerce: plugin() } );
		}
		if ( path === '/newspack/v1/plugins/woocommerce/configure/' ) {
			pluginStatus = 'active';
			return Promise.resolve( plugin() );
		}
		return Promise.resolve( {} );
	} );
	return counts;
};

const renderWizard = slug =>
	render(
		<Wizard
			headerText="Test wizard"
			apiSlug={ slug }
			requiredPlugins={ [ 'woocommerce' ] }
			sections={ [ { label: 'Configuration', path: '/configuration', render: () => <Section slug={ slug } /> } ] }
		/>
	);

// Lets every already-queued promise settle, so an assertion that something did
// NOT happen has actually given it the chance to.
const flushPending = () => act( () => new Promise( resolve => setTimeout( resolve, 0 ) ) );

describe( 'Wizard', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'refetches its API data after the installer satisfies the plugin requirements', async () => {
		// A distinct slug per test: the store's resolver cache is process-wide.
		const slug = 'test-wizard-refetch';
		const counts = mockEndpoints( slug );

		renderWizard( slug );

		// The first fetch runs before the plugin exists, so it fails.
		await waitFor( () => expect( counts.wizard ).toBe( 1 ) );
		expect( screen.queryByText( 'Settings form' ) ).not.toBeInTheDocument();

		// Two buttons read "Activate": the plugin's own row and the footer's
		// activate-all. The row button comes first in the DOM.
		const [ activate ] = await screen.findAllByRole( 'button', { name: 'Activate' } );
		fireEvent.click( activate );

		// Activation makes the endpoint answerable, so the section must render
		// against fresh data rather than the empty pre-activation response.
		expect( await screen.findByText( 'Settings form' ) ).toBeInTheDocument();
		expect( counts.wizard ).toBe( 2 );
	} );

	it( 'does not refetch when the required plugins were already active', async () => {
		const slug = 'test-wizard-no-refetch';
		const counts = mockEndpoints( slug, 'active' );

		renderWizard( slug );

		expect( await screen.findByText( 'Settings form' ) ).toBeInTheDocument();
		await flushPending();
		expect( counts.wizard ).toBe( 1 );
	} );
} );
