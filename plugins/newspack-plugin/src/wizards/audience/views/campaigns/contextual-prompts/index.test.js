/**
 * Contextual Prompts tab: a failed initial status fetch surfaces an error with a
 * Retry that re-runs the fetch, profile fields lock while a save is pending, and
 * the header's Edit design action links out to the prompt pattern's editor.
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

	it( 'links the header Edit design action to the pattern editor, with no in-page design controls', async () => {
		apiFetch.mockResolvedValueOnce( patternStatus() );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'link', { name: 'Edit design' } ) ).toBeInTheDocument() );
		expect( screen.getByRole( 'link', { name: 'Edit design' } ) ).toHaveAttribute( 'href', PATTERN_EDIT_URL );
		// The design lives in the pattern: the tab body carries no style controls.
		expect( screen.queryByRole( 'heading', { name: 'Style' } ) ).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Background' } ) ).toBeNull();
	} );

	it( 'omits the Edit design action when the pattern edit URL is empty', async () => {
		apiFetch.mockResolvedValueOnce( { ...patternStatus(), pattern_id: 0, pattern_edit_url: '' } );
		renderTab();

		await waitFor( () => expect( screen.getByRole( 'textbox', { name: 'Publisher name' } ) ).toBeInTheDocument() );
		expect( screen.queryByRole( 'link', { name: 'Edit design' } ) ).toBeNull();
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
