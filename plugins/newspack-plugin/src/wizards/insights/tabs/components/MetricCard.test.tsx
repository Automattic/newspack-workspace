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

describe( 'MetricCard zeroFallback (NPPD-1694)', () => {
	const PAYWALL = { attemptsLabel: 'paid access gate attempts', conversionsLabel: 'conversions' };

	describe( 'rate card (format="percent")', () => {
		it( 'renders "0 of N" (count, no % suffix) when numerator is 0 and denominator > 0', () => {
			render(
				<MetricCard
					label="Paid access gate conversion (Direct)"
					value={ 0 }
					format="percent"
					zeroFallback={ { numerator: 0, denominator: 17, ...PAYWALL } }
				/>
			);
			expect( screen.getByText( '0 of 17' ) ).toBeInTheDocument();
			// No percentage anywhere — neither a bare "0%" nor a "(0%)" parenthetical.
			expect( screen.queryByText( /%/ ) ).not.toBeInTheDocument();
		} );

		it( 'localizes a large denominator in the count format', () => {
			render( <MetricCard label="Rate" value={ 0 } format="percent" zeroFallback={ { numerator: 0, denominator: 1234567, ...PAYWALL } } /> );
			expect( screen.getByText( '0 of 1,234,567' ) ).toBeInTheDocument();
		} );

		it( 'renders the em-dash + "No paid access gate attempts in this timeframe" when denominator is 0', () => {
			render( <MetricCard label="Rate" value={ 0 } format="percent" zeroFallback={ { numerator: 0, denominator: 0, ...PAYWALL } } /> );
			expect( screen.getByLabelText( 'Not applicable' ) ).toHaveTextContent( '—' );
			expect( screen.getByText( 'No paid access gate attempts in this timeframe' ) ).toBeInTheDocument();
		} );

		it( 'falls through to the normal percentage when numerator and denominator are both positive', () => {
			render( <MetricCard label="Rate" value={ 0.5 } format="percent" zeroFallback={ { numerator: 3, denominator: 17, ...PAYWALL } } /> );
			expect( screen.queryByText( /0 of/ ) ).not.toBeInTheDocument();
			expect( screen.queryByLabelText( 'Not applicable' ) ).not.toBeInTheDocument();
		} );
	} );

	describe( 'currency total card (currencyRole="total")', () => {
		it( 'renders "0 conversions" when conversions are 0 and attempts > 0', () => {
			render(
				<MetricCard
					label="Total paid access gate revenue (Direct)"
					value={ 0 }
					format="currency"
					zeroFallback={ { numerator: 0, denominator: 17, currencyRole: 'total', ...PAYWALL } }
				/>
			);
			expect( screen.getByText( '0 conversions' ) ).toBeInTheDocument();
			expect( screen.queryByText( '$0.00' ) ).not.toBeInTheDocument();
		} );

		it( 'renders the em-dash + "No paid access gate attempts in this timeframe" when attempts are 0', () => {
			render(
				<MetricCard
					label="Total"
					value={ 0 }
					format="currency"
					zeroFallback={ { numerator: 0, denominator: 0, currencyRole: 'total', ...PAYWALL } }
				/>
			);
			expect( screen.getByLabelText( 'Not applicable' ) ).toHaveTextContent( '—' );
			expect( screen.getByText( 'No paid access gate attempts in this timeframe' ) ).toBeInTheDocument();
		} );
	} );

	describe( 'currency average card (currencyRole="average")', () => {
		it( 'renders the em-dash + "No conversions in this timeframe" when conversions are 0 but attempts > 0', () => {
			render(
				<MetricCard
					label="Avg revenue per paid access gate conversion"
					value={ 0 }
					format="currency"
					zeroFallback={ { numerator: 0, denominator: 17, currencyRole: 'average', ...PAYWALL } }
				/>
			);
			expect( screen.getByLabelText( 'Not applicable' ) ).toHaveTextContent( '—' );
			expect( screen.getByText( 'No conversions in this timeframe' ) ).toBeInTheDocument();
		} );

		it( 'renders "No paid access gate attempts in this timeframe" when attempts are 0', () => {
			render(
				<MetricCard
					label="Avg"
					value={ 0 }
					format="currency"
					zeroFallback={ { numerator: 0, denominator: 0, currencyRole: 'average', ...PAYWALL } }
				/>
			);
			expect( screen.getByText( 'No paid access gate attempts in this timeframe' ) ).toBeInTheDocument();
		} );
	} );

	it( 'renders a custom plural opportunity label', () => {
		render(
			<MetricCard
				label="Registered access gate conversion"
				value={ 0 }
				format="percent"
				zeroFallback={ { numerator: 0, denominator: 0, attemptsLabel: 'registration gate impressions' } }
			/>
		);
		expect( screen.getByText( 'No registration gate impressions in this timeframe' ) ).toBeInTheDocument();
	} );

	it( 'suppresses the comparison delta when a fallback hero is shown', () => {
		const { container } = render(
			<MetricCard
				label="Rate"
				value={ 0 }
				previousValue={ 0.4 }
				format="percent"
				zeroFallback={ { numerator: 0, denominator: 17, ...PAYWALL } }
			/>
		);
		expect( container.querySelector( '.newspack-insights__metric-card-delta' ) ).toBeNull();
	} );
} );

