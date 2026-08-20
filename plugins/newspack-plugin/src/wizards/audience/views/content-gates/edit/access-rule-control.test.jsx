/**
 * External dependencies
 */
import { render, screen, fireEvent, within } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import AccessRuleControl from './access-rule-control';
import registerWizardStore, { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

// The control dispatches notices through the wizard store, which registers on demand.
// `@wordpress/data` itself is left real — `@wordpress/components` needs it — and the
// guard keeps a second suite in the same worker from re-registering.
if ( ! select( WIZARD_STORE_NAMESPACE ) ) {
	registerWizardStore();
}

// FormTokenField scrolls the auto-selected suggestion into view, which jsdom has no
// implementation for.
Element.prototype.scrollIntoView = jest.fn();

/**
 * Three subscription products sharing a display name, plus one that doesn't. This is the
 * shape that made the picker ambiguous: on real sites the same name is reused across
 * legacy and current product tiers.
 */
const DUPLICATE_NAME_PRODUCTS = [
	{ value: 188250, label: 'Annual' },
	{ value: 200014, label: 'Annual' },
	{ value: 205482, label: 'Annual' },
	{ value: 300000, label: 'Monthly' },
];

const renderControl = ( { options, value, onChange } ) => {
	window.newspackAudienceContentGates = {
		available_access_rules: {
			subscription: { name: 'Active subscription', options },
		},
	};
	return render( <AccessRuleControl slug="subscription" value={ value } onChange={ onChange } /> );
};

describe( 'AccessRuleControl option picker', () => {
	it( 'labels each selected option with its ID so same-named products are distinguishable', () => {
		renderControl( {
			options: DUPLICATE_NAME_PRODUCTS,
			value: [ 188250, 205482 ],
			onChange: jest.fn(),
		} );

		expect( screen.getByText( 'Annual (#188250)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Annual (#205482)' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Annual (#200014)' ) ).not.toBeInTheDocument();
	} );

	it( 'removes only the product whose token was removed, not every product sharing its name', () => {
		const onChange = jest.fn();
		renderControl( {
			options: DUPLICATE_NAME_PRODUCTS,
			value: [ 188250, 200014, 205482 ],
			onChange,
		} );

		// Every token's remove button carries the same generic label, so reach it through
		// the token that names the product being removed.
		const token = screen.getByText( 'Annual (#200014)' ).closest( '.components-form-token-field__token' );
		fireEvent.click( within( token ).getByRole( 'button' ) );

		expect( onChange ).toHaveBeenCalledWith( [ 188250, 205482 ] );
	} );

	it( 'suggests a product when its ID is typed', () => {
		renderControl( {
			options: DUPLICATE_NAME_PRODUCTS,
			value: [],
			onChange: jest.fn(),
		} );

		const input = screen.getByRole( 'combobox' );
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: '205482' } } );

		const suggestions = screen.getAllByRole( 'option' ).map( option => option.textContent );
		expect( suggestions ).toEqual( [ 'Annual (#205482)' ] );
	} );

	it( 'selects the highlighted suggestion when a product name is typed and Enter pressed', () => {
		// A typed name is not a token — tokens carry the ID — so without an auto-selected
		// first match Enter falls through to the input validator, which rejects it and
		// renders nothing. From the keyboard the field appeared to do nothing at all.
		const onChange = jest.fn();
		renderControl( {
			options: DUPLICATE_NAME_PRODUCTS,
			value: [],
			onChange,
		} );

		const input = screen.getByRole( 'combobox' );
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: 'Monthly' } } );
		fireEvent.keyDown( input, { keyCode: 13 } );

		expect( onChange ).toHaveBeenCalledWith( [ 300000 ] );
	} );

	it( 'shows a stored product no option describes, cautions about it, and keeps it through an unrelated edit', () => {
		// An option list holds parent products only, so a variation ID is not in it and
		// is still granting access. The token must not read as safe to delete.
		const onChange = jest.fn();
		renderControl( {
			options: DUPLICATE_NAME_PRODUCTS,
			value: [ 188250, 999999 ],
			onChange,
		} );

		expect( screen.getByText( '(product not listed) (#999999)' ) ).toBeInTheDocument();
		expect( screen.getByText( /still checked when access is evaluated/ ) ).toBeInTheDocument();

		const token = screen.getByText( 'Annual (#188250)' ).closest( '.components-form-token-field__token' );
		fireEvent.click( within( token ).getByRole( 'button' ) );

		expect( onChange ).toHaveBeenCalledWith( [ 999999 ] );
	} );

	it( 'falls back to a free-text control when the rule has no options', () => {
		renderControl( { options: [], value: 'example.com', onChange: jest.fn() } );

		expect( screen.getByDisplayValue( 'example.com' ) ).toBeInTheDocument();
	} );
} );
