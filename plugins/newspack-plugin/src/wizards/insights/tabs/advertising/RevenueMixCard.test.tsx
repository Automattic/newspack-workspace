/**
 * Tests for RevenueMixCard (NPPD-1618): the direct-revenue-share scorecard and
 * its definitional description, including the all-direct / all-programmatic
 * edge cases.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import RevenueMixCard from './RevenueMixCard';
import type { MetricPayload } from '../components/metrics';

const breakdown = ( rows: Array< { label: string; revenue: number } > ): MetricPayload => ( {
	type: 'breakdown',
	computable: true,
	rows,
} );

describe( 'RevenueMixCard', () => {
	it( 'shows the direct share with a definitional description and inline complement', () => {
		render(
			<RevenueMixCard
				payload={ breakdown( [
					{ label: 'direct', revenue: 600 },
					{ label: 'programmatic', revenue: 400 },
				] ) }
			/>
		);
		expect( screen.getByText( 'Revenue Mix' ) ).toBeInTheDocument();
		expect( screen.getByText( '60%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'from direct sales' ) ).toBeInTheDocument();
		expect( screen.getByText( /The other 40% comes from programmatic/ ) ).toBeInTheDocument();
	} );

	it( 'renders the all-direct edge case gracefully', () => {
		render( <RevenueMixCard payload={ breakdown( [ { label: 'direct', revenue: 1000 } ] ) } /> );
		expect( screen.getByText( '100%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'All of your ad revenue comes from direct sales.' ) ).toBeInTheDocument();
	} );

	it( 'renders the all-programmatic edge case gracefully', () => {
		render( <RevenueMixCard payload={ breakdown( [ { label: 'programmatic', revenue: 1000 } ] ) } /> );
		expect( screen.getByText( '0%' ) ).toBeInTheDocument();
		expect( screen.getByText( 'All of your ad revenue comes from programmatic auctions.' ) ).toBeInTheDocument();
	} );
} );
