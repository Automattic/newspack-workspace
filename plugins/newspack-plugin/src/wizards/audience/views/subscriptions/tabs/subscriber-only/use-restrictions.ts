/**
 * Data access for subscriber-only product restrictions.
 *
 * Every mutation returns the full server state, so the hook never has to
 * reconcile a local guess against what was saved.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { WIZARD_ENDPOINT } from '../../constants';
import type { Restriction, RestrictionSettings } from './types';

const RULES_PATH = `${ WIZARD_ENDPOINT }/restrictions`;
const SETTINGS_PATH = `${ WIZARD_ENDPOINT }/restriction-settings`;

interface RestrictionsResponse {
	restrictions: Restriction[];
	settings: RestrictionSettings;
}

export function useRestrictions() {
	const [ restrictions, setRestrictions ] = useState< Restriction[] >( [] );
	const [ settings, setSettings ] = useState< RestrictionSettings >( { hide_from_product_lists: false } );
	const [ loading, setLoading ] = useState( true );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );

	const apply = useCallback( ( response: RestrictionsResponse ) => {
		setRestrictions( response.restrictions || [] );
		setSettings( response.settings || { hide_from_product_lists: false } );
	}, [] );

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		return apiFetch< RestrictionsResponse >( { path: RULES_PATH } )
			.then( apply )
			.catch( ( err: { message?: string } ) => setError( err?.message || __( 'Could not load restrictions.', 'newspack-plugin' ) ) )
			.finally( () => setLoading( false ) );
	}, [ apply ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Every mutation shares one shape: fire, replace state with the server's
	// answer, and surface any failure rather than leaving the UI out of step.
	const mutate = useCallback(
		( path: string, method: string, data?: Record< string, unknown > ) => {
			setSaving( true );
			setError( '' );
			return apiFetch< RestrictionsResponse >( { path, method, data } )
				.then( response => {
					apply( response );
					return true;
				} )
				.catch( ( err: { message?: string } ) => {
					setError( err?.message || __( 'Could not save. Please try again.', 'newspack-plugin' ) );
					return false;
				} )
				.finally( () => setSaving( false ) );
		},
		[ apply ]
	);

	const saveRestriction = useCallback(
		( rule: Partial< Restriction > ) => mutate( rule.id ? `${ RULES_PATH }/${ rule.id }` : RULES_PATH, 'POST', { ...rule } ),
		[ mutate ]
	);

	const deleteRestriction = useCallback( ( id: string ) => mutate( `${ RULES_PATH }/${ id }`, 'DELETE' ), [ mutate ] );

	const setActive = useCallback( ( rule: Restriction, active: boolean ) => saveRestriction( { ...rule, active } ), [ saveRestriction ] );

	const saveSettings = useCallback( ( next: RestrictionSettings ) => mutate( SETTINGS_PATH, 'POST', { ...next } ), [ mutate ] );

	return { restrictions, settings, loading, saving, error, saveRestriction, deleteRestriction, setActive, saveSettings, reload: load };
}
