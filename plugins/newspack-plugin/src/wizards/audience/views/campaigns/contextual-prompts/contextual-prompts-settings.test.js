/**
 * Contextual Prompts settings content: the disabled empty state (admin opt-in
 * modal, non-admin note), and the enabled settings body's field gating (override
 * enable toggle, CTA form/button choice, conditional button fields).
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import '@testing-library/jest-dom';
import { useState } from '@wordpress/element';
import ContextualPromptsSettings from './contextual-prompts-settings';

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

// The pattern keys the status payload carries; the settings body must ignore
// them, since the design is edited in the pattern from the wizard header.
const PATTERN_PAYLOAD = {
	pattern_id: 42,
	pattern_edit_url: 'https://example.test/wp-admin/site-editor.php?postId=42&postType=wp_block&canvas=edit',
};

const fieldsToValues = fields => ( fields || [] ).reduce( ( acc, field ) => ( { ...acc, [ field.key ]: field.value ?? '' } ), {} );

// Enabled body needs live values so the field-gating interactions can be exercised.
const EnabledHarness = ( { fields } ) => {
	const [ values, setValues ] = useState( () => fieldsToValues( fields ) );
	return (
		<ContextualPromptsSettings
			status={ { enabled: true, can_manage: true, fields } }
			values={ values }
			error={ null }
			inFlight={ false }
			onSetValue={ ( key, value ) => setValues( previous => ( { ...previous, [ key ]: value } ) ) }
			onEnable={ () => Promise.resolve() }
		/>
	);
};

describe( 'ContextualPromptsSettings empty state', () => {
	it( 'shows the empty state and opens the disclosure modal, then calls onEnable', () => {
		const onEnable = jest.fn().mockResolvedValue( undefined );
		render(
			<ContextualPromptsSettings
				status={ { enabled: false, can_manage: true, fields: [] } }
				values={ {} }
				error={ null }
				inFlight={ false }
				onSetValue={ () => {} }
				onEnable={ onEnable }
			/>
		);

		expect( screen.getByText( 'Get started with Contextual Prompts' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Enable Contextual Prompts' } ) );

		const dialog = screen.getByRole( 'dialog' );
		expect( within( dialog ).getByText( /confirm your newsroom permits it/ ) ).toBeInTheDocument();
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Enable' } ) );
		expect( onEnable ).toHaveBeenCalled();
	} );

	it( 'disables the button and shows the note for non-admins', () => {
		render(
			<ContextualPromptsSettings
				status={ { enabled: false, can_manage: false, fields: [] } }
				values={ {} }
				error={ null }
				inFlight={ false }
				onSetValue={ () => {} }
				onEnable={ () => Promise.resolve() }
			/>
		);

		expect( screen.getByText( 'An administrator must enable this feature.' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Enable Contextual Prompts' } ) ).toBeDisabled();
	} );
} );

describe( 'ContextualPromptsSettings enabled body', () => {
	it( 'renders the two settings sections and no design controls, pattern payload or not', () => {
		render(
			<ContextualPromptsSettings
				status={ { enabled: true, can_manage: true, fields: [ ENABLE_FIELD ], ...PATTERN_PAYLOAD } }
				values={ fieldsToValues( [ ENABLE_FIELD ] ) }
				error={ null }
				inFlight={ false }
				onSetValue={ () => {} }
				onEnable={ () => Promise.resolve() }
			/>
		);

		expect( screen.getByRole( 'heading', { name: 'Publisher Profile' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'heading', { name: 'Site-Wide Override' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: 'Edit Design' } ) ).not.toBeInTheDocument();
	} );

	it( 'shows only the enable toggle while the override is off', () => {
		render( <EnabledHarness fields={ [ { ...ENABLE_FIELD, value: '' }, BODY_FIELD, TOGGLE_FIELD, LABEL_FIELD, URL_FIELD ] } /> );

		expect( screen.getByText( 'Enable site-wide override' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Override copy' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Donate Form' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Override button label' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Enable site-wide override' } ) );
		expect( screen.getByText( 'Override copy' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Donate Form' ) ).toBeInTheDocument();
	} );

	it( 'hides the button fields under Donate Form and shows them under Donate Button', () => {
		render( <EnabledHarness fields={ [ ENABLE_FIELD, TOGGLE_FIELD, LABEL_FIELD, URL_FIELD ] } /> );

		expect( screen.getByText( 'Donate Form' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Override button label' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Override button URL' ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByText( 'Donate Button' ) );
		expect( screen.getByText( 'Override button label' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Override button URL' ) ).toBeInTheDocument();
	} );

	it( 'shows the button fields when no toggle exists (off-site sites)', () => {
		render( <EnabledHarness fields={ [ ENABLE_FIELD, LABEL_FIELD, URL_FIELD ] } /> );

		expect( screen.getByText( 'Override button label' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Override button URL' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Donate Form' ) ).not.toBeInTheDocument();
	} );
} );
