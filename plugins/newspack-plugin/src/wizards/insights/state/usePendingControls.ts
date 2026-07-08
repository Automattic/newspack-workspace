/**
 * usePendingControls
 *
 * Draft/apply orchestration for the Insights global controls. The header
 * controls edit a draft (the existing useDateRange / useComparisonMode hooks);
 * a committed "applied" snapshot is what flows to the tabs, so no data refetch
 * happens until the user applies a change. Applying a custom range, or any
 * comparison-toggle change, routes through a confirmation modal; plain
 * preset→preset changes commit immediately. The URL is written only on commit.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useMemo, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import useDateRange, { type DateRange, type DateRangePreset } from './useDateRange';
import useComparisonMode from './useComparisonMode';
import { writeDateRangeUrl, writeComparisonUrl } from './controlsUrl';

const rangeEqual = ( a: DateRange, b: DateRange ): boolean => a.preset === b.preset && a.start === b.start && a.end === b.end;

export interface UsePendingControlsOptions {
	defaultRange: DateRange;
	defaultComparison: boolean;
}

export interface UsePendingControlsReturn {
	draftRange: DateRange;
	draftCompare: boolean;
	setPreset: ( preset: DateRangePreset ) => void;
	setCustom: ( start: string, end: string ) => void;
	setCompare: ( enabled: boolean ) => void;
	appliedRange: DateRange;
	appliedPreviousRange: DateRange | null;
	isDirty: boolean;
	confirmOpen: boolean;
	apply: () => void;
	cancel: () => void;
	confirmApply: () => void;
}

interface AppliedState {
	range: DateRange;
	previousRange: DateRange | null;
	compare: boolean;
}

const usePendingControls = ( { defaultRange, defaultComparison }: UsePendingControlsOptions ): UsePendingControlsReturn => {
	const { range: draftRange, setPreset, setCustom, setRange } = useDateRange( { defaultRange } );
	const {
		enabled: draftCompare,
		setEnabled: setCompare,
		previousRange: draftPreviousRange,
	} = useComparisonMode( { defaultEnabled: defaultComparison, currentRange: draftRange } );

	// Initial applied snapshot = initial draft (already hydrated from URL / boot).
	const [ applied, setApplied ] = useState< AppliedState >( () => ( {
		range: draftRange,
		previousRange: draftPreviousRange,
		compare: draftCompare,
	} ) );

	const [ confirmOpen, setConfirmOpen ] = useState( false );

	const isDirty = useMemo(
		() => ! rangeEqual( draftRange, applied.range ) || draftCompare !== applied.compare,
		[ draftRange, draftCompare, applied ]
	);

	const commit = useCallback( () => {
		setApplied( { range: draftRange, previousRange: draftPreviousRange, compare: draftCompare } );
		writeDateRangeUrl( draftRange );
		writeComparisonUrl( draftCompare );
		setConfirmOpen( false );
	}, [ draftRange, draftPreviousRange, draftCompare ] );

	const apply = useCallback( () => {
		if ( ! isDirty ) {
			return;
		}
		// Custom ranges and any comparison change are the slow paths — confirm first.
		const needsConfirm = draftRange.preset === 'custom' || draftCompare !== applied.compare;
		if ( needsConfirm ) {
			setConfirmOpen( true );
			return;
		}
		commit();
	}, [ isDirty, draftRange.preset, draftCompare, applied.compare, commit ] );

	const cancel = useCallback( () => {
		setRange( applied.range );
		setCompare( applied.compare );
		setConfirmOpen( false );
	}, [ applied.range, applied.compare, setRange, setCompare ] );

	const confirmApply = useCallback( () => {
		commit();
	}, [ commit ] );

	return {
		draftRange,
		draftCompare,
		setPreset,
		setCustom,
		setCompare,
		appliedRange: applied.range,
		appliedPreviousRange: applied.previousRange,
		isDirty,
		confirmOpen,
		apply,
		cancel,
		confirmApply,
	};
};

export default usePendingControls;
