import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

const SETTINGS_PATH = '/newspack-newsletters/v1/admin-shell/settings';

export default function useSettingsData() {
	const [ data, setData ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		try {
			const response = await apiFetch( { path: SETTINGS_PATH } );
			setData( response );
		} catch ( err ) {
			setError( err );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const save = useCallback( async payload => {
		const response = await apiFetch( {
			path: SETTINGS_PATH,
			method: 'POST',
			data: payload,
		} );
		setData( response );
		return response;
	}, [] );

	return { data, isLoading, error, reload: load, save };
}
