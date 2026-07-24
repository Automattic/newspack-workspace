/**
 * Contextual Prompts tab: a failed initial status fetch surfaces an error with a
 * Retry that re-runs the fetch, instead of an endless spinner.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import ContextualPrompts from './index';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Render the tab content without the wizard header shell (the barrel re-exports
// withWizardScreen from this submodule).
jest.mock( '../../../../../../packages/components/src/with-wizard-screen', () => ( {
	__esModule: true,
	default: Component => Component,
} ) );

describe( 'ContextualPrompts tab error state', () => {
	beforeEach( () => jest.clearAllMocks() );

	it( 'shows a Retry after a failed status fetch and recovers on retry', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'nope' ) );
		render( <ContextualPrompts /> );

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Retry' } ) ).toBeInTheDocument() );

		apiFetch.mockResolvedValueOnce( { enabled: false, can_manage: true, fields: [] } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Retry' } ) );

		await waitFor( () => expect( screen.getByText( 'Get started with Contextual Prompts' ) ).toBeInTheDocument() );
	} );
} );
