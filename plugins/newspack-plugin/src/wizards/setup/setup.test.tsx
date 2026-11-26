import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import { SetupWizard } from './index';
import { MIN_ROW_MS } from './views/start/use-install';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'canvas-confetti', () => jest.fn() );
jest.mock( '../newspack/views/settings/theme-and-brand/theme-select', () => () => null );

const PROFILE_RESPONSE = {
	profile: { site_icon: false, site_title: 'My Site', tagline: '', countrystate: 'GB', currency: 'GBP' },
	countries: [ { value: 'GB', label: 'United Kingdom (UK)' } ],
	currencies: [ { value: 'GBP', label: 'Pound sterling' } ],
	wpseo_fields: [],
	location_hints: { site_locale: 'en_GB', country_currencies: { GB: 'GBP' } },
};

const mockInitialCheck = ( complete: boolean ) => {
	const initialCheck = {
		plugins: [],
		is_ssl: true,
		install_state: { approach: complete ? 'generated' : null, complete },
	};
	( apiFetch as unknown as jest.Mock ).mockImplementation( ( { path, method }: { path: string; method?: string } ) => {
		if ( path === '/newspack/v1/profile/' ) {
			return Promise.resolve( method === 'POST' ? {} : PROFILE_RESPONSE );
		}
		return Promise.resolve( initialCheck );
	} );
};

const stepper = async () => within( await screen.findByRole( 'navigation', { name: 'Setup steps' } ) );

