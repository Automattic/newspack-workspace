/**
 * useGatesData (NPPD-1604).
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
import { fetchGatesData, refreshGatesData, type GatesResponse } from '../api/gates';
import { insightsCache, makeSlotKey } from '../state/insightsCache';

export type FetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseGatesDataResult {
	status: FetchStatus;
	data: GatesResponse | null;
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

const useGatesData = ( range: DateRange, previousRange: DateRange | null ): UseGatesDataResult => {
	const key = makeSlotKey( 'gates', range, previousRange );

	const slot = useSyncExternalStore(
		listener => insightsCache.subscribe( key, listener ),
		() => insightsCache.getSlot< GatesResponse >( key )
	);

	useEffect( () => {
		insightsCache.ensureFetched( key, () => fetchGatesData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	const refetch = useCallback( () => {
		insightsCache.refresh( key, () => refreshGatesData( queryFrom( range, previousRange ) ) );
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

export default useGatesData;
