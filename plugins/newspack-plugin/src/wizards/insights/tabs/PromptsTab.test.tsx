/**
 * Tab-level tests for PromptsTab (NPPD-1607, Phase 1).
 *
 * Phase 1 ships the whole tab against placeholder (`pending: true`)
 * data. These tests confirm the tab renders the full section
 * structure without crashing when every metric is pending, that the
 * funnel / distribution / performance tables render their zero / empty
 * states, and that loading + error states are handled. A snapshot pins
 * the section structure, card titles, table headers, and explainer
 * copy against the spec.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PromptsTab from './PromptsTab';
import usePromptsData from '../hooks/usePromptsData';
import type { PromptsPlaceholderType, PromptsResponse, PromptsScalarMetric, PromptsWindow } from '../api/prompts';
import type { DateRange } from '../state/useDateRange';

jest.mock( '../hooks/usePromptsData' );

// `@wordpress/icons` ships pre-built SVG element trees created against its own
// bundled React copy; under the test runner's React they trip React's
// "element from an older version of React" guard. The icons are decorative
// here (no assertion depends on them), so stub the module.
jest.mock( '@wordpress/icons', () => ( {
	__esModule: true,
	Icon: () => null,
	info: 'info',
	closeSmall: 'closeSmall',
	chevronUp: 'chevronUp',
	chevronDown: 'chevronDown',
} ) );

const mockHook = usePromptsData as jest.Mock;
const range = { start: '2026-05-09', end: '2026-06-08', preset: 'last-30' } as unknown as DateRange;

const scalar = ( placeholder_type: PromptsPlaceholderType ): PromptsScalarMetric => ( {
	value: 'decimal' === placeholder_type ? 0.0 : 0,
	computable: false,
	pending: true,
	denominator: null,
	placeholder_type,
} );

const makeWindow = (): PromptsWindow => ( {
	window: { start: '2026-05-09', end: '2026-06-08' },
	total_prompt_impressions: scalar( 'count' ),
	unique_readers_reached: scalar( 'count' ),
	avg_prompts_per_reader: scalar( 'decimal' ),
	click_through_rate: scalar( 'rate' ),
	form_submission_rate: scalar( 'rate' ),
	dismissal_rate: scalar( 'rate' ),
	registration_conversion_direct: scalar( 'rate' ),
	registration_conversion_influenced_7d: scalar( 'rate' ),
	newsletter_signup_conversion_direct: scalar( 'rate' ),
	newsletter_signup_conversion_influenced_7d: scalar( 'rate' ),
	donation_conversion_direct: scalar( 'rate' ),
	donation_conversion_influenced_14d: scalar( 'rate' ),
	subscription_conversion_direct: scalar( 'rate' ),
	subscription_conversion_influenced_14d: scalar( 'rate' ),
	donation_revenue_direct: scalar( 'currency' ),
	donation_revenue_influenced_14d: scalar( 'currency' ),
	subscription_revenue_direct: scalar( 'currency' ),
	subscription_revenue_influenced_14d: scalar( 'currency' ),
	conversion_funnel: {
		pending: true,
		stages: [
			{ label: 'Impression', count: 0, pct_of_top: 0 },
			{ label: 'Engagement', count: 0, pct_of_top: 0 },
			{ label: 'Conversion', count: 0, pct_of_top: 0 },
		],
	},
	exposures_distribution: {
		pending: true,
		buckets: [
			{ label: '1 exposure', count: 0, pct: 0 },
			{ label: '2 exposures', count: 0, pct: 0 },
			{ label: '3–5 exposures', count: 0, pct: 0 },
			{ label: '6+ exposures', count: 0, pct: 0 },
		],
	},
	performance_by_prompt: { pending: true, rows: [] },
	performance_by_intent: { pending: true, rows: [] },
	performance_by_placement: { pending: true, rows: [] },
} );

const makeResponse = (): PromptsResponse => ( {
	tab_pending: true,
	current: makeWindow(),
	previous: null,
} );

const mockSuccess = () =>
	mockHook.mockReturnValue( {
		status: 'success',
		error: null,
		refetch: () => {},
		data: makeResponse(),
	} );

describe( 'PromptsTab', () => {
	afterEach( () => {
		mockHook.mockReset();
	} );

	it( 'renders all seven section headings + the explainer when every metric is pending', () => {
		mockSuccess();
		render( <PromptsTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'About Direct vs Influenced conversion' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Prompt exposure' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Prompt engagement' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Free reader conversion' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Paid reader conversion' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Revenue from prompts' ) ).toBeInTheDocument();
		expect( screen.getByText( 'How readers convert' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Performance breakdown' ) ).toBeInTheDocument();
	} );

	it( 'renders scorecard titles across the four format types', () => {
		mockSuccess();
		render( <PromptsTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Total Prompt Impressions' ) ).toBeInTheDocument(); // count
		expect( screen.getByText( 'Avg Prompts per Reader' ) ).toBeInTheDocument(); // decimal
		expect( screen.getByText( 'Click-Through Rate' ) ).toBeInTheDocument(); // rate
		expect( screen.getByText( 'Donation Revenue (Direct)' ) ).toBeInTheDocument(); // currency
	} );

	it( 'renders the funnel empty state and the distribution four buckets when all-zero', () => {
		mockSuccess();
		render( <PromptsTab range={ range } previousRange={ null } /> );

		// Phase 1 funnel data is all-zero; the SVG funnel needs a non-zero top step
		// to chart proportions, so it shows its empty-state copy (matching Gates).
		expect( screen.getByText( 'Not enough data to chart the funnel.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Impression' ) ).not.toBeInTheDocument();

		// The distribution still renders its four buckets at 0 / 0%.
		expect( screen.getByText( '1 exposure' ) ).toBeInTheDocument();
		expect( screen.getByText( '2 exposures' ) ).toBeInTheDocument();
		expect( screen.getByText( '3–5 exposures' ) ).toBeInTheDocument();
		expect( screen.getByText( '6+ exposures' ) ).toBeInTheDocument();
	} );

	it( 'renders the three performance tables empty-state rows when rows are empty', () => {
		mockSuccess();
		render( <PromptsTab range={ range } previousRange={ null } /> );

		expect(
			screen.getByText( 'No prompt data yet. Performance metrics will appear once readers begin interacting with your prompts.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'No prompt data yet. Intent performance will appear once readers begin interacting with your prompts.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'No prompt data yet. Placement performance will appear once readers begin interacting with your prompts.' )
		).toBeInTheDocument();
	} );

	it( 'renders the loading state', () => {
		mockHook.mockReturnValue( { status: 'loading', data: null, error: null, refetch: () => {} } );
		render( <PromptsTab range={ range } previousRange={ null } /> );
		// Now routed through the shared TabStateView loading frame (NPPD-1684).
		expect( screen.getByText( 'Loading…' ) ).toBeInTheDocument();
	} );

	it( 'renders the error state', () => {
		mockHook.mockReturnValue( { status: 'error', data: null, error: 'Boom', refetch: () => {} } );
		render( <PromptsTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( 'Could not load prompt data.' ) ).toBeInTheDocument();
	} );

	it( 'matches the full-tab placeholder snapshot', () => {
		mockSuccess();
		const { container } = render( <PromptsTab range={ range } previousRange={ null } /> );
		expect( container ).toMatchSnapshot();
	} );
} );
