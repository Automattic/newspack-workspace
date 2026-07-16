import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

import type { SettingsData } from './types';

const SETTINGS_PATH = '/newspack-newsletters/v1/admin-shell/settings';

interface UseSettingsData {
	data: SettingsData | null;
	isLoading: boolean;
	error: unknown;
	reload: () => Promise< void >;
	save: ( payload: Record< string, unknown > ) => Promise< SettingsData >;
}

export default function useSettingsData(): UseSettingsData {
	const [ data, setData ] = useState< SettingsData | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState< unknown >( null );

	const load = useCallback( async () => {
		setIsLoading( true );
		setError( null );
		try {
			const response = await apiFetch< SettingsData >( { path: SETTINGS_PATH } );
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

	const save = useCallback( async ( payload: Record< string, unknown > ): Promise< SettingsData > => {
		const response = await apiFetch< SettingsData >( {
			path: SETTINGS_PATH,
			method: 'POST',
			data: payload,
		} );
		setData( response );
		return response;
	}, [] );

	return { data, isLoading, error, reload: load, save };
}
