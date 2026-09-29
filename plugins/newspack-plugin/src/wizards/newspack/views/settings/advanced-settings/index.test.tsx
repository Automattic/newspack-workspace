/**
 * External dependencies
 */
import { render, screen, fireEvent, act, within } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { dispatch, select } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import AdvancedSettings from './index';
import { ADVANCED_SETTINGS_DEFAULTS } from '../constants';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import Router from '../../../../../../packages/components/src/proxied-imports/router';

const { MemoryRouter, useHistory } = Router;

const THEME_PATH = '/newspack/v1/wizard/newspack-setup-wizard/theme';
const MAX_AGE_PATH = '/newspack/v1/wizard/newspack-settings/related-posts-max-age';
const PRIMARY_CATEGORY_PATH = '/newspack/v1/wizard/newspack-settings/primary-category';

type Options = { path: string; method?: string; data?: Record< string, unknown > };

let responses: Record< string, unknown >;

const mockState: { rejectPath: string | null } = { rejectPath: null };

const mockWizardApiFetch = jest.fn( ( options: Options, callbacks?: { onSuccess?: ( data: unknown ) => void } ) => {
	if ( options.method === 'POST' && options.path === mockState.rejectPath ) {
		mockState.rejectPath = null;
		return Promise.reject( new Error( 'Request failed.' ) );
	}
	let response = responses[ options.path ];
	if ( options.method === 'POST' && options.path === THEME_PATH ) {
		const { theme_mods } = options.data as { theme_mods: Record< string, unknown > };
		// The server applies the all-posts choices to posts and never stores them.
		const { featured_image_all_posts, post_template_all_posts, ...stored } = theme_mods;
		response = { ...( responses[ THEME_PATH ] as object ), theme_mods: stored };
	} else if ( options.method === 'POST' ) {
		response = options.data;
	}
	callbacks?.onSuccess?.( response );
	return Promise.resolve( response );
} );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../../../hooks/use-wizard-api-fetch', () => {
	const { useState } = jest.requireActual( '@wordpress/element' );
	return {
		useWizardApiFetch: () => {
			const [ errorMessage, setErrorMessage ] = useState( null );
			return {
				wizardApiFetch: ( options: Options, callbacks?: { onSuccess?: ( data: unknown ) => void } ) =>
					mockWizardApiFetch( options, callbacks ).catch( ( error: Error ) => {
						setErrorMessage( error.message );
						throw error;
					} ),
				isFetching: false,
				errorMessage,
				resetError: () => setErrorMessage( null ),
			};
		},
	};
} );

let history: { push: ( path: string ) => void; location: { pathname: string } };

const HistorySpy = () => {
	history = useHistory();
	return null;
};

const headerAction = ( label: string ) =>
	( select( WIZARD_STORE_NAMESPACE ).getHeaderData().actions ?? [] ).find( ( action: { label: string } ) => action.label === label );

const renderView = async () => {
	render(
		<MemoryRouter initialEntries={ [ '/advanced-settings' ] }>
			<HistorySpy />
			<AdvancedSettings />
		</MemoryRouter>
	);
	await act( async () => {} );
};

const save = () =>
	act( async () => {
		headerAction( 'Save' ).action();
	} );

const choose = ( group: string, option: string ) =>
	fireEvent.click( within( screen.getByRole( 'radiogroup', { name: group } ) ).getByRole( 'radio', { name: option } ) );

const posts = () => mockWizardApiFetch.mock.calls.filter( ( [ options ] ) => options.method === 'POST' ).map( ( [ options ] ) => options );

const notices = () =>
	select( WIZARD_STORE_NAMESPACE )
		.getNotices()
		.map( ( notice: { message: string } ) => notice.message );

beforeEach( () => {
	jest.clearAllMocks();
	mockState.rejectPath = null;
	responses = {
		[ THEME_PATH ]: {
			theme_mods: { ...ADVANCED_SETTINGS_DEFAULTS, show_author_email: false },
			etc: { post_count: '10', has_pwa_plugin: false },
		},
		'/newspack/v1/wizard/newspack-settings/related-content': { relatedPostsEnabled: true, relatedPostsMaxAge: 0 },
		[ PRIMARY_CATEGORY_PATH ]: { enabled: true, yoast_active: true },
		'/newspack/v1/wizard/newspack-settings/default-templates': {
			post: [ { label: 'Default', value: 'default' } ],
			page: [ { label: 'Default', value: 'default' } ],
		},
		'/newspack/v1/wizard/newspack-settings/accessibility-statement': { reason: 'none' },
	};
	( dispatch( WIZARD_STORE_NAMESPACE ) as { resetHeaderData: () => void } ).resetHeaderData();
	( dispatch( WIZARD_STORE_NAMESPACE ) as { resetNotices: () => void } ).resetNotices();
} );

it( 'keeps Save disabled until a setting changes, and again once it changes back', async () => {
	await renderView();

	expect( headerAction( 'Save' ).disabled ).toBe( true );

	choose( 'Author email', 'Show' );
	expect( headerAction( 'Save' ).disabled ).toBe( false );

	choose( 'Author email', 'Hide' );
	expect( headerAction( 'Save' ).disabled ).toBe( true );
} );

it( 'treats a number typed back to its saved value, and a string-stored flag, as unchanged', async () => {
	responses[ THEME_PATH ] = {
		...( responses[ THEME_PATH ] as object ),
		theme_mods: { ...ADVANCED_SETTINGS_DEFAULTS, newspack_image_credits_auto_populate: '' },
	};
	await renderView();

	const length = screen.getByLabelText( 'Length' );
	fireEvent.change( length, { target: { value: '201' } } );
	fireEvent.change( length, { target: { value: '200' } } );
	expect( headerAction( 'Save' ).disabled ).toBe( true );

	choose( 'Image credits on upload', 'Auto-populate' );
	choose( 'Image credits on upload', 'Manual' );
	expect( headerAction( 'Save' ).disabled ).toBe( true );
} );

