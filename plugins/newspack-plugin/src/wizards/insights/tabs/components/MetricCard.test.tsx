/**
 * Tests for MetricCard's value tooltip (NPPD-1684).
 *
 * `.tsx`, so not collected by the current testMatch (NPPD-1683); written to the
 * sibling convention. The runnable currency coverage lives in format.test.ts.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import MetricCard from './MetricCard';

describe( 'MetricCard value tooltip', () => {
	it( 'wraps the value in a titled span when valueTitle is provided', () => {
		render( <MetricCard label="Revenue" value={ 5 } format="number" valueTitle="Five exactly" /> );
		const titled = screen.getByTitle( 'Five exactly' );
		expect( titled.tagName ).toBe( 'SPAN' );
		expect( titled ).toHaveTextContent( '5' );
	} );

	it( 'renders the value without a titled span when valueTitle is absent', () => {
		render( <MetricCard label="Readers" value={ 5 } format="number" /> );
		const value = screen.getByText( '5' );
		expect( value.tagName ).not.toBe( 'SPAN' );
		expect( value ).not.toHaveAttribute( 'title' );
	} );

	it( 'derives a full-value title for an abbreviated currency value', () => {
		render( <MetricCard label="Total" value={ 1234567.89 } format="currency" /> );
		expect( screen.getByText( '$1.2M' ) ).toHaveAttribute( 'title', '$1,234,567.89' );
	} );

	it( 'adds no title for a small currency value that is not abbreviated', () => {
		render( <MetricCard label="Total" value={ 89.42 } format="currency" /> );
		const value = screen.getByText( '$89.42' );
		expect( value ).not.toHaveAttribute( 'title' );
	} );
} );
