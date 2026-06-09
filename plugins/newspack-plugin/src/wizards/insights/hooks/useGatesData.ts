/**
 * useGatesData (NPPD-1604).
 *
 * Tab 4's data fetch lifecycle. Mirrors {@see useDonorsData} and
 * {@see useSubscribersData}: a request-id guard serializes
 * overlapping calls so the latest range change wins, and
 * idle / loading / success / error state is local to the tab.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import type { DateRange } from '../state/useDateRange';
import { fetchGatesData, type GatesResponse } from '../api/gates';

export type GatesFetchStatus = 'idle' | 'loading' | 'success' | 'error';

export interface UseGatesDataResult {
	status: GatesFetchStatus;
	data: GatesResponse | null;
	error: string | null;
	refetch: () => void;
}

const errorMessage = ( e: unknown ): string => {
	if ( e && typeof e === 'object' && 'message' in e && typeof ( e as { message: unknown } ).message === 'string' ) {
		return ( e as { message: string } ).message;
	}
	return String( e );
};

const useGatesData = ( range: DateRange, previousRange: DateRange | null ): UseGatesDataResult => {
	const [ status, setStatus ] = useState< GatesFetchStatus >( 'idle' );
	const [ data, setData ] = useState< GatesResponse | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	const requestIdRef = useRef( 0 );
	const [ refetchTick, setRefetchTick ] = useState( 0 );
	const refetch = useCallback( () => setRefetchTick( t => t + 1 ), [] );

	useEffect( () => {
		const myId = ++requestIdRef.current;
		setStatus( 'loading' );
		setError( null );

		fetchGatesData( {
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

export default useGatesData;
