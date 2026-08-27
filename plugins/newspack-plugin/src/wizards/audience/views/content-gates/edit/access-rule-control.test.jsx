/**
 * External dependencies
 */
import { render, screen, fireEvent, waitFor, within, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import AccessRuleControl from './access-rule-control';
import { INSTITUTION_RULE_SLUG, invalidateAccessRuleOptions } from '../../../../../content-gate/access-rule-option-sources';
import registerWizardStore, { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

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

const renderControl = ( { slug = 'subscription', name = 'Active subscription', options, value, onChange } ) => {
	window.newspackAudienceContentGates = {
		available_access_rules: {
			[ slug ]: { name, options },
		},
	};
	return render( <AccessRuleControl slug={ slug } value={ value } onChange={ onChange } /> );
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
		// By role, not by text: the caution is also spoken, and `speak()` leaves a copy of
		// it in its live region for the rest of the suite.
		expect( screen.getByRole( 'note' ) ).toHaveTextContent( /still checked when access is evaluated/ );

		const token = screen.getByText( 'Annual (#188250)' ).closest( '.components-form-token-field__token' );
		fireEvent.click( within( token ).getByRole( 'button' ) );

		expect( onChange ).toHaveBeenCalledWith( [ 999999 ] );
	} );

	it( 'falls back to a free-text control when the rule has no options', () => {
		renderControl( { options: [], value: 'example.com', onChange: jest.fn() } );

		expect( screen.getByDisplayValue( 'example.com' ) ).toBeInTheDocument();
	} );
} );

describe( 'AccessRuleControl, a rule whose options are fetched', () => {
	const renderInstitutionControl = value => {
		window.newspackAudienceContentGates = {
			available_access_rules: {
				institution: { name: 'Institutional access', options: [ { value: 12, label: 'City Library' } ] },
			},
		};
		return render( <AccessRuleControl slug="institution" value={ value } onChange={ jest.fn() } /> );
	};

	beforeEach( () => {
		// The fetched list is cached for the app's lifetime, so each case has to start
		// from an uncached one to get its own response.
		invalidateAccessRuleOptions( INSTITUTION_RULE_SLUG );
		apiFetch.mockResolvedValue( [] );
	} );

	it( 'keeps the picker when the list comes back empty, and stops naming what the list no longer holds', async () => {
		// Institutions are created and deleted in this same app, so deleting the last one
		// is a legitimate empty response. Falling back to the page-load snapshot would
		// leave a deleted institution named and selectable, and reading the empty list as
		// "this rule has no options" would drop it to the free-text control, which writes
		// a string over the rule's IDs.
		renderInstitutionControl( [ 12 ] );

		expect( await screen.findByText( '(institution not listed) (#12)' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'textbox' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps the page-load list when the request fails, and says so', async () => {
		apiFetch.mockRejectedValue( new Error( 'Network error' ) );

		renderInstitutionControl( [ 12 ] );

		await waitFor( () =>
			expect( select( WIZARD_STORE_NAMESPACE ).getNotices() ).toContainEqual(
				expect.objectContaining( { id: `rule-options-error-${ INSTITUTION_RULE_SLUG }`, type: 'error' } )
			)
		);
		expect( screen.getByText( 'City Library (#12)' ) ).toBeInTheDocument();
	} );
} );

describe( 'AccessRuleControl suggestion cap', () => {
	it( 'offers every option on focus, past the field default of 100', async () => {
		const manyProducts = Array.from( { length: 105 }, ( _, i ) => ( { value: i + 1, label: `Product ${ i + 1 }` } ) );
		renderControl( { options: manyProducts, value: [], onChange: jest.fn() } );

		const input = await screen.findByRole( 'combobox' );
		// A real focus, not a synthetic event: `__experimentalExpandOnFocus` only expands
		// when the input is the document's active element.
		act( () => input.focus() );

		// Cut to 100, the rest read to a publisher as not existing — the list on focus is
		// unfiltered, so nothing they could type would be ranked in front of the cap.
		const suggestions = await screen.findAllByRole( 'option' );
		expect( suggestions ).toHaveLength( manyProducts.length );
		expect( suggestions[ 104 ] ).toHaveTextContent( 'Product 105 (#105)' );
	} );
} );
