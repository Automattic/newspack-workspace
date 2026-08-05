/**
 * Contextual Prompts tab: a failed initial status fetch surfaces an error with a
 * Retry that re-runs the fetch, profile fields lock while a save is pending, and
 * the header's Edit design action hands off to the prompt pattern's editor.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import ContextualPrompts from './index';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Render the tab content and its header actions without the wizard Page shell
// (the barrel re-exports withWizardScreen from this submodule).
jest.mock( '../../../../../../packages/components/src/with-wizard-screen', () => ( {
	__esModule: true,
	default: Component => props => (
		<>
			{ props.headerActions }
			<Component { ...props } />
		</>
	),
} ) );

// The confirmDialog uses react-router history, so a Router must wrap the tab.
const renderTab = () => render( <ContextualPrompts />, { wrapper: MemoryRouter } );

const PROFILE_FIELD = {
	key: 'newspack_contextual_prompts_publisher_name',
	label: 'Publisher name',
	type: 'text',
	section: 'profile',
	value: '',
};

const PATTERN_EDIT_URL = 'https://example.test/wp-admin/site-editor.php?postId=42&postType=wp_block&canvas=edit';

// A cancelable beforeunload: prevented means the browser would draw its native
// "Leave site?" prompt.
const fireBeforeUnload = () => {
	const event = new Event( 'beforeunload', { cancelable: true } );
	window.dispatchEvent( event );
	return event;
};

const patternStatus = () => ( {
	enabled: true,
	can_manage: true,
	fields: [ PROFILE_FIELD ],
	pattern_id: 42,
	pattern_edit_url: PATTERN_EDIT_URL,
} );

describe( 'ContextualPrompts tab', () => {
	beforeEach( () => jest.clearAllMocks() );

	it( 'shows a Retry after a failed status fetch and recovers on retry', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'nope' ) );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toBeInTheDocument() );

		apiFetch.mockResolvedValueOnce( { enabled: false, can_manage: true, fields: [] } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );

		await waitFor( () => expect( screen.getByText( 'Get started with Contextual Prompts' ) ).toBeInTheDocument() );
	} );

	it( 'keeps Save disabled until dirty and locks fields while a save is pending', async () => {
		apiFetch.mockResolvedValueOnce( { enabled: true, can_manage: true, fields: [ PROFILE_FIELD ] } );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		// Clean on load.
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();

		// Editing makes it dirty and enables Save.
		fireEvent.change( screen.getByRole( 'textbox', { name: 'Publisher name' } ), { target: { value: 'Newsroom X' } } );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();

		// A pending save disables the fields so nothing changes mid-request.
		apiFetch.mockReturnValueOnce( new Promise( () => {} ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeDisabled() );
	} );

	it( 'confirms before Disable discards unsaved edits, and cancelling keeps state', async () => {
		apiFetch.mockResolvedValueOnce( { enabled: true, can_manage: true, fields: [ PROFILE_FIELD ] } );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox', { name: 'Publisher name' } ), { target: { value: 'Newsroom X' } } );

		// Disable with unsaved edits asks for confirmation instead of acting.
		fireEvent.click( screen.getByRole( 'button', { name: 'More options' } ) );
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Disable' } ) );
		expect( screen.getByText( /unsaved changes that will be lost/i ) ).toBeInTheDocument();

		// Cancelling leaves the feature enabled (no disable request) and edits intact.
		fireEvent.click( screen.getByText( 'Cancel' ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toHaveValue( 'Newsroom X' );
	} );

	// A plain link would strand the publisher in the editor: the handoff is what
	// puts a way back to the wizard on the destination screen. With nothing
	// pending it leaves straight away.
	it( 'hands off to the pattern editor from the header, with no in-page design controls', async () => {
		const HANDOFF_LINK = 'https://example.test/wp-admin/site-editor.php?handoff=1';
		const location = window.location;
		delete window.location;
		window.location = { href: 'https://example.test/wp-admin/admin.php?page=newspack-audience' };

		apiFetch.mockResolvedValueOnce( patternStatus() );
		apiFetch.mockResolvedValueOnce( { HandoffLink: HANDOFF_LINK } );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Edit design' } ) ).toBeInTheDocument() );
		// The design lives in the pattern: the tab body carries no style controls.
		expect( screen.queryByRole( 'heading', { name: 'Style' } ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Background' } ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit design' } ) );

		await waitFor( () => expect( window.location.href ).toBe( HANDOFF_LINK ) );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/newspack/v1/handoff',
				method: 'POST',
				data: expect.objectContaining( {
					destinationUrl: PATTERN_EDIT_URL,
					// The banner itself follows into the block editor; the in-editor
					// notice is not requested.
					showOnBlockEditor: false,
					bannerText: 'Return to Contextual Prompts after editing the design',
					bannerButtonText: 'Back to Contextual Prompts',
				} ),
			} )
		);

		window.location = location;
	} );

	// The handoff POSTs and navigates itself, so there is no link for the
	// unsaved-changes guard to intercept: the discard dialog is asked for here.
	it( 'confirms before Edit design leaves with unsaved edits, and discarding hands off', async () => {
		const HANDOFF_LINK = 'https://example.test/wp-admin/site-editor.php?handoff=1';
		const location = window.location;
		delete window.location;
		window.location = { href: 'https://example.test/wp-admin/admin.php?page=newspack-audience' };

		apiFetch.mockResolvedValueOnce( patternStatus() );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox', { name: 'Publisher name' } ), { target: { value: 'Newsroom X' } } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit design' } ) );
		expect( screen.getByText( /unsaved changes that will be lost/i ) ).toBeInTheDocument();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		// A refresh with edits pending still prompts.
		expect( fireBeforeUnload().defaultPrevented ).toBe( true );

		apiFetch.mockResolvedValueOnce( { HandoffLink: HANDOFF_LINK } );
		fireEvent.click( screen.getByText( 'Discard Changes' ) );

		await waitFor( () => expect( window.location.href ).toBe( HANDOFF_LINK ) );
		// But the handoff's own navigation, already approved in the dialog, does
		// not draw a second native prompt on top of it.
		expect( fireBeforeUnload().defaultPrevented ).toBe( false );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/newspack/v1/handoff',
				method: 'POST',
				data: expect.objectContaining( {
					destinationUrl: PATTERN_EDIT_URL,
					bannerText: 'Return to Contextual Prompts after editing the design',
					bannerButtonText: 'Back to Contextual Prompts',
				} ),
			} )
		);
		// The in-editor notice is not requested (the REST default is false).
		expect( apiFetch.mock.calls.at( -1 )[ 0 ].data.showOnBlockEditor ).toBeUndefined();

		window.location = location;
	} );

	it( 'omits the Edit design action when the pattern edit URL is empty', async () => {
		apiFetch.mockResolvedValueOnce( { ...patternStatus(), pattern_id: 0, pattern_edit_url: '' } );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		expect( screen.queryByRole( 'button', { name: 'Edit design' } ) ).toBeNull();
	} );

	it( 'sends the profile fields alone in the save payload', async () => {
		apiFetch.mockResolvedValueOnce( patternStatus() );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox', { name: 'Publisher name' } ), { target: { value: 'Newsroom X' } } );

		apiFetch.mockResolvedValueOnce( patternStatus() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
		const { data } = apiFetch.mock.calls[ 1 ][ 0 ];
		expect( data ).toEqual( { fields: { [ PROFILE_FIELD.key ]: 'Newsroom X' } } );
	} );
} );
