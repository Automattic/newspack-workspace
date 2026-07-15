import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const LISTS_PATH = '/newspack-newsletters/v1/lists';

// When the provider's sublists are still warming (fetched asynchronously on a
// cold cache), GET /lists returns the audiences only and sets this header. We
// re-poll a bounded number of times so the sublists appear on their own,
// without the user having to reload the page. Keep the budget in step with the
// newspack-plugin SubscriptionLists UI, which polls the same header.
const WARMING_HEADER = 'x-newspack-newsletters-lists-warming';
const WARMING_POLL_INTERVAL_MS = 3000;
const WARMING_MAX_POLLS = 10;

export default function useListsData() {
	const [ lists, setLists ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const sequencesRef = useRef( new Map() );
	const queuesRef = useRef( new Map() );
	const confirmedRef = useRef( new Map() );
	const pollTimerRef = useRef( null );
	const pollCountRef = useRef( 0 );
	const mountedRef = useRef( true );

	const clearPoll = useCallback( () => {
		if ( pollTimerRef.current ) {
			clearTimeout( pollTimerRef.current );
			pollTimerRef.current = null;
		}
	}, [] );

	const load = useCallback(
		async ( isPoll = false ) => {
			if ( ! isPoll ) {
				// A fresh load (mount or post-save reload) resets the poll budget
				// and shows the loading state; polls refresh in place so the
				// already-rendered audiences don't flash a spinner.
				clearPoll();
				pollCountRef.current = 0;
				setIsLoading( true );
			}
			setError( null );
			try {
				const response = await apiFetch( { path: LISTS_PATH, parse: false } );
				if ( ! response.ok ) {
					let body = null;
					try {
						body = await response.json();
					} catch ( e ) {
						body = null;
					}
					throw body || new Error( __( 'Could not load subscription lists.', 'newspack-newsletters' ) );
				}
				const warming = response?.headers?.get?.( WARMING_HEADER ) === '1';
				const payload = await response.json();
				const next = Array.isArray( payload ) ? payload : [];
				if ( ! mountedRef.current ) {
					return;
				}
				setLists( next );
				const confirmed = new Map();
				next.forEach( row => {
					if ( row?.db_id !== undefined && row?.db_id !== null ) {
						confirmed.set( row.db_id, row );
					}
				} );
				confirmedRef.current = confirmed;

				if ( warming && pollCountRef.current < WARMING_MAX_POLLS ) {
					pollCountRef.current += 1;
					clearPoll();
					pollTimerRef.current = setTimeout( () => load( true ), WARMING_POLL_INTERVAL_MS );
				} else {
					clearPoll();
				}
			} catch ( err ) {
				if ( mountedRef.current ) {
					setError( err );
				}
			} finally {
				if ( mountedRef.current && ! isPoll ) {
					setIsLoading( false );
				}
			}
		},
		[ clearPoll ]
	);

	useEffect( () => {
		mountedRef.current = true;
		load();
		return () => {
			mountedRef.current = false;
			clearPoll();
		};
	}, [ load, clearPoll ] );

	const patchList = useCallback( ( dbId, patch ) => {
		const seq = ( sequencesRef.current.get( dbId ) || 0 ) + 1;
		sequencesRef.current.set( dbId, seq );
		setLists( current => current.map( row => ( row.db_id === dbId ? { ...row, ...patch } : row ) ) );
		const previous = queuesRef.current.get( dbId ) || Promise.resolve();
		const next = previous
			.catch( () => {} )
			.then( async () => {
				try {
					const response = await apiFetch( {
						path: `${ LISTS_PATH }/${ dbId }`,
						method: 'PATCH',
						data: patch,
					} );
					const previousConfirmed = confirmedRef.current.get( dbId );
					confirmedRef.current.set( dbId, previousConfirmed ? { ...previousConfirmed, ...response } : response );
					if ( sequencesRef.current.get( dbId ) === seq ) {
						setLists( current => current.map( row => ( row.db_id === dbId ? { ...row, ...response } : row ) ) );
					}
					return response;
				} catch ( err ) {
					if ( sequencesRef.current.get( dbId ) === seq ) {
						const confirmed = confirmedRef.current.get( dbId );
						if ( confirmed ) {
							setLists( current => current.map( row => ( row.db_id === dbId ? confirmed : row ) ) );
						}
					}
					throw err;
				}
			} );
		queuesRef.current.set( dbId, next );
		return next;
	}, [] );

	return { lists, isLoading, error, reload: load, patchList };
}
