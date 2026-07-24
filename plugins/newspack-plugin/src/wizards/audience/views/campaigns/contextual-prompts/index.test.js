/**
 * Contextual Prompts tab: a failed initial status fetch surfaces an error with a
 * Retry that re-runs the fetch, and profile fields lock while a save is pending.
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
} );
