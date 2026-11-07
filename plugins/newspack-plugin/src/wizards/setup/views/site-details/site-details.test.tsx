import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import SiteDetails from './index';
import type { SetupViewProps } from '../../steps';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const PROFILE = '/newspack/v1/profile/';

const countries = [
	{ value: null, label: '-- Select --' },
	{ value: 'GB', label: 'United Kingdom (UK)' },
	{ value: 'US', label: 'United States (US)' },
	{ value: 'AQ', label: 'Antarctica' },
];
const currencies = [
	{ value: null, label: '-- Select --' },
	{ value: 'GBP', label: 'Pound sterling' },
	{ value: 'USD', label: 'United States (US) dollar' },
];

const response = ( overrides: { profile?: Record< string, unknown >; importedCurrency?: string } = {} ) => ( {
	profile: {
		site_icon: false,
		site_title: 'My Site',
		tagline: '',
		countrystate: '',
		currency: '',
		facebook_site: '',
		twitter_site: '',
		...overrides.profile,
	},
	countries,
	currencies,
	wpseo_fields: [],
	location_hints: {
		site_locale: 'en_US',
		country_currencies: { GB: 'GBP', US: 'USD' },
		imported_currency: overrides.importedCurrency ?? '',
	},
} );

const mockProfile = ( data: unknown, save: () => Promise< unknown > = () => Promise.resolve( {} ) ) =>
	( apiFetch as unknown as jest.Mock ).mockImplementation( ( { method }: { method?: string } ) =>
		method === 'POST' ? save() : Promise.resolve( data )
	);

const renderStep = ( props: Partial< SetupViewProps > = {} ) => {
	const view: SetupViewProps = {
		initialCheck: { plugins: [], is_ssl: true },
		installState: { approach: 'generated', complete: true },
		setInstallState: jest.fn(),
		goTo: jest.fn(),
		completeSetup: jest.fn(),
		...props,
	};
	render( <SiteDetails { ...view } /> );
	return view;
};

const choose = async ( name: string, option: string ) => {
	const select = screen.getByRole( 'combobox', { name } );
	fireEvent.change( select, { target: { value: within( select ).getByRole< HTMLOptionElement >( 'option', { name: option } ).value } } );
};

