import { Notice, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { Stack, VisuallyHidden } from '@wordpress/ui';

import Button from '../../../../../packages/components/src/button';
import ImageUpload from '../../../../../packages/components/src/image-upload';
import TextControl from '../../../../../packages/components/src/text-control';
import useUnsavedChangesDialog from '../../../../../packages/components/src/hooks/use-unsaved-changes-dialog';
import NarrowLayout from '../../components/narrow-layout';
import StepHeading from '../../components/step-heading';
import StepFooter from '../../components/step-footer';
import { fetchProfile, saveProfile, type Profile, type ProfileResponse, type SelectOption } from '../../api';
import type { SetupViewProps } from '../../steps';
import { currencyFor, detectCountrySource } from './detect-location';

type Detected = { country: 'zone' | 'locale' | null; currency: boolean };

const toItems = ( options: SelectOption[] ) => options.filter( ( option ): option is { value: string; label: string } => !! option.value );

const toOptions = ( options: SelectOption[] ) =>
	options.map( option => ( { value: option.value ?? '', label: option.label, disabled: ! option.value } ) );

const countryCode = ( countrystate: string ) => countrystate.split( ':' )[ 0 ];

export default function SiteDetails( { installState, goTo }: SetupViewProps ) {
	const [ data, setData ] = useState< ProfileResponse | null >( null );
	const [ profile, setProfile ] = useState< Profile >( {} );
	const [ loadedProfile, setLoadedProfile ] = useState< Profile >( {} );
	const [ detected, setDetected ] = useState< Detected >( { country: null, currency: false } );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ loadError, setLoadError ] = useState( false );
	const [ attempt, setAttempt ] = useState( 0 );
	const importedCurrency = useRef( false );

	const isMove = installState.approach === 'import';

	useEffect( () => {
		let active = true;
		fetchProfile()
			.then( response => {
				if ( ! active ) {
					return;
				}
				const next = {
					...response.profile,
					site_title: decodeEntities( response.profile.site_title ?? '' ),
					tagline: decodeEntities( response.profile.tagline ?? '' ),
				};
				const found: Detected = { country: null, currency: false };
				importedCurrency.current = !! next.currency && next.currency === response.location_hints.imported_currency;
				const countries = toItems( response.countries ).map( item => item.value );
				const guess = detectCountrySource( Intl.DateTimeFormat().resolvedOptions().timeZone, response.location_hints.site_locale, countries );
				if ( ! next.countrystate && guess ) {
					next.countrystate = guess.country;
					found.country = guess.source;
				}
				if ( ! next.currency ) {
					const currency = currencyFor( countryCode( next.countrystate ?? '' ) || null, response.location_hints.country_currencies );
					if ( currency ) {
						next.currency = currency;
						found.currency = true;
					}
				}
				setProfile( next );
				setLoadedProfile( next );
				setDetected( found );
				setData( response );
			} )
			.catch( () => active && setLoadError( true ) );
		return () => {
			active = false;
		};
	}, [ attempt ] );

	const update = ( changes: Profile ) => setProfile( current => ( { ...current, ...changes } ) );

	const isDirty = !! data && JSON.stringify( profile ) !== JSON.stringify( loadedProfile );
	const { confirmDialog } = useUnsavedChangesDialog( { when: isDirty && ! saving } );

	const onContinue = async () => {
		setSaving( true );
		setError( '' );
		try {
			await saveProfile( profile );
			goTo( 'design' );
		} catch {
			setError( __( 'Couldn’t save your site details.', 'newspack-plugin' ) );
			setSaving( false );
		}
	};

	const heading = (
		<StepHeading
			title={ __( 'Site details', 'newspack-plugin' ) }
			lede={
				isMove
					? __( 'Brought over from your current site. Check them and change anything that’s off.', 'newspack-plugin' )
					: __( 'How your site introduces itself. You can change all of this later.', 'newspack-plugin' )
			}
		/>
	);

	if ( ! data ) {
		return (
			<NarrowLayout>
				{ heading }
				{ loadError ? (
					<Notice
						status="error"
						isDismissible={ false }
						politeness="polite"
						actions={ [
							{
								label: __( 'Try Again', 'newspack-plugin' ),
								onClick: () => {
									setLoadError( false );
									setAttempt( current => current + 1 );
								},
							},
						] }
					>
						{ __( 'Couldn’t load your site details.', 'newspack-plugin' ) }
					</Notice>
				) : (
					<div role="status">
						<Spinner />
						<VisuallyHidden>{ __( 'Loading site details', 'newspack-plugin' ) }</VisuallyHidden>
					</div>
				) }
			</NarrowLayout>
		);
	}

	const countryValue = toItems( data.countries ).find( item => item.value === countryCode( profile.countrystate ?? '' ) )?.value ?? '';
	const currencyValue = toItems( data.currencies ).find( item => item.value === profile.currency )?.value ?? '';

	let countryDescription: string | undefined;
	if ( detected.country === 'zone' ) {
		countryDescription = __( 'Based on your time zone.', 'newspack-plugin' );
	} else if ( detected.country === 'locale' ) {
		countryDescription = __( 'Based on your site language.', 'newspack-plugin' );
	}

	let currencyDescription: string | undefined;
	if ( detected.currency ) {
		currencyDescription = __( 'Based on the country.', 'newspack-plugin' );
	} else if ( isMove && importedCurrency.current && profile.currency === data.profile.currency ) {
		currencyDescription = __( 'Matches your current site’s store.', 'newspack-plugin' );
	}

	return (
		<NarrowLayout
			footer={
				<StepFooter>
					<Button
						variant="primary"
						loading={ saving }
						disabled={ saving }
						accessibleWhenDisabled
						__next40pxDefaultSize
						onClick={ onContinue }
					>
						{ __( 'Continue', 'newspack-plugin' ) }
					</Button>
				</StepFooter>
			}
		>
			{ heading }
			<Stack direction="column" gap="xl">
				<div className="setup-identity">
					<ImageUpload
						className="setup-identity__icon"
						label={ __( 'Site icon', 'newspack-plugin' ) }
						actionLabels={ {
							upload: __( 'Upload Site Icon', 'newspack-plugin' ),
							replace: __( 'Replace Site Icon', 'newspack-plugin' ),
							remove: __( 'Remove Site Icon', 'newspack-plugin' ),
						} }
						image={ profile.site_icon || null }
						onChange={ ( image: Profile[ 'site_icon' ] ) => update( { site_icon: image } ) }
						isCovering
						withMargin={ false }
					/>
					<Stack direction="column" gap="lg">
						<TextControl
							label={ __( 'Site title', 'newspack-plugin' ) }
							value={ profile.site_title ?? '' }
							onChange={ ( site_title: string ) => update( { site_title } ) }
							withMargin={ false }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
						<TextControl
							label={ __( 'Tagline', 'newspack-plugin' ) }
							placeholder={ __( 'A short line about your site', 'newspack-plugin' ) }
							value={ profile.tagline ?? '' }
							onChange={ ( tagline: string ) => update( { tagline } ) }
							withMargin={ false }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</Stack>
				</div>
				<Stack direction="column" gap="lg">
					<SelectControl
						label={ __( 'Country', 'newspack-plugin' ) }
						help={ countryDescription }
						options={ toOptions( data.countries ) }
						value={ countryValue }
						onChange={ ( countrystate: string ) => {
							if ( countrystate && countrystate !== countryValue ) {
								if ( detected.currency || ! profile.currency ) {
									const currency = currencyFor( countryCode( countrystate ) || null, data.location_hints.country_currencies );
									update( currency ? { countrystate, currency } : { countrystate } );
									setDetected( { country: null, currency: !! currency } );
								} else {
									update( { countrystate } );
									setDetected( current => ( { ...current, country: null } ) );
								}
							}
						} }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Currency', 'newspack-plugin' ) }
						help={ currencyDescription }
						options={ toOptions( data.currencies ) }
						value={ currencyValue }
						onChange={ ( currency: string ) => {
							if ( currency && currency !== currencyValue ) {
								update( { currency } );
								setDetected( current => ( { ...current, currency: false } ) );
								importedCurrency.current = false;
							}
						} }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</Stack>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
			</Stack>
			{ confirmDialog }
		</NarrowLayout>
	);
}
