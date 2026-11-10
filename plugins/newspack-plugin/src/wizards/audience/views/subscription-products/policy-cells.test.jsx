/**
 * External dependencies.
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import { EffectivePrice } from './policy-cells';

const CURRENCY = { symbol: '$', position: 'left', decimals: 2, decimal_separator: '.', thousand_separator: ',' };

describe( 'EffectivePrice', () => {
	it.each( [
		[ 'an unchanged price', { base_price: 10, effective_price: 10, schedule: [] } ],
		[ 'a changed price', { base_price: 10, effective_price: 5, schedule: [] } ],
	] )( 'renders %s without bold weight', ( name, policy ) => {
		const { container } = render( <EffectivePrice policy={ policy } currency={ CURRENCY } /> );
		expect( container.textContent ).not.toBe( '' );
		expect( container.querySelector( 'strong' ) ).toBeNull();
	} );
} );
