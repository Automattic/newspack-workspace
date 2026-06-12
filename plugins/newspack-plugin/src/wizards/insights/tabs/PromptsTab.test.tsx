/**
 * Tab-level tests for PromptsTab (NPPD-1607).
 *
 * Confirms the tab renders the full section structure under the state
 * envelope: scalars in 'populated' state render their card chrome,
 * collection metrics ('empty' state) render their empty-state copy, and
 * the tab-level error banner appears when `tab_error` is set.
 * Loading + error states are exercised separately.
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
	caution: 'caution',
} ) );

const mockHook = usePromptsData as jest.Mock;
const range = { start: '2026-05-09', end: '2026-06-08', preset: 'last-30' } as unknown as DateRange;

const scalar = ( placeholder_type: PromptsPlaceholderType ): PromptsScalarMetric => ( {
	state: 'populated',
	value: 'decimal' === placeholder_type ? 0.0 : 0,
	computable: false,
	denominator: null,
	placeholder_type,
} );

const errorScalar = ( placeholder_type: PromptsPlaceholderType ): PromptsScalarMetric => ( {
	state: 'error',
	value: 0,
	computable: false,
	denominator: null,
	placeholder_type,
	error_code: 'bq_unavailable',
	error_message: 'BigQuery is unavailable.',
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
		state: 'empty',
		stages: [],
	},
	exposures_distribution: {
		state: 'empty',
		buckets: [],
	},
	performance_by_prompt: { state: 'empty', rows: [] },
	performance_by_intent: { state: 'empty', rows: [] },
	performance_by_placement: { state: 'empty', rows: [] },
} );

const makeResponse = (): PromptsResponse => ( {
	tab_error: false,
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

	it( 'renders all seven section headings + the explainer when scalars are populated', () => {
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

	it( 'renders the funnel + distribution empty-state copy when those sections are empty', () => {
		mockSuccess();
		render( <PromptsTab range={ range } previousRange={ null } /> );

		expect(
			screen.getByText( 'No funnel data yet. The funnel will populate once readers begin moving through your prompts.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'No distribution data yet. This will populate once readers begin converting.' ) ).toBeInTheDocument();
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

	it( 'renders the tab-level error banner when tab_error is true', () => {
		const response = makeResponse();
		response.tab_error = true;
		// Flip every scalar to the error state so the per-card treatments line up
		// with the banner; the banner itself only depends on `tab_error`.
		Object.keys( response.current ).forEach( key => {
			const metric = ( response.current as unknown as Record< string, { state?: string; placeholder_type?: PromptsPlaceholderType } > )[ key ];
			if ( metric && 'placeholder_type' in metric && metric.placeholder_type ) {
				( response.current as unknown as Record< string, PromptsScalarMetric > )[ key ] = errorScalar( metric.placeholder_type );
			}
		} );
		response.current.conversion_funnel = {
			state: 'error',
			stages: [],
			error_code: 'bq_unavailable',
			error_message: 'BigQuery is unavailable.',
		};
		response.current.exposures_distribution = {
			state: 'error',
			buckets: [],
			error_code: 'bq_unavailable',
			error_message: 'BigQuery is unavailable.',
		};
		response.current.performance_by_prompt = {
			state: 'error',
			rows: [],
			error_code: 'bq_unavailable',
			error_message: 'BigQuery is unavailable.',
		};
		response.current.performance_by_intent = {
			state: 'error',
			rows: [],
			error_code: 'bq_unavailable',
			error_message: 'BigQuery is unavailable.',
		};
		response.current.performance_by_placement = {
			state: 'error',
			rows: [],
			error_code: 'bq_unavailable',
			error_message: 'BigQuery is unavailable.',
		};
		mockHook.mockReturnValue( { status: 'success', error: null, refetch: () => {}, data: response } );

		render( <PromptsTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Unable to load this tab.' ) ).toBeInTheDocument();
		// Per-section error treatment renders the shared "Unable to load this section." copy.
		expect( screen.getAllByText( 'Unable to load this section. Newspack Manager may need attention.' ).length ).toBeGreaterThan( 0 );
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
