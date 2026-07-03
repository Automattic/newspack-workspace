/**
 * Donors API client (NPPD-1617).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 7
 * endpoint: `GET /newspack-insights/v1/donors`. Type definitions
 * mirror the PHP response shape assembled by
 * `Donors_REST_Controller`.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { type CachedEnvelope } from '../state/insightsCache';

export type StorageBackend = 'hpos' | 'legacy';

export interface DonorsClassification {
	backend: StorageBackend;
	donation_product_count: number;
	has_donation_family: boolean;
}

export interface UpcomingDonationRenewals {
	count: number;
	total_value: number;
}

export interface UpcomingDonationCancellations {
	count: number;
	total_value: number;
}

export interface DonorsSnapshot {
	active_donors: number;
	active_recurring_donors: number;
	donation_mrr: number;
	donation_arr: number;
	upcoming_donation_renewals_30d: UpcomingDonationRenewals;
	upcoming_donation_cancellations_30d: UpcomingDonationCancellations;
	/** Newsletter → donation conversion (NEWS-2603): mature-cohort snapshot rate. */
	newsletter_conversion: DonorsRateValue;
	/** Modeled 3-year supporter CLV (NEWS-2603): snapshot currency value ({value, computable, denominator}). */
	supporter_clv_3yr: DonorsRateValue;
}

/**
 * A product's billing nature, tracked as two INDEPENDENT flags rather
 * than one enum. Derived server-side from each contributing row's
 * `_subscription_period` meta. A leaf variation is purely one nature
 * (exactly one flag set); a variable parent latches EACH flag as its
 * variation rows land, so a product that is recurring AND took a
 * one-time (bare-parent) gift carries both. The UI shows a column iff
 * its flag is set, rendering the rest as em-dashes instead of
 * misleading zeros.
 */
export interface BillingNature {
	has_recurring: boolean;
	has_one_time: boolean;
}

/**
 * A rate metric whose denominator may legitimately be zero. The UI
 * uses `computable` to decide between rendering the value and a
 * "no data yet" empty state, and surfaces `denominator` inline as
 * context when the value is real but the cohort is small.
 */
export interface DonorsRateValue {
	value: number;
	computable: boolean;
	denominator: number;
	/** `'error'` when the hub proxy failed — distinct from a genuine non-computable (insufficient-data) result. */
	state?: string;
}

export interface DonorsTierVariationRow extends BillingNature {
	variation_id: number;
	label: string;
	active_recurring_donors: number;
	lapsed_donors_in_window: number;
	new_donors_in_window: number;
	one_time_gifts_in_window: number;
	recurring_revenue_in_window: number;
	lifetime_donation_revenue: number;
}

export interface DonorsTierRow extends BillingNature {
	product_id: number;
	name: string;
	is_parent: boolean;
	active_recurring_donors: number;
	lapsed_donors_in_window: number;
	new_donors_in_window: number;
	one_time_gifts_in_window: number;
	recurring_revenue_in_window: number;
	lifetime_donation_revenue: number;
	/** Present only when `is_parent` is true. Sorted by lifetime_donation_revenue descending. */
	variations?: DonorsTierVariationRow[];
}

/**
 * One "Donations by campaign" row (NEWS-2580). Shape emitted by the shared
 * Metric_Grouping fold: campaign value, donation count, attributed revenue, and
 * the untagged flag for the trailing "(no campaign)" denominator row.
 */
export interface DonorsCampaignRow {
	/** Campaign name, or the localized "(no campaign)" untagged label. */
	value: string;
	/** Donations attributed to this campaign in the window. */
	count: number;
	/** Attributed donation-order revenue for this campaign in the window. */
	amount: number;
	/** True for the trailing untagged "(no campaign)" denominator row. */
	is_untagged: boolean;
}

export interface DonorsWindow {
	window: { start: string; end: string };
	new_donors: number;
	lapsed_donors: number;
	one_time_revenue: number;
	recurring_revenue: number;
	total_revenue: number;
	average_gift: number;
	/**
	 * Lapsed-donor recovery rate.
	 *
	 * `computable: false` when the prior-window lapsed cohort is
	 * empty (no donors to recover) — UI renders an empty state.
	 * `denominator` is surfaced in the subtitle so small-cohort 0%
	 * reads as "0% (0 of N donors)" rather than bare 0%.
	 */
	lapsed_donor_recovery_rate: DonorsRateValue;
	/**
	 * Recurring-donor retention. Same shape and UI contract as
	 * `lapsed_donor_recovery_rate`.
	 */
	recurring_donor_retention: DonorsRateValue;
	donations_by_tier: DonorsTierRow[];
	/**
	 * Donations grouped by `utm_campaign` (NEWS-2580). Ranked count desc; the
	 * trailing `is_untagged` "(no campaign)" row is the untagged denominator.
	 * Coverage is the UTM-tagged subset — see formulas/tab-7-donors.md.
	 */
	donations_by_campaign: DonorsCampaignRow[];
	/**
	 * Derived empty-state signal (NPPD-1696): true when the window saw
	 * any donation activity (revenue, a new donor, or a lapse). Drives
	 * the WindowedSection's whole-section `no_opportunity` empty state.
	 * Derived server-side from values already computed — no extra query.
	 */
	has_window_activity: boolean;
}

export interface DonorsResponse {
	classification: DonorsClassification;
	snapshot: DonorsSnapshot;
	current: DonorsWindow;
	previous: DonorsWindow | null;
}

export interface DonorsQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/donors';

const queryString = ( query: DonorsQuery ): string => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return params.toString();
};

/**
 * Fetch Tab 7 data for the given window pair.
 */
export const fetchDonorsData = async ( query: DonorsQuery ): Promise< CachedEnvelope< DonorsResponse > > =>
	apiFetch< CachedEnvelope< DonorsResponse > >( {
		path: `${ ENDPOINT }?${ queryString( query ) }`,
		method: 'GET',
	} );

export const refreshDonorsData = async ( query: DonorsQuery ): Promise< CachedEnvelope< DonorsResponse > > =>
	apiFetch< CachedEnvelope< DonorsResponse > >( {
		path: `${ ENDPOINT }/refresh?${ queryString( query ) }`,
		method: 'POST',
	} );
