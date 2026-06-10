/**
 * useAudienceData (NPPD-1649).
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
import { fetchAudienceData, refreshAudienceData, type AudienceResponse } from '../api/audience';
import { insightsCache, makeSlotKey } from '../state/insightsCache';
import { useRegisterRefresh } from '../state/refreshRegistry';

export type FetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseAudienceDataResult {
	status: FetchStatus;
	data: AudienceResponse | null;
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

const useAudienceData = ( range: DateRange, previousRange: DateRange | null ): UseAudienceDataResult => {
	const key = makeSlotKey( 'audience', range, previousRange );

	const slot = useSyncExternalStore(
		listener => insightsCache.subscribe( key, listener ),
		() => insightsCache.getSlot< AudienceResponse >( key )
	);

	useEffect( () => {
		insightsCache.ensureFetched( key, () => fetchAudienceData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	const refetch = useCallback( () => {
		insightsCache.refresh( key, () => refreshAudienceData( queryFrom( range, previousRange ) ) );
	}, [ key, range.start, range.end, previousRange?.start, previousRange?.end ] );

	useRegisterRefresh( 'audience', refetch );

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

export default useAudienceData;
