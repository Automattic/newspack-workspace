/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies.
 */
import OneTimePurchaseRuleControl from './one-time-purchase-rule-control';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const OPTIONS = [ { value: 188250, label: 'Annual' } ];

const renderOneTimePurchase = ( productIds: number[] ) =>
	render(
		<OneTimePurchaseRuleControl
			value={ { product_ids: productIds, duration_value: 0, duration_unit: 'forever' } }
			onChange={ () => {} }
			options={ OPTIONS }
			productsLabel="Products"
		/>
	);

describe( 'the caution for values no option describes', () => {
	it( 'reaches the one-time purchase picker, and is spoken since the field takes no description', () => {
		// A one-time purchase matches the `_variation_id` on an order item, so this rule
		// is the likeliest to hold an ID the option list cannot describe — and it was the
		// one picker that showed such a token with nothing to explain it.
		renderOneTimePurchase( [ 188250, 999999 ] );

		expect( screen.getByRole( 'note' ) ).toHaveTextContent( /removing one widens who this gate lets in/ );
		expect( speak ).toHaveBeenCalledWith( expect.stringContaining( 'not listed' ), 'polite' );
	} );

	it( 'stays away when every stored value resolves', () => {
		renderOneTimePurchase( [ 188250 ] );

		expect( screen.queryByRole( 'note' ) ).not.toBeInTheDocument();
	} );
} );
