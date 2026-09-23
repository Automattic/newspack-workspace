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
import Collections from './index';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

type CollectionsResponse = CollectionsSettingsData & { module_enabled_collections: boolean };

const SETTINGS: CollectionsResponse = {
	module_enabled_collections: false,
	custom_naming_enabled: false,
	custom_name: '',
	custom_singular_name: '',
	custom_slug: '',
	subscribe_link: '',
	order_link: '',
	posts_per_page: 12,
	category_filter_label: '',
	highlight_latest: false,
	articles_block_attrs: {},
	show_cover_story_img: false,
	post_indicator_style: 'default',
	card_message: '',
};

let server: CollectionsResponse & Record< string, unknown >;

const mockState = { isFetching: false, errorMessage: null as string | null, rejectNext: false };

const mockWizardApiFetch = jest.fn(
	(
		options: { method?: string; data?: Partial< CollectionsResponse > },
		callbacks: { onSuccess?: ( data: CollectionsResponse ) => void; onFinally?: () => void }
	) => {
		if ( mockState.rejectNext ) {
			mockState.rejectNext = false;
			callbacks?.onFinally?.();
			return Promise.reject( new Error( 'Request failed.' ) );
		}
		if ( options.method === 'POST' ) {
			server = { ...server, ...options.data };
		}
		callbacks?.onSuccess?.( server );
		callbacks?.onFinally?.();
		return Promise.resolve( server );
	}
);

jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: mockWizardApiFetch,
		isFetching: mockState.isFetching,
		errorMessage: mockState.errorMessage,
		resetError: jest.fn(),
	} ),
} ) );

const mockReload = jest.fn();
const originalLocation = window.location;

beforeAll( () => {
	Object.defineProperty( window, 'location', {
		configurable: true,
		value: { ...originalLocation, origin: originalLocation.origin, hostname: originalLocation.hostname, reload: mockReload },
	} );
} );

afterAll( () => {
	Object.defineProperty( window, 'location', { configurable: true, value: originalLocation } );
} );

const headerActions = () => select( WIZARD_STORE_NAMESPACE ).getHeaderData().actions ?? [];
const headerAction = ( label: string ) => headerActions().find( ( action: { label: string } ) => action.label === label );
const runHeaderAction = ( label: string ) => act( () => headerAction( label ).action() );

const renderCollections = async () => {
	render( <Collections /> );
	await act( async () => {} );
};

const lastPost = () => {
	const posts = mockWizardApiFetch.mock.calls.filter( ( [ options ] ) => options.method === 'POST' );
	return posts[ posts.length - 1 ]?.[ 0 ].data;
};

beforeEach( () => {
	jest.clearAllMocks();
	mockState.isFetching = false;
	mockState.errorMessage = null;
	mockState.rejectNext = false;
	server = { ...SETTINGS };
	( dispatch( WIZARD_STORE_NAMESPACE ) as { resetHeaderData: () => void } ).resetHeaderData();
	( dispatch( WIZARD_STORE_NAMESPACE ) as { resetNotices: () => void } ).resetNotices();
} );

describe( 'when Collections is off', () => {
	it( 'offers the empty state instead of the settings', async () => {
		await renderCollections();

		expect( screen.getByText( 'Organize content into collections' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Calls to Action' ) ).not.toBeInTheDocument();
		expect( headerActions() ).toHaveLength( 0 );
	} );

	it( 'enables the module and reloads so the admin menu picks it up', async () => {
		await renderCollections();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Enable' } ) );
		} );

		expect( lastPost() ).toEqual( { module_enabled_collections: true } );
		expect( mockReload ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stays put when enabling fails', async () => {
		await renderCollections();
		mockState.rejectNext = true;

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Enable' } ) );
		} );

		expect( mockReload ).not.toHaveBeenCalled();
	} );
} );

describe( 'when Collections is on', () => {
	beforeEach( () => {
		server = { ...SETTINGS, module_enabled_collections: true, module_enabled_print: true };
	} );

	it( 'keeps Save disabled until a setting changes', async () => {
		await renderCollections();

		expect( headerAction( 'Save' ).disabled ).toBe( true );

		fireEvent.click( screen.getByRole( 'radio', { name: 'Card' } ) );

		expect( headerAction( 'Save' ).disabled ).toBe( false );
	} );

	it( 'saves only its own settings, naming included, and confirms without reloading', async () => {
		await renderCollections();

		fireEvent.click( screen.getByRole( 'radio', { name: '30' } ) );
		fireEvent.click( screen.getByRole( 'radio', { name: 'Custom' } ) );
		fireEvent.change( screen.getByLabelText( 'Plural name' ), { target: { value: 'Issues' } } );
		await runHeaderAction( 'Save' );

		expect( lastPost() ).toEqual( {
			...SETTINGS,
			module_enabled_collections: true,
			posts_per_page: 30,
			custom_naming_enabled: true,
			custom_name: 'Issues',
		} );
		expect( mockReload ).not.toHaveBeenCalled();
		expect( headerAction( 'Save' ).disabled ).toBe( true );
		expect(
			select( WIZARD_STORE_NAMESPACE )
				.getNotices()
				.map( ( notice: { message: string } ) => notice.message )
		).toContain( 'Settings saved.' );
	} );

	it( 'shows the card message only for the card indicator', async () => {
		await renderCollections();

		expect( screen.queryByLabelText( 'Card message' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'radio', { name: 'Card' } ) );

		expect( screen.getByLabelText( 'Card message' ) ).toBeInTheDocument();
	} );

	it( 'keeps the draft when a save fails', async () => {
		await renderCollections();

		fireEvent.click( screen.getByRole( 'radio', { name: 'Card' } ) );
		mockState.rejectNext = true;
		await runHeaderAction( 'Save' );

		expect( screen.getByRole( 'radio', { name: 'Card' } ) ).toBeChecked();
		expect( headerAction( 'Save' ).disabled ).toBe( false );
	} );

	it( 'confirms before disabling, then writes and reloads', async () => {
		await renderCollections();

		await runHeaderAction( 'Disable' );

		expect( lastPost() ).toBeUndefined();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Disable' } ) );
		} );

		expect( lastPost() ).toEqual( { module_enabled_collections: false } );
		expect( mockReload ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'says the unsaved draft will be lost when disabling with one pending', async () => {
		await renderCollections();

		fireEvent.click( screen.getByRole( 'radio', { name: 'Card' } ) );
		await runHeaderAction( 'Disable' );

		expect( screen.getByText( /unsaved changes will be lost/ ) ).toBeInTheDocument();
	} );
} );
