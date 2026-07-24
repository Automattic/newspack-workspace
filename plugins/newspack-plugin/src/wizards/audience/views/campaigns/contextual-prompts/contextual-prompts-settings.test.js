/**
 * Contextual Prompts settings: the override section's enable-toggle gating, the
 * CTA toggle, and the conditional button label/URL fields in the configure view.
 */

import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import apiFetch from '@wordpress/api-fetch';
import ContextualPromptsSettings from './contextual-prompts-settings';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const FIELD_DEFAULTS = { section: 'override', value: '' };
const ENABLE_FIELD = {
	...FIELD_DEFAULTS,
	key: 'newspack_contextual_prompts_override_enabled',
	label: 'Enable site-wide override',
	type: 'toggle',
	value: '1',
};
const BODY_FIELD = { ...FIELD_DEFAULTS, key: 'newspack_contextual_prompts_override_body', label: 'Override copy', type: 'textarea' };
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

	it( 'shows only the enable toggle while the override is off', async () => {
		mockStatus( [ { ...ENABLE_FIELD, value: '' }, BODY_FIELD, TOGGLE_FIELD, LABEL_FIELD, URL_FIELD ] );
		render( <ContextualPromptsSettings configuring onConfigure={ () => {} } /> );

		await waitFor( () => expect( screen.getByText( 'Enable site-wide override' ) ).toBeInTheDocument() );
		expect( screen.queryByText( 'Override copy' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Donate Form' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Override button label' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Enable site-wide override' } ) );
		expect( screen.getByText( 'Override copy' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Donate Form' ) ).toBeInTheDocument();
	} );

	it( 'hides the button fields under Donate Form and shows them under Donate Button', async () => {
		mockStatus( [ ENABLE_FIELD, TOGGLE_FIELD, LABEL_FIELD, URL_FIELD ] );
		render( <ContextualPromptsSettings configuring onConfigure={ () => {} } /> );

		await waitFor( () => expect( screen.getByText( 'Donate Form' ) ).toBeInTheDocument() );
		expect( screen.queryByText( 'Override button label' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Override button URL' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByText( 'Donate Button' ) );
		expect( screen.getByText( 'Override button label' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Override button URL' ) ).toBeInTheDocument();
	} );

	it( 'shows the button fields when no toggle exists (off-site sites)', async () => {
		mockStatus( [ ENABLE_FIELD, LABEL_FIELD, URL_FIELD ] );
		render( <ContextualPromptsSettings configuring onConfigure={ () => {} } /> );

		await waitFor( () => expect( screen.getByText( 'Override button label' ) ).toBeInTheDocument() );
		expect( screen.getByText( 'Override button URL' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Donate Form' ) ).not.toBeInTheDocument();
	} );

	it( 'renders Save in the header row', async () => {
		mockStatus( [ ENABLE_FIELD ] );
		const { container } = render( <ContextualPromptsSettings configuring onConfigure={ () => {} } /> );

		await waitFor( () => expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeInTheDocument() );
		const save = screen.getByRole( 'button', { name: 'Save' } );
		const heading = screen.getByRole( 'heading', { name: 'Contextual Prompts' } );
		expect( save.closest( 'form' ) ).toBe( container.querySelector( 'form' ) );
		// Same header row as the title, not the end of the form.
		expect( save.parentElement ).toBe( heading.parentElement.parentElement );
	} );
} );
