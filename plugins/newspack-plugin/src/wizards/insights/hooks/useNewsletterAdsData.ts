/**
 * useNewsletterAdsData (NPPD-1861).
 *
 * Thin reader over the module insightsCache, mirroring
 * {@see useAdvertisingData}. The slot key embeds the date range + comparison
 * window so cross-tab/date state stays coherent.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useSyncExternalStore } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { fetchNewsletterAdsData, refreshNewsletterAdsData, type NewsletterAdsResponse } from '../api/newsletter_ads';
import { insightsCache, makeSlotKey } from '../state/insightsCache';
import { useRegisterRefresh } from '../state/refreshRegistry';

export type FetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseNewsletterAdsDataResult {
	status: FetchStatus;
	data: NewsletterAdsResponse | null;
	error: string | null;
	refetch: () => void;
	computedAt: string | null;
	source: 'bigquery' | 'external' | 'local' | null;
	cooldownUntil: string | null;
}

const queryFrom = ( range: DateRange, previousRange: DateRange | null ) => ( {
	start: range.start,
	end: range.end,
	compare_start: previousRange?.start,
	compare_end: previousRange?.end,
} );

const useNewsletterAdsData = ( range: DateRange, previousRange: DateRange | null ): UseNewsletterAdsDataResult => {
	const key = makeSlotKey( 'newsletter_ads', range, previousRange );

	const slot = useSyncExternalStore(
		listener => insightsCache.subscribe( key, listener ),
		() => insightsCache.getSlot< NewsletterAdsResponse >( key )
	);

	useEffect( () => {
		insightsCache.ensureFetched( key, () => fetchNewsletterAdsData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	const refetch = useCallback( () => {
		insightsCache.refresh( key, () => refreshNewsletterAdsData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	useRegisterRefresh( 'newsletter_ads', refetch );

	return {
		status: slot.status,
		data: slot.data,
		error: slot.error,
		refetch,
		computedAt: slot.computedAt,
		source: slot.source,
		cooldownUntil: slot.cooldownUntil,
	};
};

export default useNewsletterAdsData;
