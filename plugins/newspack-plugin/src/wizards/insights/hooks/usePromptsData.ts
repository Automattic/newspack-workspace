/**
 * usePromptsData (NPPD-1607).
 *
 * Tab 5's data fetch lifecycle. Mirrors {@see useGatesData}: a
 * request-id guard serializes overlapping calls so the latest range
 * change wins, and idle / loading / success / error state is local to
 * the tab.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { fetchPromptsData, type PromptsResponse } from '../api/prompts';

export type PromptsFetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UsePromptsDataResult {
	status: PromptsFetchStatus;
	data: PromptsResponse | null;
	error: string | null;
	refetch: () => void;
}

const errorMessage = ( e: unknown ): string => {
	if ( e && typeof e === 'object' && 'message' in e && typeof ( e as { message: unknown } ).message === 'string' ) {
		return ( e as { message: string } ).message;
	}
	return String( e );
};

const usePromptsData = ( range: DateRange, previousRange: DateRange | null ): UsePromptsDataResult => {
	const [ status, setStatus ] = useState< PromptsFetchStatus >( 'idle' );
	const [ data, setData ] = useState< PromptsResponse | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	const requestIdRef = useRef( 0 );
	const [ refetchTick, setRefetchTick ] = useState( 0 );
	const refetch = useCallback( () => setRefetchTick( t => t + 1 ), [] );

	useEffect( () => {
		const myId = ++requestIdRef.current;
		setStatus( 'loading' );
		setError( null );

		fetchPromptsData( {
			start: range.start,
			end: range.end,
			compare_start: previousRange?.start,
			compare_end: previousRange?.end,
		} )
			.then( response => {
				if ( requestIdRef.current !== myId ) {
					return;
				}
				setData( response );
				setStatus( 'success' );
			} )
			.catch( e => {
				if ( requestIdRef.current !== myId ) {
					return;
				}
				setError( errorMessage( e ) );
				setStatus( 'error' );
			} );
	}, [ range.start, range.end, previousRange?.start, previousRange?.end, refetchTick ] );

	return { status, data, error, refetch };
};

export default usePromptsData;
