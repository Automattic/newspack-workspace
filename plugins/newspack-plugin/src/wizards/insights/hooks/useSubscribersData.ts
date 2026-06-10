/**
 * useSubscribersData (NPPD-1616).
 *
 * Thin reader over the module insightsCache. The slot key embeds the
 * date range + comparison window so cross-tab/date state stays coherent.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useSyncExternalStore } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { fetchSubscribersData, refreshSubscribersData, type SubscribersResponse } from '../api/subscribers';
import { insightsCache, makeSlotKey } from '../state/insightsCache';

export type FetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseSubscribersDataResult {
	status: FetchStatus;
	data: SubscribersResponse | null;
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

const useSubscribersData = ( range: DateRange, previousRange: DateRange | null ): UseSubscribersDataResult => {
	const key = makeSlotKey( 'subscribers', range, previousRange );

	const slot = useSyncExternalStore(
		listener => insightsCache.subscribe( key, listener ),
		() => insightsCache.getSlot< SubscribersResponse >( key )
	);

	useEffect( () => {
		insightsCache.ensureFetched( key, () => fetchSubscribersData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	const refetch = useCallback( () => {
		insightsCache.refresh( key, () => refreshSubscribersData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

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

export default useSubscribersData;
