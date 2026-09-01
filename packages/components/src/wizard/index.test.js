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

// Both globals are localized onto every real wizard screen. The footer's
// ExternalLink needs a string href, and the debug Notice reads aux data.
window.newspack_aux_data = { is_debug_mode: false };
window.newspack_urls = { support: 'https://help.newspack.com/' };

const SETTINGS = { minimumDonation: '5' };

// Stands in for a wizard section that can only render once its API data has
// arrived — the shape a wizard section has when the wizard owns an apiSlug.
const Section = ( { slug } ) => {
	const wizardData = useWizardData( slug );
	return <div>{ wizardData.settings ? 'Settings form' : null }</div>;
};

// The other shape: a section on a wizard with no apiSlug, which reads page-load
// globals rather than wizard API data and so never touches the store.
const StaticSection = () => <div>Static section</div>;

// Mocks the endpoints a wizard hits, gated on whether the required plugin is
// active: the wizard's own endpoint 400s while the plugin is missing, exactly as
// Audience_Donations::api_get_donation_settings() does without WooCommerce.
// Counts every wizard-endpoint GET, whatever the slug, so a test can assert that
// a wizard given no apiSlug fetches nothing at all.
const mockEndpoints = ( slug, initialStatus = 'inactive' ) => {
	const counts = { wizard: 0 };
	let pluginStatus = initialStatus;
	const plugin = () => ( { Name: 'WooCommerce', Description: 'Store', Status: pluginStatus, Download: 'wporg' } );
	apiFetch.mockImplementation( ( { path, method } ) => {
		if ( path.startsWith( '/newspack/v1/wizard/' ) && 'POST' !== method ) {
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

const renderWizard = ( slug, { withApiSlug = true } = {} ) =>
	render(
		<Wizard
			headerText="Test wizard"
			apiSlug={ withApiSlug ? slug : undefined }
			requiredPlugins={ [ 'woocommerce' ] }
			sections={ [
				{
					label: 'Configuration',
					path: '/configuration',
					render: () => ( withApiSlug ? <Section slug={ slug } /> : <StaticSection /> ),
				},
			] }
		/>
	);

// The plugin's own row button, not the footer's activate-all — both read
// "Activate", and the row button comes first in the DOM.
const clickActivate = async () => {
	const [ activate ] = await screen.findAllByRole( 'button', { name: 'Activate' } );
	fireEvent.click( activate );
};

// Lets every already-queued promise settle, so an assertion that something did
// NOT happen has actually given it the chance to.
const flushPending = () => act( () => new Promise( resolve => setTimeout( resolve, 0 ) ) );

describe( 'Wizard', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'refetches its API data after the installer satisfies the plugin requirements', async () => {
		// A distinct slug per test: the store's resolution cache lives in the
		// module registry, so it is shared by every test in this file.
		const slug = 'test-wizard-refetch';
		const counts = mockEndpoints( slug );

		renderWizard( slug );

		// The first fetch runs before the plugin exists, so it fails. Until the
		// installer is satisfied it owns the whole route, so the installer screen
		// showing is what proves we are in the pre-activation state.
		await waitFor( () => expect( counts.wizard ).toBe( 1 ) );
		expect( await screen.findByText( 'WooCommerce' ) ).toBeInTheDocument();

		await clickActivate();

		// Activation makes the endpoint answerable, so the section must render
		// against fresh data rather than the empty pre-activation response.
		expect( await screen.findByText( 'Settings form' ) ).toBeInTheDocument();

		// Exactly one refetch. The installer re-reports its status on every
		// re-render while it is mounted, so this is the assertion that would
		// catch a latch that re-fired.
		await flushPending();
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

	it( 'fetches nothing for a wizard that has required plugins but no API slug', async () => {
		// The shape of every other Newspack wizard that gates on plugins: it
		// reads page-load globals rather than wizard API data. This change has to
		// stay inert for them. Note this pins the inertness, not the `apiSlug`
		// guard itself — the resolver already no-ops on an undefined slug, so
		// that guard is a second line of defence rather than the load-bearing one.
		const slug = 'test-wizard-no-api-slug';
		const counts = mockEndpoints( slug );

		renderWizard( slug, { withApiSlug: false } );

		await clickActivate();

		// The installer still completes and hands the route back to the section.
		expect( await screen.findByText( 'Static section' ) ).toBeInTheDocument();
		await flushPending();
		expect( counts.wizard ).toBe( 0 );
	} );
} );
