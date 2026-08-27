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
import UnlistedValuesNotice from './unlisted-values-notice';
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
	// First, because the announcement happens once for the page and the later cases
	// mount the caution too.
	it( 'is spoken once, not again each time a picker remounts', () => {
		// The block editor's inspector unmounts on deselection, and `@wordpress/a11y`
		// forces a repeat rather than absorbing it — it appends a non-breaking space to a
		// message matching the previous one — so announcing on mount read the whole
		// paragraph out again every time a publisher clicked between two blocks.
		renderOneTimePurchase( [ 188250, 999999 ] ).unmount();
		renderOneTimePurchase( [ 188250, 999999 ] );

		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith( expect.stringContaining( 'not listed' ), 'polite' );
	} );

	it( 'is a note, and appears only while a stored value has no option', () => {
		const { rerender } = render( <UnlistedValuesNotice options={ OPTIONS } value={ [ 188250, 999999 ] } /> );

		expect( screen.getByRole( 'note' ) ).toHaveTextContent( /removing one widens who this gate lets in/ );

		rerender( <UnlistedValuesNotice options={ OPTIONS } value={ [ 188250 ] } /> );

		expect( screen.queryByRole( 'note' ) ).not.toBeInTheDocument();
	} );

	it( 'reaches the one-time purchase picker', () => {
		// A one-time purchase matches the `_variation_id` on an order item, so this rule
		// is the likeliest to hold an ID the option list cannot describe — and it was the
		// one picker that showed such a token with nothing to explain it.
		renderOneTimePurchase( [ 188250, 999999 ] );

		expect( screen.getByRole( 'note' ) ).toBeInTheDocument();
	} );
} );
