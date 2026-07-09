/**
 * Tests for the merged, field-driven Conversions column in the Performance by
 * prompt table (Table 7.1). The old six per-type conversion columns collapse
 * into one column that stacks only the conversion types a prompt actually drove
 * — including the register-then-pay case where a registration-intent prompt also
 * logs subscription conversions (attributed to the popup by id, not action_type).
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PerformanceByPromptTable from './PerformanceByPromptTable';
import type { PromptsPerformanceByPromptRow, PromptsPerformanceByPromptTable as TableData } from '../../../api/prompts';

const makeRow = ( overrides: Partial< PromptsPerformanceByPromptRow > ): PromptsPerformanceByPromptRow => ( {
	popup_id: 1,
	prompt_title: 'A prompt',
	intent: 'donation',
	intent_label: 'Donation',
	placement: 'overlay',
	impressions: 1000,
	unique_viewers: 800,
	ctr: 0.05,
	form_submission_rate: 0.04,
	dismissal_rate: 0.1,
	registrations: 0,
	newsletter_signups: 0,
	donation_conversions: null,
	donation_conversion_rate: null,
	subscription_conversions: null,
	subscription_conversion_rate: null,
	...overrides,
} );

const makeData = ( rows: PromptsPerformanceByPromptRow[] ): TableData => ( { state: 'populated', rows } );

describe( 'PerformanceByPromptTable — merged Conversions column', () => {
	it( 'renders one merged Conversions column, not the per-type columns', () => {
		render( <PerformanceByPromptTable data={ makeData( [ makeRow( {} ) ] ) } /> );
		expect( screen.getByText( 'Conversions' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Donation conversions' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Subscription conversion rate' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Newsletter signups' ) ).not.toBeInTheDocument();
	} );

	it( 'shows a donation prompt as a single count + rate line', () => {
		const { container } = render(
			<PerformanceByPromptTable data={ makeData( [ makeRow( { donation_conversions: 65, donation_conversion_rate: 0.016 } ) ] ) } />
		);
		expect( container ).toHaveTextContent( 'Donation 65 (1.6%)' );
	} );

	it( 'stacks both lines for a register-then-pay prompt (registrations + subscription conversions)', () => {
		const { container } = render(
			<PerformanceByPromptTable
				data={ makeData( [
					makeRow( {
						intent: 'registration',
						intent_label: 'Registration',
						registrations: 84,
						subscription_conversions: 18,
						subscription_conversion_rate: 0.006,
					} ),
				] ) }
			/>
		);
		expect( container ).toHaveTextContent( 'Registrations 84' );
		expect( container ).toHaveTextContent( 'Subscription 18 (0.6%)' );
	} );

	it( 'renders the not-applicable em-dash when a prompt drove no conversions', () => {
		render( <PerformanceByPromptTable data={ makeData( [ makeRow( { ctr: 0.05, form_submission_rate: 0.04, dismissal_rate: 0.1 } ) ] ) } /> );
		// registrations / newsletter are 0 and donation / subscription are null, so
		// the merged cell falls back to the shared not-applicable em-dash.
		expect( screen.getAllByLabelText( 'Not applicable' ).length ).toBeGreaterThan( 0 );
	} );
} );
