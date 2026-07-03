/**
 * Tests for CohortRetentionSection (Section 5).
 *
 * Covers:
 *   - Section structure (heading, both cohort titles)
 *   - coming_soon treatment (default fixture)
 *   - empty treatment
 *   - error treatment
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import CohortRetentionSection from './CohortRetentionSection';
import { makeConversionWindow } from './fixtures';
import type { ConversionCohortData, ConversionReferenceLine } from '../../api/conversion';

const populatedCohort = ( referenceLine: ConversionReferenceLine | null ): ConversionCohortData => ( {
	state: 'populated',
	cohorts: [
		{
			label: '2026-01',
			points: [
				{ period: 0, value: 0 },
				{ period: 1, value: 0.02 },
			],
		},
	],
	reference_line: referenceLine,
} );

describe( 'CohortRetentionSection', () => {
	it( 'renders the heading and both cohort titles', () => {
		render( <CohortRetentionSection current={ makeConversionWindow() } /> );
		expect( screen.getByRole( 'heading', { name: 'Cohort retention' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Registration → conversion' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Subscriber retention' ) ).toBeInTheDocument();
	} );

	it( 'renders the cohort coming_soon message for both charts', () => {
		render( <CohortRetentionSection current={ makeConversionWindow( { cohortState: 'coming_soon' } ) } /> );
		expect(
			screen.getAllByText( 'Cohort data is being prepared. Check back in a few minutes, then click Refresh now to load it.' )
		).toHaveLength( 2 );
	} );

	it( 'renders the empty treatment when state is empty', () => {
		render( <CohortRetentionSection current={ makeConversionWindow( { cohortState: 'empty' } ) } /> );
		expect( screen.getAllByText( 'No cohort data available yet.' ) ).toHaveLength( 2 );
	} );

	it( 'renders the error treatment when state is error', () => {
		render( <CohortRetentionSection current={ makeConversionWindow( { cohortState: 'error' } ) } /> );
		expect( screen.getAllByRole( 'alert' ) ).toHaveLength( 2 );
		expect( screen.getAllByText( /Unable to load this section/ ) ).toHaveLength( 2 );
	} );

	it( 'shows the 5.2 target callout but suppresses the 5.1 one even when present', () => {
		const current = {
			...makeConversionWindow( { cohortState: 'populated' } ),
			// 5.1 deliberately carries a value to prove the parent never wires a 5.1
			// target callout, independent of payload (real payload is null).
			registration_to_conversion_cohort: populatedCohort( { value: 0.15, label: '15% at 6 months' } ),
			subscriber_retention_cohort: populatedCohort( { value: 0.7, label: '70% at 12 months' } ),
		};
		const { container } = render( <CohortRetentionSection current={ current } /> );
		expect( screen.getByText( /70% at 12 months/ ) ).toBeInTheDocument();
		expect( screen.queryByText( /15% at 6 months/ ) ).not.toBeInTheDocument();
		// Exactly one heatmap renders a target callout (the 5.2 retention chart).
		expect( container.querySelectorAll( '.newspack-insights__cohort-heatmap-target' ) ).toHaveLength( 1 );
	} );

	it( 'renders a cohort-matrix grid per chart for populated cohorts', () => {
		const current = {
			...makeConversionWindow( { cohortState: 'populated' } ),
			registration_to_conversion_cohort: populatedCohort( null ),
			subscriber_retention_cohort: populatedCohort( { value: 0.7, label: '70% at 12 months' } ),
		};
		const { container } = render( <CohortRetentionSection current={ current } /> );
		// One heatmap table per cohort chart (5.1 and 5.2).
		expect( container.querySelectorAll( '.newspack-insights__cohort-heatmap-table' ) ).toHaveLength( 2 );
		// The 0.02 cohort value is shaded and printed as a percentage cell.
		expect( screen.getAllByText( '2%' ).length ).toBeGreaterThan( 0 );
	} );
} );
