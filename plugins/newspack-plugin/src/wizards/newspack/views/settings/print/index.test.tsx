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
import Print from './index';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

const SETTINGS: PrintData = {
	module_enabled_print: false,
	indesign_platform: 'win',
	indesign_post_types: [ 'post' ],
	available_post_types: [
		{ label: 'Posts', value: 'post' },
		{ label: 'Pages', value: 'page' },
	],
	indesign_exclude_captions: false,
};

let server: PrintData;

const mockState = { isFetching: false, errorMessage: null as string | null, rejectNext: false };

const mockWizardApiFetch = jest.fn(
	( options: { method?: string; data?: Partial< PrintData > }, callbacks: { onSuccess?: ( data: PrintData ) => void; onFinally?: () => void } ) => {
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

const mockResetError = jest.fn();

jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: mockWizardApiFetch,
		isFetching: mockState.isFetching,
		errorMessage: mockState.errorMessage,
		resetError: mockResetError,
	} ),
} ) );

const headerActions = () => select( WIZARD_STORE_NAMESPACE ).getHeaderData().actions ?? [];
const headerAction = ( label: string ) => headerActions().find( ( action: { label: string } ) => action.label === label );
const runHeaderAction = ( label: string ) => act( () => headerAction( label ).action() );

const renderPrint = async () => {
	render( <Print /> );
	// The view holds its spinner until the initial GET settles.
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

describe( 'when InDesign export is off', () => {
	it( 'offers the empty state instead of the settings', async () => {
		await renderPrint();

		expect( screen.getByText( 'Export articles to Adobe InDesign' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Platform' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the Enable button reachable while its request is in flight', async () => {
		mockState.isFetching = true;
		await renderPrint();

		const enable = screen.getByRole( 'button', { name: 'Enable' } );
		expect( enable ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( enable ).not.toBeDisabled();
	} );

	it( 'enables the module on click and reveals the settings', async () => {
		await renderPrint();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Enable' } ) );
		} );

		expect( lastPost() ).toEqual( { module_enabled_print: true } );
		expect( screen.getByLabelText( 'Platform' ) ).toBeInTheDocument();
	} );
} );

describe( 'when InDesign export is on', () => {
	beforeEach( () => {
		server = { ...SETTINGS, module_enabled_print: true };
	} );

	it( 'keeps Save disabled until a setting changes', async () => {
		await renderPrint();

		expect( headerAction( 'Save' ).disabled ).toBe( true );

		fireEvent.click( screen.getByLabelText( 'Pages' ) );

		expect( headerAction( 'Save' ).disabled ).toBe( false );
	} );

	it( 'ignores post-type order, so re-checking a box leaves the draft clean', async () => {
		server = { ...SETTINGS, module_enabled_print: true, indesign_post_types: [ 'post', 'page' ] };
		await renderPrint();

		fireEvent.click( screen.getByLabelText( 'Posts' ) );
		fireEvent.click( screen.getByLabelText( 'Posts' ) );

		expect( headerAction( 'Save' ).disabled ).toBe( true );
	} );

	it( 'writes nothing until Save is pressed, then sends the whole draft', async () => {
		await renderPrint();

		fireEvent.click( screen.getByLabelText( 'Pages' ) );
		fireEvent.click( screen.getByLabelText( 'Exclude photo captions and credits' ) );

		expect( lastPost() ).toBeUndefined();

		await runHeaderAction( 'Save' );

		expect( lastPost() ).toEqual( {
			module_enabled_print: true,
			indesign_platform: 'win',
			indesign_post_types: [ 'post', 'page' ],
			indesign_exclude_captions: true,
		} );
		expect( headerAction( 'Save' ).disabled ).toBe( true );
		expect(
			select( WIZARD_STORE_NAMESPACE )
				.getNotices()
				.map( ( notice: { message: string } ) => notice.message )
		).toContain( 'Settings saved.' );
	} );

	it( 'keeps the draft when a save fails, and settles rather than rejecting', async () => {
		await renderPrint();

		fireEvent.click( screen.getByLabelText( 'Pages' ) );
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
		expect( screen.getByLabelText( 'Pages' ) ).toBeChecked();
		expect( headerAction( 'Save' ).disabled ).toBe( false );
	} );

	it( 'surfaces an API error, announced politely rather than assertively', async () => {
		mockState.errorMessage = 'Request failed.';
		await renderPrint();

		// The message lands twice: once in the notice, once in the live region it was spoken into.
		const matches = screen.getAllByText( 'Request failed.' );
		expect( matches.some( ( el: HTMLElement ) => el.classList.contains( 'components-notice__content' ) ) ).toBe( true );
		expect( matches.some( ( el: HTMLElement ) => el.id === 'a11y-speak-polite' ) ).toBe( true );
		expect( matches.some( ( el: HTMLElement ) => el.id === 'a11y-speak-assertive' ) ).toBe( false );
	} );

	it( 'confirms before disabling, and writes only once confirmed', async () => {
		await renderPrint();

		await runHeaderAction( 'Disable' );

		expect( screen.queryByText( /unsaved changes will be lost/ ) ).not.toBeInTheDocument();
		expect( lastPost() ).toBeUndefined();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Disable' } ) );
		} );

		expect( lastPost() ).toEqual( { module_enabled_print: false } );
		expect( screen.getByText( 'Export articles to Adobe InDesign' ) ).toBeInTheDocument();
		expect( headerActions() ).toHaveLength( 0 );
	} );

	it( 'says the unsaved draft will be lost when disabling with one pending', async () => {
		await renderPrint();

		fireEvent.click( screen.getByLabelText( 'Pages' ) );
		await runHeaderAction( 'Disable' );

		expect( screen.getByText( /unsaved changes will be lost/ ) ).toBeInTheDocument();
	} );

	it( 'names the body that focus lands on', async () => {
		await renderPrint();

		expect( screen.getByRole( 'group', { name: 'Adobe InDesign export settings' } ) ).toBeInTheDocument();
	} );

	it( 'leaves focus alone when the tab is merely arrived at', async () => {
		await renderPrint();

		const platform = screen.getByLabelText( 'Platform' );
		expect( platform.ownerDocument.activeElement ).toBe( platform.ownerDocument.body );
	} );

	it( 'moves focus to the body it reveals, rather than dropping it on the document', async () => {
		await renderPrint();

		await runHeaderAction( 'Disable' );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Disable' } ) );
		} );

		const emptyState = screen.getByText( 'Export articles to Adobe InDesign' );
		const { activeElement } = emptyState.ownerDocument;
		expect( activeElement ).toHaveClass( 'newspack-wizard__sections' );
	} );

	it( 'keeps the settings when the disable is cancelled', async () => {
		await renderPrint();

		await runHeaderAction( 'Disable' );
		// By text, not by role: the modal's close icon is also labelled "Cancel".
		await act( async () => {
			fireEvent.click( screen.getByText( 'Cancel' ) );
		} );

		expect( lastPost() ).toBeUndefined();
		expect( screen.getByLabelText( 'Platform' ) ).toBeInTheDocument();
	} );
} );
