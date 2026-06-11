import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

const LISTS_PATH = '/newspack-newsletters/v1/lists';

export default function useListsData() {
	const [ lists, setLists ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const sequencesRef = useRef( new Map() );
	const queuesRef = useRef( new Map() );
	const confirmedRef = useRef( new Map() );

	const load = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		try {
			const response = await apiFetch( { path: LISTS_PATH } );
			const next = Array.isArray( response ) ? response : [];
			setLists( next );
			const confirmed = new Map();
			next.forEach( row => {
				if ( row?.db_id !== undefined && row?.db_id !== null ) {
					confirmed.set( row.db_id, row );
				}
			} );
			confirmedRef.current = confirmed;
		} catch ( err ) {
			setError( err );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

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
