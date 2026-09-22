/**
 * The module flag and the settings are saved on different terms: enabling and
 * disabling write on click, everything else waits for Save.
 */

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

const mockWizardApiFetch = jest.fn(
	( options: { method?: string; data?: Partial< PrintData > }, callbacks: { onSuccess?: ( data: PrintData ) => void; onFinally?: () => void } ) => {
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
		isFetching: false,
		errorMessage: null,
		resetError: jest.fn(),
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
	server = { ...SETTINGS };
	( dispatch( WIZARD_STORE_NAMESPACE ) as { resetHeaderData: () => void } ).resetHeaderData();
} );

describe( 'when InDesign export is off', () => {
	it( 'offers the empty state instead of the settings', async () => {
		await renderPrint();

		expect( screen.getByText( 'Export articles to Adobe InDesign' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Platform' ) ).not.toBeInTheDocument();
	} );

	it( 'publishes no header actions, so there is nothing to save or disable', async () => {
		await renderPrint();

		expect( headerActions() ).toHaveLength( 0 );
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
	} );

	it( 'confirms before disabling, and writes only once confirmed', async () => {
		await renderPrint();

		await runHeaderAction( 'Disable' );

		expect( lastPost() ).toBeUndefined();

		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Disable' } ) );
		} );

		expect( lastPost() ).toEqual( { module_enabled_print: false } );
		expect( screen.getByText( 'Export articles to Adobe InDesign' ) ).toBeInTheDocument();
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
