/**
 * Contextual Prompts tab: a failed initial status fetch surfaces an error with a
 * Retry that re-runs the fetch, profile fields lock while a save is pending, and
 * the header's Edit Styles action opens the drawer on a classic theme or hands
 * off to the Site Editor's Styles panel on a block theme.
 */

import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
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

// A saved override on two groups: resetting one is a real style edit that leaves
// the other in place, so the payload assertion also covers "send the full object".
const SAVED_STYLES = { color: { background: '#123456' }, border: { radius: '4px', width: '1px' } };

// Classic theme, so the header's Edit Styles opens the drawer rather than the handoff.
const styledStatus = () => ( {
	enabled: true,
	can_manage: true,
	fields: [ PROFILE_FIELD ],
	is_block_theme: false,
	styles: SAVED_STYLES,
	style_defaults: { color: { background: '#f7f7f7' }, border: { radius: '10px' } },
	style_palette: [ { name: 'Accent', slug: 'accent', color: '#178f15' } ],
	style_font_sizes: [ { name: 'Small', slug: 'small', size: '14px' } ],
	site_editor_styles_url: 'https://example.test/wp-admin/site-editor.php?p=%2Fstyles',
} );

// With nothing stored, PHP hands back an empty JSON array rather than an object.
const unstyledStatus = () => ( { ...styledStatus(), styles: [] } );

// Block theme, so the styles live in the Site Editor: no drawer, and the header
// carries the handoff to it instead.
const blockThemeStatus = () => ( { ...styledStatus(), is_block_theme: true } );

// Each style group resets from the options menu in its ToolsPanel header.
const resetStyleItem = ( panel, item ) => {
	fireEvent.click( screen.getByRole( 'button', { name: `${ panel } options` } ) );
	fireEvent.click( screen.getByRole( 'menuitem', { name: `Reset ${ item }` } ) );
};

// The drawer and the header both carry Save/Cancel-named buttons, so drawer
// queries are scoped to its frame (a portal, hence the document lookup).
const drawerElement = () => document.querySelector( '.newspack-prompt-style-drawer' );
const drawer = () => within( drawerElement() );