describe( 'MetricCard notCapableMessage (NPPD-1720)', () => {
	const NUDGE = 'No donation block detected in your active prompts.';

	it( 'renders the em-dash hero + the message as the secondary line', () => {
		render( <MetricCard label="Donation Conversion (Direct)" value={ 0 } format="percent" notCapableMessage={ NUDGE } /> );
		expect( screen.getByLabelText( 'Not applicable' ) ).toHaveTextContent( '—' );
		expect( screen.getByText( NUDGE ) ).toBeInTheDocument();
	} );

	it( 'does not render the metric value', () => {
		render( <MetricCard label="Donation Conversion (Direct)" value={ 0.42 } format="percent" notCapableMessage={ NUDGE } /> );
		expect( screen.queryByText( /%/ ) ).not.toBeInTheDocument();
	} );

	it( 'wins over zeroFallback (structural gap beats a window-bound zero)', () => {
		render(
			<MetricCard
				label="Donation Conversion (Direct)"
				value={ 0 }
				format="percent"
				notCapableMessage={ NUDGE }
				zeroFallback={ { numerator: 0, denominator: 17, attemptsLabel: 'donation prompts' } }
			/>
		);
		expect( screen.getByText( NUDGE ) ).toBeInTheDocument();
		expect( screen.queryByText( '0 of 17' ) ).not.toBeInTheDocument();
	} );

	it( 'yields to the error treatment (error wins over not-capable)', () => {
		render( <MetricCard label="Donation Conversion (Direct)" error="boom" notCapableMessage={ NUDGE } /> );
		expect( screen.getByText( 'Data temporarily unavailable.' ) ).toBeInTheDocument();
		expect( screen.queryByText( NUDGE ) ).not.toBeInTheDocument();
	} );

	it( 'suppresses the comparison delta', () => {
		const { container } = render(
			<MetricCard label="Donation Conversion (Direct)" value={ 0 } previousValue={ 0.4 } format="percent" notCapableMessage={ NUDGE } />
		);
		expect( container.querySelector( '.newspack-insights__metric-card-delta' ) ).toBeNull();
	} );

	it( 'hides the formula description (the nudge replaces it)', () => {
		const description = 'Completed donations ÷ donation-intent prompt impressions';
		render(
			<MetricCard label="Donation Conversion (Direct)" value={ 0 } format="percent" description={ description } notCapableMessage={ NUDGE } />
		);
		expect( screen.getByText( NUDGE ) ).toBeInTheDocument();
		expect( screen.queryByText( description ) ).not.toBeInTheDocument();
	} );
} );