describe( 'Setup wizard shell', () => {
	beforeEach( () => {
		window.location.hash = '';
		window.scrollTo = jest.fn();
		delete window.newspack_aux_data;
	} );

	it( 'renders the Newspack mark and the four steps in the header', async () => {
		mockInitialCheck( false );
		render( <SetupWizard /> );
		const nav = await stepper();
		const items = nav.getAllByRole( 'listitem' );
		expect( items.map( item => item.textContent ) ).toEqual( [ '1Get started', '2Site details', '3Design', '4Done' ] );
		expect( screen.getByRole( 'heading', { level: 1, name: 'Newspack setup' } ) ).toBeTruthy();
		expect( document.querySelector( '.setup-header__mark svg' ) ).toBeTruthy();
		expect( screen.queryByRole( 'main' ) ).toBeNull();
	} );

	it( 'redirects to Get started until the install has finished', async () => {
		mockInitialCheck( false );
		window.location.hash = '#/design';
		render( <SetupWizard /> );
		const nav = await stepper();
		expect( nav.getByText( 'Get started' ).closest( '[aria-current="step"]' ) ).toBeTruthy();
		expect( window.location.hash ).toBe( '#/' );
	} );

	it( 'keeps a later step on reload once the install has finished', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/design';
		render( <SetupWizard /> );
		const nav = await stepper();
		expect( nav.getByText( 'Design' ).closest( '[aria-current="step"]' ) ).toBeTruthy();
	} );

	it( 'never links back to Get started once the install has finished', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/design';
		render( <SetupWizard /> );
		const nav = await stepper();
		expect( nav.getByRole( 'button', { name: /Site details/ } ) ).toBeTruthy();
		expect( nav.queryByRole( 'button', { name: /Get started/ } ) ).toBeNull();
	} );

	it( 'tells assistive tech which steps are completed', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/design';
		render( <SetupWizard /> );
		const nav = await stepper();
		const [ start, details, design ] = nav.getAllByRole( 'listitem' );
		expect( start ).toHaveTextContent( 'Get started (completed)' );
		expect( details ).toHaveTextContent( 'Site details (completed)' );
		expect( design ).not.toHaveTextContent( '(completed)' );
	} );

	it.each( [
		[ 'Get started', false, '#/' ],
		[ 'Site details', true, '#/site-details' ],
		[ 'Design', true, '#/design' ],
	] )( 'renders the current step, %s, as plain text rather than a button', async ( label, complete, hash ) => {
		mockInitialCheck( complete );
		window.location.hash = hash;
		render( <SetupWizard /> );
		const nav = await stepper();
		const current = nav.getByText( label ).closest( '[aria-current="step"]' );
		expect( current?.tagName ).toBe( 'SPAN' );
		expect( current ).not.toHaveClass( 'setup-stepper__link' );
		expect( nav.queryByRole( 'button', { name: new RegExp( label ) } ) ).toBeNull();
	} );

	it( 'renders steps not reached yet as plain text', async () => {
		mockInitialCheck( false );
		render( <SetupWizard /> );
		const nav = await stepper();
		expect( nav.queryAllByRole( 'button' ) ).toHaveLength( 0 );
		expect( nav.getByText( 'Done' ).closest( '.setup-stepper__step' )?.tagName ).toBe( 'SPAN' );
	} );

	it( 'redirects Get started to Site details once the install has finished', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/';
		render( <SetupWizard /> );
		const nav = await stepper();
		expect( nav.getByText( 'Site details' ).closest( '[aria-current="step"]' ) ).toBeTruthy();
		expect( window.location.hash ).toBe( '#/site-details' );
	} );

	it( 'links only to the steps already reached', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/site-details';
		render( <SetupWizard /> );
		const nav = await stepper();
		expect( nav.getByText( 'Site details' ).closest( '[aria-current="step"]' ) ).toBeTruthy();
		expect( nav.queryByRole( 'button', { name: /Design/ } ) ).toBeNull();
	} );

	it( 'links back to Design once Site details was saved', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/site-details';
		render( <SetupWizard /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( window.location.hash ).toBe( '#/design' ) );
		fireEvent.click( ( await stepper() ).getByRole( 'button', { name: /Site details/ } ) );
		await waitFor( () => expect( window.location.hash ).toBe( '#/site-details' ) );
		expect( ( await stepper() ).getByRole( 'button', { name: /Design/ } ) ).toBeTruthy();
	} );

	it( 'asks before the stepper leaves Site details with unsaved edits', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/site-details';
		render( <SetupWizard /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( window.location.hash ).toBe( '#/design' ) );
		fireEvent.click( ( await stepper() ).getByRole( 'button', { name: /Site details/ } ) );
		fireEvent.change( await screen.findByRole( 'textbox', { name: 'Site title' } ), { target: { value: 'Renamed' } } );

		fireEvent.click( ( await stepper() ).getByRole( 'button', { name: /Design/ } ) );
		expect( await screen.findByText( 'You have unsaved changes that will be lost. Discard changes?' ) ).toBeInTheDocument();
		expect( window.location.hash ).toBe( '#/site-details' );
		expect( screen.getByLabelText( 'Site title' ) ).toHaveValue( 'Renamed' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Discard Changes' } ) );
		await waitFor( () => expect( window.location.hash ).toBe( '#/design' ) );
	} );

	it( 'asks before the stepper leaves Design with unsaved edits, and stays on Cancel', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/design';
		render( <SetupWizard /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Footer' } ) );
		fireEvent.change( await screen.findByRole( 'textbox', { name: 'Copyright information' } ), { target: { value: '© Us' } } );

		fireEvent.click( ( await stepper() ).getByRole( 'button', { name: /Site details/ } ) );
		expect( await screen.findByText( 'You have unsaved changes that will be lost. Discard changes?' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByText( 'Cancel', { selector: 'button' } ) );
		await waitFor( () => expect( screen.queryByText( 'You have unsaved changes that will be lost. Discard changes?' ) ).toBeNull() );
		expect( window.location.hash ).toBe( '#/design' );
		expect( screen.getByLabelText( 'Copyright information' ) ).toHaveValue( '© Us' );
	} );

	it( 'lets the stepper leave a step without edits straight away', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/design';
		render( <SetupWizard /> );
		await screen.findByRole( 'button', { name: 'Footer' } );
		fireEvent.click( ( await stepper() ).getByRole( 'button', { name: /Site details/ } ) );
		await waitFor( () => expect( window.location.hash ).toBe( '#/site-details' ) );
		expect( screen.queryByText( 'You have unsaved changes that will be lost. Discard changes?' ) ).toBeNull();
	} );

	it( 'sends Done back to Design until the setup was completed', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/done';
		render( <SetupWizard /> );
		const nav = await stepper();
		expect( nav.getByText( 'Design' ).closest( '[aria-current="step"]' ) ).toBeTruthy();
		expect( window.location.hash ).toBe( '#/design' );
	} );

	it( 'opens Done directly once the setup was completed before', async () => {
		window.newspack_aux_data = { has_completed_setup: '1' };
		mockInitialCheck( true );
		window.location.hash = '#/done';
		render( <SetupWizard /> );
		expect( await screen.findByRole( 'heading', { name: 'Your site is set up' } ) ).toBeTruthy();
		expect( window.location.hash ).toBe( '#/done' );
	} );

	it( 'scrolls to the top and focuses the new step heading after Continue', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/site-details';
		render( <SetupWizard /> );
		await screen.findByRole( 'combobox', { name: 'Country' } );
		expect( screen.getByRole( 'heading', { level: 2, name: 'Site details' } ) ).not.toHaveFocus();
		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( screen.getByRole( 'heading', { level: 2, name: 'Design' } ) ).toHaveFocus() );
		expect( window.scrollTo ).toHaveBeenCalledWith( 0, 0 );
	} );

	it( 'keeps focus on the Site details heading once its profile loads after Get started', async () => {
		jest.useFakeTimers();
		let loadProfile: ( profile: typeof PROFILE_RESPONSE ) => void = () => {};
		( apiFetch as unknown as jest.Mock ).mockImplementation( ( { path }: { path: string } ) => {
			if ( path === '/newspack/v1/profile/' ) {
				return new Promise( resolve => {
					loadProfile = resolve;
				} );
			}
			return Promise.resolve( { plugins: [], is_ssl: true, install_state: { approach: null, complete: false } } );
		} );
		try {
			render( <SetupWizard /> );
			fireEvent.click( await screen.findByRole( 'checkbox', { name: /Add sample stories/ } ) );
			fireEvent.click( screen.getByRole( 'button', { name: 'Install' } ) );
			await act( () => jest.advanceTimersByTimeAsync( 3 * MIN_ROW_MS ) );
			fireEvent.click( screen.getByRole( 'button', { name: /Continue/ } ) );
			await act( () => jest.advanceTimersByTimeAsync( 0 ) );
			expect( screen.getByRole( 'heading', { level: 2, name: 'Site details' } ) ).toHaveFocus();

			await act( async () => loadProfile( PROFILE_RESPONSE ) );
			await screen.findByRole( 'combobox', { name: 'Country' } );
			expect( screen.getByRole( 'heading', { level: 2, name: 'Site details' } ) ).toHaveFocus();
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'Skip for Now on Design completes setup and shows Done', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/design';
		render( <SetupWizard /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Skip for Now' } ) );
		expect( await screen.findByRole( 'heading', { name: 'Your site is set up' } ) ).toBeTruthy();
		expect( window.location.hash ).toBe( '#/done' );
	} );

	it( 'announces a setup that fails to load politely', async () => {
		( apiFetch as unknown as jest.Mock ).mockRejectedValue( new Error( 'down' ) );
		render( <SetupWizard /> );
		await screen.findByText( 'Could not load the setup. Reload the page to try again.', { ignore: '.a11y-speak-region' } );
		await waitFor( () => expect( document.getElementById( 'a11y-speak-polite' )?.textContent ).toContain( 'Could not load the setup.' ) );
		expect( document.getElementById( 'a11y-speak-assertive' )?.textContent ?? '' ).not.toContain( 'Could not load the setup.' );
	} );

	it( 'has no Back button on Design', async () => {
		mockInitialCheck( true );
		window.location.hash = '#/design';
		render( <SetupWizard /> );
		await screen.findByRole( 'button', { name: 'Finish' } );
		expect( screen.queryByRole( 'button', { name: 'Back' } ) ).toBeNull();
	} );
} );
