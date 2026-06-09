import {
	Notice,
	Spinner,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { notifyError, notifySuccess } from '../../notices';
import ListsSection from './lists-section';
import OptionsSection from './options-section';
import ProviderSection from './provider-section';
import useListsData from './use-lists-data';
import useSettingsData from './use-settings-data';

const LETTERHEAD_KEY = 'newspack_newsletters_letterhead_api_key';

export default function SettingsScreen() {
	const { data, isLoading, error, save: saveSettings, reload: reloadSettings } = useSettingsData();
	const { lists, isLoading: isListsLoading, error: listsError, patchList, reload: reloadLists } = useListsData();

	const savedSlug = data?.provider?.selected || '';

	const [ pendingSlug, setPendingSlug ] = useState( savedSlug );
	const [ pendingCredentials, setPendingCredentials ] = useState( {} );
	const [ pendingOptions, setPendingOptions ] = useState( {} );
	const [ isSaving, setIsSaving ] = useState( false );

	useEffect( () => {
		setPendingSlug( savedSlug );
	}, [ savedSlug ] );

	const handleAuthorized = useCallback( () => {
		notifySuccess( __( 'Provider connected.', 'newspack-newsletters' ) );
		reloadSettings();
		reloadLists();
	}, [ reloadSettings, reloadLists ] );

	const updateOption = useCallback( ( key, value ) => {
		setPendingOptions( current => ( { ...current, [ key ]: value } ) );
	}, [] );

	const updateCredential = useCallback( ( key, value ) => {
		setPendingCredentials( current => ( { ...current, [ key ]: value } ) );
	}, [] );

	const onSlugChange = useCallback( next => {
		setPendingSlug( next );
		setPendingCredentials( {} );
	}, [] );

	const newsletterOptionsSchema = useMemo( () => ( data?.schema || [] ).filter( field => field.key !== LETTERHEAD_KEY ), [ data?.schema ] );
	const letterheadSchema = useMemo( () => ( data?.schema || [] ).filter( field => field.key === LETTERHEAD_KEY ), [ data?.schema ] );
	// Restrict to fields visible under the saved provider so stale other-provider keys don't get POSTed.
	const newsletterOptionKeys = useMemo(
		() => newsletterOptionsSchema.filter( f => ! f.provider || f.provider === savedSlug ).map( f => f.key ),
		[ newsletterOptionsSchema, savedSlug ]
	);
	const letterheadOptionKeys = useMemo(
		() => letterheadSchema.filter( f => ! f.provider || f.provider === savedSlug ).map( f => f.key ),
		[ letterheadSchema, savedSlug ]
	);

	const slugDirty = pendingSlug !== savedSlug;
	const credentialsDirty = Object.keys( pendingCredentials ).length > 0;
	const providerDirty = slugDirty || credentialsDirty;
	const pendingOptionKeys = Object.keys( pendingOptions );
	const newsletterOptionsDirty = pendingOptionKeys.some( k => newsletterOptionKeys.includes( k ) );
	const letterheadOptionsDirty = pendingOptionKeys.some( k => letterheadOptionKeys.includes( k ) );

	const clearOptionKeys = useCallback( keys => {
		setPendingOptions( prev => {
			const next = { ...prev };
			keys.forEach( k => delete next[ k ] );
			return next;
		} );
	}, [] );

	const handleSaveProvider = useCallback( async () => {
		if ( ! providerDirty ) {
			return;
		}
		const payload = { provider: { slug: pendingSlug } };
		if ( pendingSlug !== 'manual' && credentialsDirty ) {
			const submitted = {};
			Object.keys( pendingCredentials ).forEach( key => {
				const value = pendingCredentials[ key ];
				if ( typeof value === 'string' && value.length > 0 ) {
					submitted[ key ] = value;
				}
			} );
			payload.provider.credentials = submitted;
		}
		setIsSaving( true );
		try {
			await saveSettings( payload );
			notifySuccess( __( 'Provider settings saved.', 'newspack-newsletters' ) );
			setPendingCredentials( {} );
			reloadLists();
		} catch ( err ) {
			notifyError( err?.message || __( 'Could not save settings. Check the credentials and try again.', 'newspack-newsletters' ) );
		} finally {
			setIsSaving( false );
		}
	}, [ providerDirty, credentialsDirty, pendingSlug, pendingCredentials, saveSettings, reloadLists ] );

	const saveOptionsSubset = useCallback(
		async ( keys, successMessage ) => {
			const subset = {};
			keys.forEach( key => {
				if ( Object.prototype.hasOwnProperty.call( pendingOptions, key ) ) {
					subset[ key ] = pendingOptions[ key ];
				}
			} );
			if ( Object.keys( subset ).length === 0 ) {
				return;
			}
			setIsSaving( true );
			try {
				await saveSettings( { options: subset } );
				notifySuccess( successMessage );
				clearOptionKeys( keys );
			} catch ( err ) {
				notifyError( err?.message || __( 'Could not save settings.', 'newspack-newsletters' ) );
			} finally {
				setIsSaving( false );
			}
		},
		[ pendingOptions, saveSettings, clearOptionKeys ]
	);

	const handleSaveNewsletterOptions = useCallback(
		() => saveOptionsSubset( newsletterOptionKeys, __( 'Newsletter options saved.', 'newspack-newsletters' ) ),
		[ saveOptionsSubset, newsletterOptionKeys ]
	);
	const handleSaveLetterhead = useCallback(
		() => saveOptionsSubset( letterheadOptionKeys, __( 'Letterhead saved.', 'newspack-newsletters' ) ),
		[ saveOptionsSubset, letterheadOptionKeys ]
	);

	if ( isLoading && ! data ) {
		return (
			<HStack className="newspack-newsletters-admin__loading" justify="center">
				<Spinner />
			</HStack>
		);
	}

	if ( error && ! data ) {
		return (
			<VStack spacing={ 12 } className="newspack-newsletters-settings">
				<Notice status="error" isDismissible={ false }>
					{ error?.message || __( 'Could not load settings. Refresh the page to try again.', 'newspack-newsletters' ) }
				</Notice>
			</VStack>
		);
	}

	return (
		<VStack spacing={ 12 } className="newspack-newsletters-settings">
			<ProviderSection
				provider={ data?.provider }
				providers={ data?.providers }
				pendingSlug={ pendingSlug }
				pendingCredentials={ pendingCredentials }
				onSlugChange={ onSlugChange }
				onCredentialChange={ updateCredential }
				onAuthorized={ handleAuthorized }
				onSave={ handleSaveProvider }
				isDirty={ providerDirty }
				isSaving={ isSaving }
				disabled={ isSaving }
			/>
			<OptionsSection
				title={ __( 'Newsletter options', 'newspack-newsletters' ) }
				options={ data?.options }
				schema={ newsletterOptionsSchema }
				activeProvider={ savedSlug }
				pendingValues={ pendingOptions }
				onChange={ updateOption }
				onSave={ handleSaveNewsletterOptions }
				isDirty={ newsletterOptionsDirty }
				isSaving={ isSaving }
				disabled={ isSaving }
			/>
			<OptionsSection
				title={ __( 'Letterhead', 'newspack-newsletters' ) }
				options={ data?.options }
				schema={ letterheadSchema }
				activeProvider={ savedSlug }
				pendingValues={ pendingOptions }
				onChange={ updateOption }
				onSave={ handleSaveLetterhead }
				isDirty={ letterheadOptionsDirty }
				isSaving={ isSaving }
				disabled={ isSaving }
			/>
			<ListsSection
				lists={ lists }
				isLoading={ isListsLoading }
				error={ listsError }
				canAddLocal={ !! data?.lists_can_add_local }
				onPatchList={ patchList }
				onLocalListChanged={ reloadLists }
			/>
		</VStack>
	);
}