const openDrawer = async () => {
	await waitFor( () => expect( screen.getByRole( 'button', { name: 'Edit Styles' } ) ).toBeInTheDocument() );
	fireEvent.click( screen.getByRole( 'button', { name: 'Edit Styles' } ) );
};

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
		const nameField = {
			key: 'newspack_contextual_prompts_publisher_name',
			label: 'Publisher name',
			type: 'text',
			section: 'profile',
			value: '',
		};
		apiFetch.mockResolvedValueOnce( { enabled: true, can_manage: true, fields: [ nameField ] } );
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

	it( 'locks the Edit Styles action while a save is pending', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox', { name: 'Publisher name' } ), { target: { value: 'Newsroom X' } } );

		// The drawer must not open onto state a pending save is about to replace.
		apiFetch.mockReturnValueOnce( new Promise( () => {} ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Edit Styles' } ) ).toBeDisabled() );
	} );

	it( 'confirms before Disable discards unsaved edits, and cancelling keeps state', async () => {
		const nameField = {
			key: 'newspack_contextual_prompts_publisher_name',
			label: 'Publisher name',
			type: 'text',
			section: 'profile',
			value: '',
		};
		apiFetch.mockResolvedValueOnce( { enabled: true, can_manage: true, fields: [ nameField ] } );
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

	it( 'opens the Edit Styles drawer from the header on a classic theme, with no in-page section', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Edit Styles' } ) ).toBeInTheDocument() );
		// The tab body carries no style controls: the drawer owns them now.
		expect( screen.queryByRole( 'heading', { name: 'Style' } ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Background' } ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit Styles' } ) );
		expect( screen.getByRole( 'heading', { name: 'Edit Styles', level: 2 } ) ).toBeInTheDocument();
		expect( drawer().getByRole( 'button', { name: 'Background' } ) ).toBeInTheDocument();
	} );

	it( 'closes the drawer without a prompt when the styles are untouched', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();
		await openDrawer();

		fireEvent.click( drawer().getByRole( 'button', { name: 'Cancel' } ) );
		await waitFor( () => expect( drawerElement() ).toBeNull() );
		expect( screen.queryByText( /unsaved changes that will be lost/i ) ).toBeNull();
	} );

	it( 'confirms before Cancel discards style edits, keeping the drawer when the prompt is cancelled', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();
		await openDrawer();

		resetStyleItem( 'Border', 'Border' );

		// Cancelling with a style edit standing prompts instead of closing.
		fireEvent.click( drawer().getByRole( 'button', { name: 'Cancel' } ) );
		await screen.findByText( /unsaved changes that will be lost/i );

		// Keeping the changes leaves the drawer open with the edit in place. The
		// text query dodges the dialog's X button, which is also named Cancel.
		fireEvent.click( within( document.querySelector( '.newspack-modal' ) ).getByText( 'Cancel' ) );
		expect( drawerElement() ).not.toBeNull();

		// Discarding closes the drawer, reverts the edit and saves nothing: the
		// reopened drawer's Save has nothing to send.
		await waitFor( () => fireEvent.click( drawer().getByRole( 'button', { name: 'Cancel' } ) ) );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Discard Changes' } ) );
		await waitFor( () => expect( drawerElement() ).toBeNull() );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		fireEvent.click( screen.getByRole( 'button', { name: 'Edit Styles' } ) );
		expect( drawer().getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
	} );

	it( 'vetoes Escape while style edits are unsaved', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();
		await openDrawer();

		resetStyleItem( 'Border', 'Border' );

		// The frame's own handler vetoes the close and defers to the confirm.
		fireEvent.keyDown( drawerElement(), { key: 'Escape' } );
		await screen.findByText( /unsaved changes that will be lost/i );
		expect( drawerElement() ).not.toBeNull();
	} );

	it( 'closes on Escape without a prompt when styles are untouched', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();
		await openDrawer();

		fireEvent.keyDown( drawerElement(), { key: 'Escape' } );
		await waitFor( () => expect( drawerElement() ).toBeNull() );
		expect( screen.queryByText( /unsaved changes that will be lost/i ) ).toBeNull();
	} );

	it( 'omits styles from the save payload when they are untouched', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox', { name: 'Publisher name' } ), { target: { value: 'Newsroom X' } } );

		apiFetch.mockResolvedValueOnce( styledStatus() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
		const { data } = apiFetch.mock.calls[ 1 ][ 0 ];
		expect( data.fields ).toEqual( { [ PROFILE_FIELD.key ]: 'Newsroom X' } );
		// Absent means "leave the stored styles alone"; sending them would be a no-op write.
		expect( data ).not.toHaveProperty( 'styles' );
	} );

	it( 'goes clean again when a style edit is undone from an empty array payload', async () => {
		apiFetch.mockResolvedValueOnce( unstyledStatus() );
		renderTab();
		await openDrawer();

		expect( drawer().getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();

		fireEvent.click( drawer().getByRole( 'button', { name: 'Text' } ) );
		fireEvent.click( screen.getByRole( 'option', { name: 'Accent' } ) );
		expect( drawer().getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();

		// Clicking the selected swatch clears it, leaving no overrides at all: the
		// saved snapshot has to compare equal to that, empty array payload or not.
		fireEvent.click( screen.getByRole( 'option', { name: 'Accent' } ) );
		expect( drawer().getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
	} );

	it( 'includes the whole style object in the drawer save payload and closes on success', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();
		await openDrawer();

		expect( screen.getByRole( 'heading', { name: 'Border', level: 3 } ) ).toBeInTheDocument();
		expect( drawer().getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();

		resetStyleItem( 'Border', 'Border' );
		expect( drawer().getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();

		apiFetch.mockResolvedValueOnce( styledStatus() );
		fireEvent.click( drawer().getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
		const { data } = apiFetch.mock.calls[ 1 ][ 0 ];
		expect( data.styles ).toEqual( { color: { background: '#123456' }, border: { radius: '4px' } } );
		expect( data.fields ).toEqual( { [ PROFILE_FIELD.key ]: '' } );
		await waitFor( () => expect( drawerElement() ).toBeNull() );
	} );

	it( 'leaves dirty profile fields unsaved and intact when the drawer saves', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByRole( 'textbox', { name: 'Publisher name' } ), { target: { value: 'Newsroom X' } } );

		await openDrawer();
		resetStyleItem( 'Border', 'Border' );

		apiFetch.mockResolvedValueOnce( styledStatus() );
		fireEvent.click( drawer().getByRole( 'button', { name: 'Save' } ) );

		// The drawer commits styles only: the fields it has to send are the saved
		// snapshot, not the edits standing in the page behind it.
		await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
		const { data } = apiFetch.mock.calls[ 1 ][ 0 ];
		expect( data.fields ).toEqual( { [ PROFILE_FIELD.key ]: '' } );

		await waitFor( () => expect( drawerElement() ).toBeNull() );
		expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toHaveValue( 'Newsroom X' );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeEnabled();
	} );

	it( 'keeps the drawer open and shows the error when the drawer save fails', async () => {
		apiFetch.mockResolvedValueOnce( styledStatus() );
		renderTab();
		await openDrawer();

		resetStyleItem( 'Border', 'Border' );

		apiFetch.mockRejectedValueOnce( new Error( 'Save failed.' ) );
		fireEvent.click( drawer().getByRole( 'button', { name: 'Save' } ) );

		// The edits are still there to retry, with the reason in reach of them.
		await waitFor( () => {
			expect( apiFetch ).toHaveBeenCalledTimes( 2 );
			expect( drawerElement() ).not.toBeNull();
			expect( drawer().getByText( 'Save failed.' ) ).toBeInTheDocument();
		} );
	} );

	describe( 'on a block theme', () => {
		const HANDOFF_LINK = 'https://example.test/wp-admin/site-editor.php?handoff=1';
		const RETURN_URL = 'https://example.test/wp-admin/admin.php?page=newspack-audience';
		let location;

		// The handoff reads the current URL for its return banner and navigates by
		// assigning to it, so a plain object stands in for the whole time.
		beforeEach( () => {
			location = window.location;
			delete window.location;
			window.location = { href: RETURN_URL };
		} );

		afterEach( () => {
			window.location = location;
		} );

		it( 'hands off to the Styles panel from the header, with no Style section', async () => {
			apiFetch.mockResolvedValueOnce( blockThemeStatus() );
			apiFetch.mockResolvedValueOnce( { HandoffLink: HANDOFF_LINK } );
			renderTab();

			await waitFor( () => expect( screen.getByRole( 'button', { name: 'Edit Styles' } ) ).toBeInTheDocument() );
			// The section is gone: the header action stands in for it.
			expect( screen.queryByRole( 'heading', { name: 'Style' } ) ).toBeNull();
			expect( screen.queryByRole( 'button', { name: 'Background' } ) ).toBeNull();

			fireEvent.click( screen.getByRole( 'button', { name: 'Edit Styles' } ) );

			await waitFor( () => expect( window.location.href ).toBe( HANDOFF_LINK ) );
			expect( apiFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/newspack/v1/handoff',
					method: 'POST',
					data: expect.objectContaining( {
						destinationUrl: blockThemeStatus().site_editor_styles_url,
						// The banner the Site Editor shows carries the way back here.
						bannerText: 'Return to Contextual Prompts after editing the block styles',
						bannerButtonText: 'Back to Contextual Prompts',
					} ),
				} )
			);
		} );
	} );
} );