describe( 'Site details', () => {
	const resolvedOptions = Intl.DateTimeFormat.prototype.resolvedOptions;

	beforeEach( () => {
		( apiFetch as unknown as jest.Mock ).mockReset();
		Intl.DateTimeFormat.prototype.resolvedOptions = function () {
			return { ...resolvedOptions.call( this ), timeZone: 'Europe/London' };
		};
	} );

	afterEach( () => {
		Intl.DateTimeFormat.prototype.resolvedOptions = resolvedOptions;
	} );

	it( 'shows the heading with a spinner while the profile loads', () => {
		( apiFetch as unknown as jest.Mock ).mockReturnValue( new Promise( () => {} ) );
		renderStep();
		expect( screen.getByRole( 'heading', { name: 'Site details' } ) ).toBeTruthy();
		expect( screen.queryByRole( 'combobox' ) ).toBeNull();
		expect( document.querySelector( '.setup-canvas, [class*="spinner"], svg' ) ).toBeTruthy();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Loading site details' );
	} );

	it( 'names the site icon buttons after the field', async () => {
		mockProfile( response() );
		renderStep();
		expect( await screen.findByRole( 'button', { name: 'Upload Site Icon' } ) ).toHaveTextContent( 'Upload' );
	} );

	it( 'names the buttons for a saved site icon after the field', async () => {
		mockProfile( response( { profile: { site_icon: { id: 5, url: 'https://example.test/icon.png' } } } ) );
		renderStep();
		expect( await screen.findByRole( 'button', { name: 'Replace Site Icon' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Remove Site Icon' } ) ).toBeInTheDocument();
	} );

	it( 'prefills country and currency only when the site has none saved', async () => {
		mockProfile( response() );
		renderStep();
		expect( await screen.findByRole( 'combobox', { name: 'Country' } ) ).toHaveDisplayValue( 'United Kingdom (UK)' );
		expect( screen.getByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'Pound sterling' );
		expect( screen.getByText( 'Based on your time zone.' ) ).toBeTruthy();
		expect( screen.getByText( 'Based on the country.' ) ).toBeTruthy();
	} );

	it( 'leaves saved values untouched', async () => {
		mockProfile( response( { profile: { countrystate: 'US:CA', currency: 'USD' } } ) );
		renderStep();
		expect( await screen.findByRole( 'combobox', { name: 'Country' } ) ).toHaveDisplayValue( 'United States (US)' );
		expect( screen.getByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'United States (US) dollar' );
		expect( screen.queryByText( 'Based on your time zone.' ) ).toBeNull();
	} );

	it( 'says an imported currency matches the current site', async () => {
		mockProfile( response( { profile: { currency: 'USD' }, importedCurrency: 'USD' } ) );
		renderStep( { installState: { approach: 'import', complete: true } } );
		expect( await screen.findByText( 'Matches your current site’s store.' ) ).toBeTruthy();
		expect( screen.getByText( /Brought over from your current site/ ) ).toBeTruthy();
	} );

	it( 'makes no claim about a saved currency the import did not set', async () => {
		mockProfile( response( { profile: { currency: 'USD' } } ) );
		renderStep( { installState: { approach: 'import', complete: true } } );
		expect( await screen.findByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'United States (US) dollar' );
		expect( screen.queryByText( 'Matches your current site’s store.' ) ).toBeNull();
	} );

	it( 'makes no claim when the saved currency differs from the one the import set', async () => {
		mockProfile( response( { profile: { currency: 'USD' }, importedCurrency: 'GBP' } ) );
		renderStep( { installState: { approach: 'import', complete: true } } );
		expect( await screen.findByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'United States (US) dollar' );
		expect( screen.queryByText( 'Matches your current site’s store.' ) ).toBeNull();
	} );

	it( 'detects country on its own when only the currency was imported', async () => {
		mockProfile( response( { profile: { currency: 'USD', countrystate: '' }, importedCurrency: 'USD' } ) );
		renderStep( { installState: { approach: 'import', complete: true } } );
		expect( await screen.findByRole( 'combobox', { name: 'Country' } ) ).toHaveDisplayValue( 'United Kingdom (UK)' );
		expect( screen.getByText( 'Based on your time zone.' ) ).toBeTruthy();
		expect( screen.getByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'United States (US) dollar' );
		expect( screen.getByText( 'Matches your current site’s store.' ) ).toBeTruthy();
	} );

	it( 'follows a detected currency to the newly chosen country', async () => {
		mockProfile( response() );
		renderStep();
		await screen.findByRole( 'combobox', { name: 'Country' } );
		await choose( 'Country', 'United States (US)' );
		await waitFor( () => expect( screen.getByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'United States (US) dollar' ) );
		expect( screen.getByText( 'Based on the country.' ) ).toBeTruthy();
	} );

	it( 'keeps the detected currency but drops its hint when the new country has none', async () => {
		mockProfile( response() );
		renderStep();
		await screen.findByRole( 'combobox', { name: 'Country' } );
		await choose( 'Country', 'Antarctica' );
		await waitFor( () => expect( screen.getByRole( 'combobox', { name: 'Country' } ) ).toHaveDisplayValue( 'Antarctica' ) );
		expect( screen.getByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'Pound sterling' );
		expect( screen.queryByText( 'Based on the country.' ) ).toBeNull();
	} );

	it( 'never changes a currency the publisher picked when the country changes', async () => {
		mockProfile( response() );
		renderStep();
		await screen.findByRole( 'combobox', { name: 'Country' } );
		await choose( 'Currency', 'United States (US) dollar' );
		await choose( 'Country', 'United States (US)' );
		await choose( 'Country', 'United Kingdom (UK)' );
		await waitFor( () => expect( screen.getByRole( 'combobox', { name: 'Country' } ) ).toHaveDisplayValue( 'United Kingdom (UK)' ) );
		expect( screen.getByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'United States (US) dollar' );
	} );

	it( 'never changes an imported currency when the country changes', async () => {
		mockProfile( response( { profile: { currency: 'USD', countrystate: '' }, importedCurrency: 'USD' } ) );
		renderStep( { installState: { approach: 'import', complete: true } } );
		await screen.findByRole( 'combobox', { name: 'Country' } );
		await choose( 'Country', 'United Kingdom (UK)' );
		await choose( 'Country', 'Antarctica' );
		await choose( 'Country', 'United Kingdom (UK)' );
		await waitFor( () => expect( screen.getByRole( 'combobox', { name: 'Country' } ) ).toHaveDisplayValue( 'United Kingdom (UK)' ) );
		expect( screen.getByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'United States (US) dollar' );
		expect( screen.getByText( 'Matches your current site’s store.' ) ).toBeTruthy();
	} );

	it( 'derives the currency from the saved country, not the time zone', async () => {
		mockProfile( response( { profile: { countrystate: 'US:CA', currency: '' } } ) );
		renderStep();
		expect( await screen.findByRole( 'combobox', { name: 'Currency' } ) ).toHaveDisplayValue( 'United States (US) dollar' );
	} );

	it( 'says the language was the source when the time zone is unknown', async () => {
		Intl.DateTimeFormat.prototype.resolvedOptions = function () {
			return { ...resolvedOptions.call( this ), timeZone: 'UTC' };
		};
		mockProfile( response() );
		renderStep();
		expect( await screen.findByRole( 'combobox', { name: 'Country' } ) ).toHaveDisplayValue( 'United States (US)' );
		expect( screen.getByText( 'Based on your site language.' ) ).toBeTruthy();
	} );

	it( 'announces only the load failure, not its Try Again button', async () => {
		( apiFetch as unknown as jest.Mock ).mockRejectedValue( new Error( 'down' ) );
		renderStep();
		await screen.findByRole( 'button', { name: 'Try Again' } );
		await waitFor( () => expect( document.getElementById( 'a11y-speak-polite' )?.textContent ).toContain( 'Couldn’t load your site details.' ) );
		expect( document.getElementById( 'a11y-speak-polite' )?.textContent ).not.toContain( 'Try Again' );
	} );

	it( 'offers Try Again when the profile fails to load', async () => {
		( apiFetch as unknown as jest.Mock ).mockRejectedValueOnce( new Error( 'down' ) ).mockResolvedValue( response() );
		renderStep();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Try Again' } ) );
		expect( await screen.findByRole( 'combobox', { name: 'Country' } ) ).toBeTruthy();
	} );

	it( 'has no Back button', async () => {
		mockProfile( response() );
		renderStep();
		await screen.findByRole( 'combobox', { name: 'Country' } );
		expect( screen.queryByRole( 'button', { name: 'Back' } ) ).toBeNull();
	} );

	it( 'shows a saved title and tagline as text, and saves them unchanged', async () => {
		mockProfile( response( { profile: { site_title: 'Tom&#039;s News &amp; Views', tagline: 'Fish &amp; chips' } } ) );
		const view = renderStep();
		expect( await screen.findByRole( 'textbox', { name: 'Site title' } ) ).toHaveValue( "Tom's News & Views" );
		expect( screen.getByRole( 'textbox', { name: 'Tagline' } ) ).toHaveValue( 'Fish & chips' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( view.goTo ).toHaveBeenCalledWith( 'design' ) );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'POST',
				data: { profile: expect.objectContaining( { site_title: "Tom's News & Views", tagline: 'Fish & chips' } ) },
			} )
		);
	} );

	it( 'saves the profile and moves to Design on Continue', async () => {
		mockProfile( response() );
		const view = renderStep();
		await screen.findByRole( 'combobox', { name: 'Country' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		await waitFor( () => expect( view.goTo ).toHaveBeenCalledWith( 'design' ) );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: PROFILE,
				method: 'POST',
				data: { profile: expect.objectContaining( { countrystate: 'GB', currency: 'GBP', site_title: 'My Site' } ) },
			} )
		);
	} );

	it( 'stays on the step and shows an error when the save fails', async () => {
		mockProfile( response(), () => Promise.reject( new Error( 'nope' ) ) );
		const view = renderStep();
		await screen.findByRole( 'combobox', { name: 'Country' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Continue' } ) );
		expect( ( await screen.findAllByText( 'Couldn’t save your site details.' ) ).length ).toBeGreaterThan( 0 );
		expect( view.goTo ).not.toHaveBeenCalled();
	} );
} );
