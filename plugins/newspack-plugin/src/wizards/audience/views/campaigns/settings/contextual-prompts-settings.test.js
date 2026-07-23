/**
 * Contextual Prompts settings: the override CTA toggle and the conditional
 * button label/URL fields in the configure view.
 */

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import ContextualPromptsSettings from './contextual-prompts-settings';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const FIELD_DEFAULTS = { section: 'override', value: '' };
const TOGGLE_FIELD = {
	...FIELD_DEFAULTS,
	key: 'newspack_contextual_prompts_override_cta',
	label: 'Override call to action',
	type: 'togglegroup',
	options: [
		{ value: 'form', label: 'Donate Form' },
		{ value: 'button', label: 'Donate Button' },
	],
	value: 'form',
};
const LABEL_FIELD = { ...FIELD_DEFAULTS, key: 'newspack_contextual_prompts_override_label', label: 'Override button label', type: 'text' };
const URL_FIELD = { ...FIELD_DEFAULTS, key: 'newspack_contextual_prompts_override_url', label: 'Override button URL', type: 'text' };

const mockStatus = fields => apiFetch.mockResolvedValue( { enabled: true, can_manage: true, fields } );

describe( 'ContextualPromptsSettings configure view', () => {
	beforeEach( () => jest.clearAllMocks() );

	it( 'hides the button fields under Donate Form and shows them under Donate Button', async () => {
		mockStatus( [ TOGGLE_FIELD, LABEL_FIELD, URL_FIELD ] );
		render( <ContextualPromptsSettings configuring onConfigure={ () => {} } /> );

		await waitFor( () => expect( screen.getByText( 'Donate Form' ) ).toBeInTheDocument() );
		expect( screen.queryByText( 'Override button label' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Override button URL' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByText( 'Donate Button' ) );
		expect( screen.getByText( 'Override button label' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Override button URL' ) ).toBeInTheDocument();
	} );

	it( 'shows the button fields when no toggle exists (off-site sites)', async () => {
		mockStatus( [ LABEL_FIELD, URL_FIELD ] );
		render( <ContextualPromptsSettings configuring onConfigure={ () => {} } /> );

		await waitFor( () => expect( screen.getByText( 'Override button label' ) ).toBeInTheDocument() );
		expect( screen.getByText( 'Override button URL' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Donate Form' ) ).not.toBeInTheDocument();
	} );
} );
