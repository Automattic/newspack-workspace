/**
 * External dependencies
 */
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import ThemeBrand from './index';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { DEFAULT_THEME_MODS } from '../constants';

const SERVER: ThemeData = {
	etc: { post_count: '0' },
	theme: 'newspack-theme',
	homepage_patterns: [],
	theme_mods: { ...DEFAULT_THEME_MODS, header_center_logo: true },
};

let server: ThemeData;

const mockState = { errorMessage: null as string | null, rejectNext: false, holdNext: false };
let releaseHeld: () => void = () => {};

const mockWizardApiFetch = jest.fn( ( options: { method?: string; data?: ThemeData }, callbacks: { onSuccess?: ( data: ThemeData ) => void } ) => {
	if ( mockState.rejectNext ) {
		mockState.rejectNext = false;
		return Promise.reject( new Error( 'Request failed.' ) );
	}
	if ( options.method === 'POST' ) {
		server = { ...server, ...options.data };
	}
	const response = { ...server };
	if ( mockState.holdNext ) {
		mockState.holdNext = false;
		return new Promise( resolve => {
			releaseHeld = () => {
				callbacks?.onSuccess?.( response );
				resolve( response );
			};
		} );
	}
	callbacks?.onSuccess?.( response );
	return Promise.resolve( response );
} );

jest.mock( './theme-select', () => () => null );
jest.mock( './homepage-select', () => ( { HomepageSelect: () => null } ) );
jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: mockWizardApiFetch,
		isFetching: false,
		errorMessage: mockState.errorMessage,
		resetError: jest.fn(),
	} ),
} ) );

const headerAction = ( label: string ) =>
	( select( WIZARD_STORE_NAMESPACE ).getHeaderData().actions ?? [] ).find( ( action: { label: string } ) => action.label === label );
const notices = () =>
	select( WIZARD_STORE_NAMESPACE )
		.getNotices()
		.map( ( notice: { message: string } ) => notice.message );
const pick = ( name: string ) => fireEvent.click( screen.getByRole( 'radio', { name } ) );

const renderThemeBrand = async () => {
	render( <ThemeBrand /> );
	await act( async () => {} );
};

beforeEach( () => {
	jest.clearAllMocks();
	mockState.errorMessage = null;
	mockState.rejectNext = false;
	mockState.holdNext = false;
	server = { ...SERVER, theme_mods: { ...SERVER.theme_mods } };
	( dispatch( WIZARD_STORE_NAMESPACE ) as { resetHeaderData: () => void } ).resetHeaderData();
	( dispatch( WIZARD_STORE_NAMESPACE ) as { resetNotices: () => void } ).resetNotices();
} );

describe( 'Theme and Brand', () => {
	it( 'keeps Save disabled until a setting changes, and again once it is changed back', async () => {
		await renderThemeBrand();

		expect( headerAction( 'Save' ).disabled ).toBe( true );
		pick( 'Left' );
		expect( headerAction( 'Save' ).disabled ).toBe( false );
		pick( 'Center' );
		expect( headerAction( 'Save' ).disabled ).toBe( true );
	} );

	it( 'saves the draft and confirms with a snackbar', async () => {
		await renderThemeBrand();

		pick( 'Left' );
		await act( async () => {
			await headerAction( 'Save' ).action();
		} );

		expect( server.theme_mods.header_center_logo ).toBe( false );
		expect( notices() ).toContain( 'Settings saved.' );
		expect( headerAction( 'Save' ).disabled ).toBe( true );
	} );

	it( 'keeps an edit made while a save is in flight, and leaves it unsaved', async () => {
		await renderThemeBrand();

		pick( 'Left' );
		mockState.holdNext = true;
		let saving: Promise< unknown > = Promise.resolve();
		act( () => {
			saving = headerAction( 'Save' ).action();
		} );
		pick( 'Small' );
		await act( async () => {
			releaseHeld();
			await saving;
		} );

		expect( screen.getByRole( 'radio', { name: 'Small' } ) ).toBeChecked();
		expect( headerAction( 'Save' ).disabled ).toBe( false );
	} );

	it( 'keeps the draft when a save fails, and settles rather than rejecting', async () => {
		await renderThemeBrand();

		pick( 'Left' );
		mockState.rejectNext = true;
		let rejection: unknown = null;
		await act( async () => {
			await headerAction( 'Save' )
				.action()
				.then( undefined, ( error: unknown ) => {
					rejection = error;
				} );
		} );

		expect( rejection ).toBeNull();
		expect( notices() ).not.toContain( 'Settings saved.' );
		expect( headerAction( 'Save' ).disabled ).toBe( false );
	} );

	it( 'surfaces an API error', async () => {
		mockState.errorMessage = 'Request failed.';
		await renderThemeBrand();

		expect( screen.getAllByText( 'Request failed.' ).some( el => el.classList.contains( 'components-notice__content' ) ) ).toBe( true );
	} );
} );
