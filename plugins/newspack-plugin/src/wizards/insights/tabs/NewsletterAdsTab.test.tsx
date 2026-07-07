/**
 * Tab-level tests for NewsletterAdsTab (NPPD-1861): the visibility gate, the
 * not-ready inline-notice behavior (lifetime section still renders — the
 * deliberate divergence from AdvertisingTab), and the ready / error states.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import NewsletterAdsTab from './NewsletterAdsTab';
import useNewsletterAdsData from '../hooks/useNewsletterAdsData';
import type { DateRange } from '../state/useDateRange';
import type { NewsletterAdsWindow } from '../api/newsletter_ads';

jest.mock( '../hooks/useNewsletterAdsData' );

const mockHook = useNewsletterAdsData as jest.Mock;
const range = { start: '2026-06-01', end: '2026-06-30', preset: 'last-30' } as unknown as DateRange;

const readyMetrics: NewsletterAdsWindow[ 'metrics' ] = {
	lifetime_impressions: { value: 950000, computable: true, type: 'count' },
	lifetime_clicks: { value: 12400, computable: true, type: 'count' },
	lifetime_ctr: { value: 0.013, computable: true, type: 'rate', numerator: 12400, denominator: 950000 },
	total_impressions: { value: 84000, computable: true, type: 'count' },
	total_clicks: { value: 1200, computable: true, type: 'count' },
	ctr: { value: 0.0143, computable: true, type: 'rate', numerator: 1200, denominator: 84000 },
	total_revenue: { value: 4200, computable: true, type: 'currency' },
	ecpm: { value: 50, computable: true, type: 'currency' },
	active_ads: { value: 7, computable: true, type: 'count' },
	revenue_excluded_ads: { value: 0, computable: true, type: 'count' },
	performance_by_day: {
		type: 'timeseries',
		computable: true,
		rows: [
			{ date: '2026-06-01', impressions: 4000, clicks: 60 },
			{ date: '2026-06-02', impressions: 4400, clicks: 66 },
		],
	},
	top_ads: {
		type: 'table',
		computable: true,
		rows: [ { ad_id: 12, title: 'Summer Sale', advertiser: 'Acme Hardware', impressions: 40000, clicks: 600, ctr: 0.015, revenue: 2100 } ],
	},
	top_advertisers: {
		type: 'table',
		computable: true,
		rows: [ { advertiser: 'Acme Hardware', ads: 3, impressions: 60000, clicks: 800, ctr: 0.0133, revenue: 3000 } ],
	},
	by_newsletter: {
		type: 'table',
		computable: true,
		rows: [ { newsletter_id: 991, title: 'Weekly Digest', sent_date: '2026-06-02', ads: 2, impressions: 8400, clicks: 120, ctr: 0.0143 } ],
	},
};

const baseWindow = ( overrides: Partial< NewsletterAdsWindow > = {} ): NewsletterAdsWindow => ( {
	is_tab_visible: true,
	is_report_ready: true,
	readiness_issues: [],
	data_as_of: '2026-06-29',
	has_window_activity: true,
	is_loading: false,
	metrics: readyMetrics,
	...overrides,
} );

const mockData = ( current: NewsletterAdsWindow ) =>
	mockHook.mockReturnValue( {
		status: 'success',
		data: { current, previous: null },
		error: null,
		refetch: () => {},
		computedAt: null,
		source: null,
		cooldownUntil: null,
	} );

describe( 'NewsletterAdsTab', () => {
	afterEach( () => {
		mockHook.mockReset();
	} );

	it( 'renders nothing when the tab is not visible', () => {
		mockData( baseWindow( { is_tab_visible: false } ) );
		const { container } = render( <NewsletterAdsTab range={ range } previousRange={ null } /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'still renders the Overview (lifetime cards) with an inline notice when the report is not ready', () => {
		// Mirror the real not-ready envelope: the orchestrator still emits every
		// timeframe metric with `computable: false` (so the Overview renders N/A
		// cards) and OMITS `has_window_activity` entirely.
		const notReadyWindow = baseWindow( {
			is_report_ready: false,
			readiness_issues: [
				{
					code: 'newsletter_ads_stats_missing',
					message: 'Newsletter ad statistics have not been recorded yet.',
					remediation_url: 'http://example.test/newsletter-ads',
				},
			],
			metrics: {
				lifetime_impressions: { value: 950000, computable: true, type: 'count' },
				lifetime_clicks: { value: 12400, computable: true, type: 'count' },
				lifetime_ctr: { value: 0.013, computable: true, type: 'rate', numerator: 12400, denominator: 950000 },
				total_impressions: { value: 0, computable: false, type: 'count' },
				total_clicks: { value: 0, computable: false, type: 'count' },
				ctr: { value: 0, computable: false, type: 'rate', numerator: 0, denominator: 0 },
				total_revenue: { value: 0, computable: false, type: 'currency' },
				ecpm: { value: 0, computable: false, type: 'currency' },
				active_ads: { value: 0, computable: false, type: 'count' },
				revenue_excluded_ads: { value: 0, computable: false, type: 'count' },
				performance_by_day: { type: 'timeseries', computable: false, rows: [] },
				top_ads: { type: 'table', computable: false, rows: [] },
				top_advertisers: { type: 'table', computable: false, rows: [] },
				by_newsletter: { type: 'table', computable: false, rows: [] },
			},
		} );
		delete notReadyWindow.has_window_activity;
		mockData( notReadyWindow );
		render( <NewsletterAdsTab range={ range } previousRange={ null } /> );

		// The inline notice carries the readiness issue…
		expect( screen.getByText( 'Finish setting up newsletter ad tracking to see timeframe data' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Newsletter ad statistics have not been recorded yet.' ) ).toBeInTheDocument();
		// …but — unlike AdvertisingTab — the tab body is NOT replaced: the Overview
		// section renders with the all-time lifetime cards.
		expect( screen.getByText( 'Reach & revenue' ) ).toBeInTheDocument();
		expect( screen.getByText( 'All-time impressions' ) ).toBeInTheDocument();
		expect( screen.getByText( '950,000' ) ).toBeInTheDocument();
		expect( screen.getByText( '12,400' ) ).toBeInTheDocument();
		// The non-computable timeframe cards render the em-dash treatment with the
		// remediation copy — never fake zeros.
		expect( screen.getAllByText( 'Requires the latest Newspack Newsletters plugin.' ).length ).toBeGreaterThan( 0 );
		// The timeframe-scoped sections stay withheld…
		expect( screen.queryByText( 'Performance trend' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Top performers' ) ).not.toBeInTheDocument();
		// …and the absent has_window_activity signal must NOT collapse the
		// Overview into the empty state (the lifetime cards carry the signal).
		expect( document.querySelector( '[data-empty-state]' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the tracking-disabled issue as an informational notice without withholding any section', () => {
		mockData(
			baseWindow( {
				readiness_issues: [
					{
						code: 'newsletter_ads_tracking_disabled',
						message: 'Newsletter ad tracking is currently disabled.',
						remediation_url: 'http://example.test/settings',
					},
				],
			} )
		);
		render( <NewsletterAdsTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Newsletter ad tracking is currently disabled.' ) ).toBeInTheDocument();
		// Not presented as a "finish connecting" blocker…
		expect( screen.queryByText( 'Finish setting up newsletter ad tracking to see timeframe data' ) ).not.toBeInTheDocument();
		// …and every section still renders.
		expect( screen.getByText( 'Reach & revenue' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Performance trend' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Top performers' ) ).toBeInTheDocument();
	} );

	it( 'renders all sections with values when ready', () => {
		mockData( baseWindow() );
		render( <NewsletterAdsTab range={ range } previousRange={ null } /> );

		expect( screen.getByText( 'Reach & revenue' ) ).toBeInTheDocument();
		expect( screen.getByText( '84,000' ) ).toBeInTheDocument();
		expect( screen.getByText( '$4,200' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Performance trend' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Summer Sale' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Weekly Digest' ) ).toBeInTheDocument();
		// No "About this data / data as of" callout: this tab reads local data
		// with no lag, so the indicator was deliberately removed (NPPD-1861).
		expect( screen.queryByText( /Data as of/ ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /About this data/ ) ).not.toBeInTheDocument();
	} );

	it( 'collapses the Overview to no_opportunity on a resolved ready window with no activity', () => {
		mockData(
			baseWindow( {
				has_window_activity: false,
				metrics: {
					...readyMetrics,
					total_impressions: { value: 0, computable: true, type: 'count' },
					total_clicks: { value: 0, computable: true, type: 'count' },
				},
			} )
		);
		const { container } = render( <NewsletterAdsTab range={ range } previousRange={ null } /> );

		expect( container.querySelector( '[data-empty-state="no_opportunity"]' ) ).toBeInTheDocument();
		expect( container ).toHaveTextContent( 'No newsletter ad activity in this timeframe' );
	} );

	it( 'shows the error state with detail when the fetch fails', () => {
		mockHook.mockReturnValue( {
			status: 'error',
			data: null,
			error: 'HTTP 500',
			refetch: () => {},
			computedAt: null,
			source: null,
			cooldownUntil: null,
		} );
		render( <NewsletterAdsTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( 'Could not load newsletter ads data.' ) ).toBeInTheDocument();
		expect( screen.getByText( 'HTTP 500' ) ).toBeInTheDocument();
	} );

	it( 'shows the initial loading state before any data arrives', () => {
		mockHook.mockReturnValue( {
			status: 'loading',
			data: null,
			error: null,
			refetch: () => {},
			computedAt: null,
			source: null,
			cooldownUntil: null,
		} );
		render( <NewsletterAdsTab range={ range } previousRange={ null } /> );
		expect( screen.getByText( 'Loading…' ) ).toBeInTheDocument();
	} );
} );
