/**
 * Subscribers API client (NPPD-1616).
 *
 * Thin wrapper around `@wordpress/api-fetch` for the single Tab 6
 * endpoint: `GET /newspack-insights/v1/subscribers`. Type definitions
 * here are the source of truth for the React layer and mirror the PHP
 * response shape assembled by `Subscribers_REST_Controller`.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { CooldownError, isCooldown, type CachedEnvelope } from '../state/insightsCache';

export type StorageBackend = 'hpos' | 'legacy';

/**
 * A rate metric whose denominator may legitimately be zero. The UI
 * uses `computable` to decide between rendering the value and a
 * "no data yet" empty state, and surfaces `denominator` inline as
 * context when the value is real but the cohort is small.
 */
export interface SubscribersRateValue {
	value: number;
	computable: boolean;
	denominator: number;
}

export interface SubscribersClassification {
	backend: StorageBackend;
	donation_product_count: number;
	has_donation_family: boolean;
}

export interface TenureDistributionRow {
	product_name: string;
	tenure_days: number;
}

export interface UpcomingRenewals {
	count: number;
	total_value: number;
}

export interface UpcomingCancellations {
	count: number;
	total_value: number;
}

export interface SubscribersSnapshot {
	active_subscribers: number;
	mrr: number;
	arr: number;
	tenure_distribution: TenureDistributionRow[];
	upcoming_renewals_30d: UpcomingRenewals;
	upcoming_cancellations_30d: UpcomingCancellations;
}

export interface PerformanceVariationRow {
	variation_id: number;
	label: string;
	active_subs: number;
	churned_subs: number;
	active_value: number;
	lifetime_revenue: number;
}

export interface PerformanceRow {
	product_id: number;
	name: string;
	is_parent: boolean;
	active_subs: number;
	churned_subs: number;
	active_value: number;
	lifetime_revenue: number;
	/** Present only when `is_parent` is true. Sorted by active_subs descending. */
	variations?: PerformanceVariationRow[];
}

export interface CancellationReasonRow {
	cancellation_reason: string;
	count: number;
}

export interface SubscribersWindow {
	window: { start: string; end: string };
	new_subscribers: number;
	churned_subscribers: number;
	revenue_gross: number;
	revenue_net: number;
	/**
	 * Refunds ÷ subscription orders in the window. `computable: false`
	 * when there are no subscription orders in the window — UI renders
	 * a "No subscription orders in this timeframe" empty state.
	 */
	refund_rate: SubscribersRateValue;
	/**
	 * Recoveries ÷ retry attempts in the window. Same shape and UI
	 * contract as `refund_rate`.
	 */
	failed_payment_retry_rate: SubscribersRateValue;
	subscriptions_by_product: PerformanceRow[];
	cancellation_reasons: CancellationReasonRow[];
}

export interface SubscribersResponse {
	classification: SubscribersClassification;
	snapshot: SubscribersSnapshot;
	current: SubscribersWindow;
	previous: SubscribersWindow | null;
}

export interface SubscribersQuery {
	start: string;
	end: string;
	compare_start?: string;
	compare_end?: string;
}

const ENDPOINT = '/newspack-insights/v1/subscribers';

const queryString = ( query: SubscribersQuery ): string => {
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
 * Fetch Tab 6 data for the given window pair. Returns the full
 * classification + snapshot + current + previous payload.
 *
 * Throws on REST error (caught by the calling hook into an `error`
 * state).
 */
export const fetchSubscribersData = async ( query: SubscribersQuery ): Promise< CachedEnvelope< SubscribersResponse > > =>
	apiFetch< CachedEnvelope< SubscribersResponse > >( {
		path: `${ ENDPOINT }?${ queryString( query ) }`,
		method: 'GET',
	} );

export const refreshSubscribersData = async ( query: SubscribersQuery ): Promise< CachedEnvelope< SubscribersResponse > > => {
	try {
		return await apiFetch< CachedEnvelope< SubscribersResponse > >( {
			path: `${ ENDPOINT }/refresh?${ queryString( query ) }`,
			method: 'POST',
		} );
	} catch ( e: unknown ) {
		if ( isCooldown( e ) ) {
			const data = ( e as { data: { cooldown_until: string } } ).data;
			throw new CooldownError( data.cooldown_until );
		}
		throw e;
	}
};