it( 'saves only the settings that changed, then confirms', async () => {
	await renderView();

	choose( 'Author email', 'Show' );
	await save();

	expect( posts().map( ( { path } ) => path ) ).toEqual( [ THEME_PATH ] );
	expect( ( posts()[ 0 ].data as { theme_mods: AdvancedSettings } ).theme_mods.show_author_email ).toBe( true );
	expect( headerAction( 'Save' ).disabled ).toBe( true );
	expect( notices() ).toContain( 'Settings saved.' );
} );

it( 'saves the related posts age and primary category through their own endpoints', async () => {
	await renderView();

	fireEvent.change( screen.getByLabelText( 'Maximum age of related content, in months' ), { target: { value: '6' } } );
	choose( 'Categories on posts', 'All' );
	await save();

	expect( posts().map( ( { path } ) => path ) ).toEqual( [ MAX_AGE_PATH, PRIMARY_CATEGORY_PATH ] );
	expect( headerAction( 'Save' ).disabled ).toBe( true );
} );

it( 'saves a cleared related posts age as 0', async () => {
	responses[ '/newspack/v1/wizard/newspack-settings/related-content' ] = { relatedPostsEnabled: true, relatedPostsMaxAge: 3 };
	await renderView();

	fireEvent.change( screen.getByLabelText( 'Maximum age of related content, in months' ), { target: { value: '' } } );
	await save();

	expect( posts()[ 0 ] ).toMatchObject( { path: MAX_AGE_PATH, data: { relatedPostsMaxAge: 0 } } );
} );

it( 'asks before overwriting every post, and writes nothing if cancelled', async () => {
	await renderView();

	fireEvent.change( screen.getByLabelText( 'Featured image position for all existing posts' ), { target: { value: 'small' } } );
	await save();

	expect( screen.getByText( 'Update all posts?' ) ).toBeInTheDocument();
	expect( posts() ).toHaveLength( 0 );

	fireEvent.click( screen.getByText( 'Cancel', { selector: 'button' } ) );
	expect( posts() ).toHaveLength( 0 );

	await save();
	await act( async () => {
		fireEvent.click( screen.getByRole( 'button', { name: 'Update all posts' } ) );
	} );

	expect( ( posts()[ 0 ].data as { theme_mods: AdvancedSettings } ).theme_mods.featured_image_all_posts ).toBe( 'small' );
	expect( screen.getByLabelText( 'Featured image position for all existing posts' ) ).toHaveValue( 'none' );
	expect( headerAction( 'Save' ).disabled ).toBe( true );
} );

it( 'raises the unsaved-changes dialog, not the overwrite one, when leaving with an all-posts choice', async () => {
	await renderView();

	fireEvent.change( screen.getByLabelText( 'Featured image position for all existing posts' ), { target: { value: 'small' } } );
	act( () => history.push( '/theme-and-brand' ) );

	expect( screen.getByText( 'Discard Changes', { selector: 'button' } ) ).toBeInTheDocument();
	expect( screen.queryByText( 'Update all posts?' ) ).not.toBeInTheDocument();
	expect( history.location.pathname ).toBe( '/advanced-settings' );
} );

it( 'keeps the edit, shows the error and no confirmation when a save fails', async () => {
	await renderView();

	choose( 'Author email', 'Show' );
	mockState.rejectPath = THEME_PATH;
	await save();

	expect( within( screen.getByRole( 'radiogroup', { name: 'Author email' } ) ).getByRole( 'radio', { name: 'Show' } ) ).toBeChecked();
	expect( screen.getByText( 'Request failed.', { selector: '.components-notice__content' } ) ).toBeInTheDocument();
	expect( headerAction( 'Save' ).disabled ).toBe( false );
	expect( notices() ).not.toContain( 'Settings saved.' );
} );

it( 'retries only the endpoint that failed', async () => {
	await renderView();

	choose( 'Author email', 'Show' );
	fireEvent.change( screen.getByLabelText( 'Maximum age of related content, in months' ), { target: { value: '6' } } );
	mockState.rejectPath = MAX_AGE_PATH;
	await save();

	expect( notices() ).not.toContain( 'Settings saved.' );
	expect( headerAction( 'Save' ).disabled ).toBe( false );

	mockWizardApiFetch.mockClear();
	await save();

	expect( posts().map( ( { path } ) => path ) ).toEqual( [ MAX_AGE_PATH ] );
	expect( screen.queryByText( 'Request failed.', { selector: '.components-notice__content' } ) ).not.toBeInTheDocument();
	expect( notices() ).toContain( 'Settings saved.' );
} );

it( 'reports a failed Jetpack handoff and lets the publisher try again', async () => {
	( apiFetch as unknown as jest.Mock ).mockRejectedValue( { message: 'Handoff failed.' } );
	await renderView();

	const button = screen.getByRole( 'button', { name: 'Configure in Jetpack' } );
	await act( async () => {
		fireEvent.click( button );
	} );
	await act( async () => {
		fireEvent.click( button );
	} );

	expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	expect( notices().filter( ( message: string ) => message === 'Handoff failed.' ) ).toHaveLength( 1 );
	expect( button ).not.toBeDisabled();
	expect( button ).not.toHaveAttribute( 'aria-disabled', 'true' );
} );
