/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { SettingsField, settingsFieldRenders } from './settings-field';

jest.mock( '../../../../../packages/components/src', () => ( {
	Button: ( { children } ) => children,
	Grid: ( { children } ) => children,
	SelectControl: ( { label, options } ) => (
		<select aria-label={ label }>
			{ options.map( ( { value, label: optionLabel } ) => (
				<option key={ value } value={ value }>
					{ optionLabel }
				</option>
			) ) }
		</select>
	),
	TextControl: ( { label, value } ) => <input aria-label={ label } value={ value } readOnly />,
} ) );

describe( 'settingsFieldRenders', () => {
	it( 'reports no output for a hidden field', () => {
		expect( settingsFieldRenders( { type: 'hidden' } ) ).toBe( false );
	} );

	it( 'reports no output for a select with no options', () => {
		expect( settingsFieldRenders( { type: 'select' } ) ).toBe( false );
		expect( settingsFieldRenders( { type: 'select', options: [] } ) ).toBe( false );
	} );

	it( 'reports output for a select that has options', () => {
		expect( settingsFieldRenders( { type: 'select', options: [ { value: 'a', label: 'A' } ] } ) ).toBe( true );
	} );

	it( 'reports output for every other field type', () => {
		[ 'text', 'password', 'number', 'textarea', 'checkbox', 'metadata', 'oauth' ].forEach( type => {
			expect( settingsFieldRenders( { type } ) ).toBe( true );
		} );
	} );
} );

describe( 'SettingsField', () => {
	const renderField = field => render( <SettingsField field={ field } value="" onChange={ () => {} } /> );

	// The ESP list call returns an empty array on failure, and core's
	// SelectControl renders nothing for it, so the wrapper must not be left
	// behind as an empty child of the column laying these fields out.
	it( 'renders nothing for a select with no options', () => {
		const { container } = renderField( { key: 'audience', type: 'select', label: 'Audience', options: [] } );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'renders the select once it has options', () => {
		renderField( { key: 'audience', type: 'select', label: 'Audience', options: [ { value: 'a', label: 'Readers' } ] } );
		expect( screen.getByLabelText( 'Audience' ) ).toBeTruthy();
	} );

	it( 'renders nothing for a hidden field', () => {
		const { container } = renderField( { key: 'secret', type: 'hidden', label: 'Secret' } );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
