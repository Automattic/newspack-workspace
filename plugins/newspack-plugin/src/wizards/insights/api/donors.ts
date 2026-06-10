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
}

/**
 * Whether a product is sold as recurring or one-time. Derived
 * server-side from the product's `_subscription_period` meta. The
 * UI uses this to render cells that don't apply to the product's
 * billing model as em-dashes instead of misleading zeros.
 */
export type BillingModel = 'recurring' | 'one_time';

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
}

export interface DonorsTierVariationRow {
	variation_id: number;
	label: string;
	billing_model: BillingModel;
	active_recurring_donors: number;
	lapsed_donors_in_window: number;
	new_donors_in_window: number;
	one_time_gifts_in_window: number;
	recurring_revenue_in_window: number;
	lifetime_donation_revenue: number;
}

export interface DonorsTierRow {
	product_id: number;
	name: string;
	is_parent: boolean;
	/**
	 * For variable subscription parents, this is `recurring` if ANY
	 * variation is recurring (the canonical Newspack donation shape).
	 */
	billing_model: BillingModel;
	active_recurring_donors: number;
	lapsed_donors_in_window: number;
	new_donors_in_window: number;
	one_time_gifts_in_window: number;
	recurring_revenue_in_window: number;
	lifetime_donation_revenue: number;
	/** Present only when `is_parent` is true. Sorted by lifetime_donation_revenue descending. */
	variations?: DonorsTierVariationRow[];
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

/**
 * Fetch Tab 7 data for the given window pair.
 */
export const fetchDonorsData = async ( query: DonorsQuery ): Promise< DonorsResponse > => {
	const params = new URLSearchParams();
	params.set( 'start', query.start );
	params.set( 'end', query.end );
	if ( query.compare_start && query.compare_end ) {
		params.set( 'compare_start', query.compare_start );
		params.set( 'compare_end', query.compare_end );
	}
	return apiFetch< DonorsResponse >( {
		path: `${ ENDPOINT }?${ params.toString() }`,
		method: 'GET',
	} );
};
