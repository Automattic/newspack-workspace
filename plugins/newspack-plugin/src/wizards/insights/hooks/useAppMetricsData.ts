/**
 * useAppMetricsData (NPPD-1882).
 *
 * Reads the App tab's windowed metrics through the shared module insightsCache,
 * mirroring {@see useNewsletterAdsData}. Adopting the cache is what gives the App
 * tab the "Last updated" + kebab chrome (LastUpdated reads the same slot) and
 * period-over-period comparison (the slot key embeds the comparison window, and
 * the fetch forwards it so the server returns a `previous` window too).
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useSyncExternalStore } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { fetchAppMetricsData, refreshAppMetricsData, type AppMetricsQuery, type AppReportData } from '../api/app';
import { insightsCache, makeSlotKey } from '../state/insightsCache';
import { useRegisterRefresh } from '../state/refreshRegistry';

export type FetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseAppMetricsDataResult {
	status: FetchStatus;
	data: AppReportData | null;
	error: string | null;
	refetch: () => void;
	computedAt: string | null;
}

const queryFrom = ( range: DateRange, previousRange: DateRange | null ): AppMetricsQuery => ( {
	start: range.start,
	end: range.end,
	compare_start: previousRange?.start,
	compare_end: previousRange?.end,
} );

const useAppMetricsData = ( range: DateRange, previousRange: DateRange | null ): UseAppMetricsDataResult => {
	const key = makeSlotKey( 'app', range, previousRange );

	const slot = useSyncExternalStore(
		listener => insightsCache.subscribe( key, listener ),
		() => insightsCache.getSlot< AppReportData >( key )
	);

	useEffect( () => {
		insightsCache.ensureFetched( key, () => fetchAppMetricsData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	const refetch = useCallback( () => {
		insightsCache.refresh( key, () => refreshAppMetricsData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	useRegisterRefresh( 'app', refetch );

	return {
		status: slot.status,
		data: slot.data,
		error: slot.error,
		refetch,
		computedAt: slot.computedAt,
	};
};

export default useAppMetricsData;
