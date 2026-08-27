/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { hasSelectableOption, isEmptyValue, SettingsField, settingsFieldRenders } from './settings-field';

// The select comes from @wordpress/components, which renders for real here so the
// tests see core's own empty-list and label wiring rather than a stand-in for it.
jest.mock( '../../../../../packages/components/src', () => ( {
	Button: ( { children } ) => children,
	Grid: ( { children } ) => children,
	TextControl: ( { label, value } ) => <input aria-label={ label } value={ value } readOnly />,
} ) );

describe( 'isEmptyValue', () => {
	it( 'treats absent values and the empty string as unset', () => {
		[ undefined, null, '' ].forEach( value => expect( isEmptyValue( value ) ).toBe( true ) );
	} );

	it( 'treats anything else as set', () => {
		[ '0', 0, false, 'abc' ].forEach( value => expect( isEmptyValue( value ) ).toBe( false ) );
	} );
} );

describe( 'hasSelectableOption', () => {
	it( 'reports nothing selectable for a missing or empty option list', () => {
		expect( hasSelectableOption( {} ) ).toBe( false );
		expect( hasSelectableOption( { options: [] } ) ).toBe( false );
	} );

	// A successful ESP list call against an account with no audiences still returns
	// the prepended None entry, so counting options would read that as a live list.
	it( 'reports nothing selectable when every option carries an empty value', () => {
		expect( hasSelectableOption( { options: [ { label: 'None', value: '' } ] } ) ).toBe( false );
	} );

	it( 'reports a selectable option once one carries a value', () => {
		expect(
			hasSelectableOption( {
				options: [
					{ label: 'None', value: '' },
					{ label: 'Readers', value: 'a' },
				],
			} )
		).toBe( true );
	} );
} );

describe( 'settingsFieldRenders', () => {
	it( 'reports no output for a hidden field', () => {
		expect( settingsFieldRenders( { type: 'hidden' } ) ).toBe( false );
	} );

	it( 'reports no output for an option-driven field with no options', () => {
		[ 'select', 'metadata' ].forEach( type => {
			expect( settingsFieldRenders( { type } ) ).toBe( false );
			expect( settingsFieldRenders( { type, options: [] } ) ).toBe( false );
		} );
	} );

	it( 'reports output for an option-driven field that has options', () => {
		[ 'select', 'metadata' ].forEach( type => {
			expect( settingsFieldRenders( { type, options: [ { value: 'a', label: 'A' } ] } ) ).toBe( true );
		} );
	} );

	it( 'reports no output for a select whose only option cannot be chosen', () => {
		expect( settingsFieldRenders( { type: 'select', options: [ { label: 'None', value: '' } ] } ) ).toBe( false );
	} );

	it( 'reports output for a required select with nothing to pick', () => {
		expect( settingsFieldRenders( { type: 'select', required: true, options: [] } ) ).toBe( true );
		expect( settingsFieldRenders( { type: 'select', required: true, options: [ { label: 'None', value: '' } ] } ) ).toBe( true );
	} );

	// Hiding a set field would strand its value somewhere the publisher cannot see it.
	it( 'reports output for an optionless select that already has a value', () => {
		expect( settingsFieldRenders( { type: 'select', value: 'stored', options: [] } ) ).toBe( true );
	} );

	it( 'reports output for every other field type', () => {
		[ 'text', 'password', 'number', 'textarea', 'checkbox', 'oauth' ].forEach( type => {
			expect( settingsFieldRenders( { type } ) ).toBe( true );
		} );
	} );
} );

describe( 'SettingsField', () => {
	const renderField = field => render( <SettingsField field={ field } value="" onChange={ () => {} } /> );

	it( 'renders nothing for a select with no options', () => {
		const { container } = renderField( { key: 'audience', type: 'select', label: 'Audience', options: [] } );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders the select once it has options', () => {
		renderField( { key: 'audience', type: 'select', label: 'Audience', options: [ { value: 'a', label: 'Readers' } ] } );
		expect( screen.getByLabelText( 'Audience' ) ).toBeTruthy();
	} );

	// Dropping the field would hide the only setting the Enable modal tells
	// publishers to open the settings view and complete.
	it( 'keeps a required select on screen when its options failed to load', () => {
		renderField( { key: 'audience', type: 'select', label: 'Audience', required: true, options: [] } );
		expect( screen.getByLabelText( 'Audience' ).getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( screen.getByRole( 'option' ).textContent ).toBe( 'No options available' );
	} );

	// The Enable modal calls this payload unsatisfiable and sends publishers here,
	// so the two screens have to agree that there is nothing to pick.
	it( 'treats a required select whose only option is empty the same as no options', () => {
		renderField( {
			key: 'audience',
			type: 'select',
			label: 'Audience',
			required: true,
			options: [ { label: 'None', value: '' } ],
		} );
		expect( screen.getByLabelText( 'Audience' ).getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( screen.getByRole( 'option' ).textContent ).toBe( 'No options available' );
	} );

	// aria-disabled keeps the field in the tab order, so a keyboard user still
	// reaches the control the Enable flow pointed them at.
	it( 'leaves the unsatisfiable select focusable', () => {
		renderField( { key: 'audience', type: 'select', label: 'Audience', required: true, options: [] } );
		const select = screen.getByLabelText( 'Audience' );
		expect( select.disabled ).toBe( false );
		select.focus();
		expect( document.activeElement ).toBe( select );
	} );

	it( 'keeps the field description alongside the empty-list explanation', () => {
		renderField( {
			key: 'audience',
			type: 'select',
			label: 'Audience',
			required: true,
			description: 'Choose an audience to receive reader activity data.',
			options: [],
		} );
		expect( screen.getByText( /Choose an audience to receive reader activity data\./ ) ).toBeTruthy();
		expect( screen.getByText( /No options are available\. Check the connection to this integration\./ ) ).toBeTruthy();
	} );

	it( 'renders nothing for a hidden field', () => {
		const { container } = renderField( { key: 'secret', type: 'hidden', label: 'Secret' } );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