describe( 'MetricCard warming (NEWS-2603)', () => {
	it( 'renders the "still calculating" note in place of the value', () => {
		render( <MetricCard label="Newsletter → subscription" value={ 0 } format="percent" warming /> );
		expect( screen.getByText( 'Still calculating — check back shortly.' ) ).toBeInTheDocument();
		// Not the generic non-computable / em-dash note.
		expect( screen.queryByLabelText( 'Not applicable' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Data temporarily unavailable.' ) ).not.toBeInTheDocument();
	} );

	it( 'yields to the error treatment (error wins over warming)', () => {
		render( <MetricCard label="Newsletter → subscription" error="boom" warming /> );
		expect( screen.getByText( 'Data temporarily unavailable.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Still calculating — check back shortly.' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'MetricCard dataMissing', () => {
	it( 'renders the data-missing note in place of the value', () => {
		render( <MetricCard label="Influenced subscription rate" value={ 0 } format="percent" dataMissing /> );
		expect( screen.getByText( 'Some data could not be loaded.' ) ).toBeInTheDocument();
		// The misleading zero is not shown.
		expect( screen.queryByText( '0%' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'MetricCard notComputableMessage (NPPD-1704)', () => {
	const MESSAGE = 'No donation-intent prompts viewed in this timeframe.';
	const NUDGE = 'No donation block detected in your active prompts.';

	it( 'renders the em-dash hero + the message as the secondary line', () => {
		render( <MetricCard label="Donation Conversion (Direct)" value={ 0 } format="percent" notComputableMessage={ MESSAGE } /> );
		expect( screen.getByLabelText( 'Not applicable' ) ).toHaveTextContent( '—' );
		expect( screen.getByText( MESSAGE ) ).toBeInTheDocument();
	} );

	it( 'does not render the metric value', () => {
		render( <MetricCard label="Donation Conversion (Direct)" value={ 0.42 } format="percent" notComputableMessage={ MESSAGE } /> );
		expect( screen.queryByText( /%/ ) ).not.toBeInTheDocument();
	} );

	it( 'yields to not-capable (add-the-block beats wait-for-data)', () => {
		render(
			<MetricCard
				label="Donation Conversion (Direct)"
				value={ 0 }
				format="percent"
				notCapableMessage={ NUDGE }
				notComputableMessage={ MESSAGE }
			/>
		);
		expect( screen.getByText( NUDGE ) ).toBeInTheDocument();
		expect( screen.queryByText( MESSAGE ) ).not.toBeInTheDocument();
	} );

	it( 'wins over zeroFallback (capable-but-no-inputs beats a bare zero count)', () => {
		render(
			<MetricCard
				label="Donation Conversion (Direct)"
				value={ 0 }
				format="percent"
				notComputableMessage={ MESSAGE }
				zeroFallback={ { numerator: 0, denominator: 17, attemptsLabel: 'donation prompts' } }
			/>
		);
		expect( screen.getByText( MESSAGE ) ).toBeInTheDocument();
		expect( screen.queryByText( '0 of 17' ) ).not.toBeInTheDocument();
	} );

	it( 'yields to the error treatment (error wins over not-computable)', () => {
		render( <MetricCard label="Donation Conversion (Direct)" error="boom" notComputableMessage={ MESSAGE } /> );
		expect( screen.getByText( 'Data temporarily unavailable.' ) ).toBeInTheDocument();
		expect( screen.queryByText( MESSAGE ) ).not.toBeInTheDocument();
	} );

	it( 'suppresses the comparison delta', () => {
		const { container } = render(
			<MetricCard label="Donation Conversion (Direct)" value={ 0 } previousValue={ 0.4 } format="percent" notComputableMessage={ MESSAGE } />
		);
		expect( container.querySelector( '.newspack-insights__metric-card-delta' ) ).toBeNull();
	} );

	it( 'hides the formula description (the message replaces it)', () => {
		const description = 'Completed donations ÷ donation-intent prompt impressions';
		render(
			<MetricCard
				label="Donation Conversion (Direct)"
				value={ 0 }
				format="percent"
				description={ description }
				notComputableMessage={ MESSAGE }
			/>
		);
		expect( screen.getByText( MESSAGE ) ).toBeInTheDocument();
		expect( screen.queryByText( description ) ).not.toBeInTheDocument();
	} );
} );
