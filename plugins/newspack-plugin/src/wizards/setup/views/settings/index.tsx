/**
 * WordPress dependencies
 */
import { Fragment, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { Grid, ImageUpload, SectionHeader, SelectControl, TextControl, withWizardScreen, hooks } from '../../../../../packages/components/src';
import type { SelectedImageAttachment } from '../../../../../packages/components/src/image-upload';
import type { SelectControlOption } from '../../../../../packages/components/src/select-control';
import type { SetupScreenComponentProps, SetupScreenProps } from '../../types';

const pageTitleTemplate = document.title.replace( newspack_aux_data.site_title, '__SITE_TITLE__' );

/**
 * A profile field value: text fields hold strings, the site icon holds an
 * image attachment.
 */
type ProfileValue = string | SelectedImageAttachment | null | undefined;

/**
 * The site profile, keyed by field.
 */
type ProfileData = Record< string, ProfileValue >;

/**
 * Descriptor of a single profile setting control.
 */
type ProfileSetting = {
	key: string;
	label: string;
	type?: string;
	placeholder?: string;
	className?: string;
	options?: SelectControlOption[];
};

/**
 * The profile endpoint's response shape.
 */
type ProfileApiResponse = {
	profile: ProfileData;
	currencies: SelectControlOption[];
	countries: SelectControlOption[];
	wpseo_fields: ProfileSetting[];
};

type ProfileOptions = {
	currencies?: SelectControlOption[];
	countries?: SelectControlOption[];
	wpseoFields?: ProfileSetting[];
};

/**
 * Settings Setup Screen.
 */
const Settings = ( { setError, wizardApiFetch, renderPrimaryButton }: SetupScreenComponentProps ) => {
	const [ { currencies = [], countries = [], wpseoFields = [] }, setOptions ] = useState< ProfileOptions >( {} );
	const [ profileData, updateProfileData ] = hooks.useObjectState< ProfileData >( {} );

	useEffect( () => {
		wizardApiFetch( { path: '/newspack/v1/profile/', method: 'GET' } )
			.then( response => {
				// The profile endpoint's response (opaque API boundary).
				const data = response as ProfileApiResponse;
				setOptions( {
					currencies: data.currencies,
					countries: data.countries,
					wpseoFields: data.wpseo_fields,
				} );
				updateProfileData( data.profile );
			} )
			.catch( setError );
	}, [] );

	const updateProfile = () =>
		apiFetch( {
			path: '/newspack/v1/profile/',
			method: 'POST',
			data: { profile: profileData },
		} );

	useEffect( () => {
		if ( typeof profileData.site_title === 'string' ) {
			document.title = pageTitleTemplate.replace( '__SITE_TITLE__', profileData.site_title );
		}
	}, [ profileData.site_title ] );

	const renderSetting = ( { options, label, key, type, placeholder, className }: ProfileSetting ) => {
		if ( options ) {
			return (
				<SelectControl
					label={ label }
					// Select-type profile fields hold string values (from the profile API).
					value={ profileData[ key ] as string }
					onChange={ updateProfileData( key ) }
					options={ options }
					className={ className }
				/>
			);
		}
		if ( type === 'image' ) {
			return (
				<ImageUpload
					label={ label }
					style={ { width: '102px', height: '102px' } }
					image={ profileData[ key ] }
					isCovering
					onChange={ updateProfileData( key ) }
				/>
			);
		}
		return (
			<TextControl
				label={ label }
				value={ profileData[ key ] || '' }
				onChange={ updateProfileData( key ) }
				placeholder={ placeholder }
				className={ className }
			/>
		);
	};

	return (
		<Fragment>
			<SectionHeader title={ __( 'Site Profile', 'newspack' ) } description={ __( 'Add and manage the basic information', 'newspack' ) } />
			<Grid columns={ 3 } gutter={ 32 } rowGap={ 16 } className="newspack-site-profile">
				{ renderSetting( {
					key: 'site_icon',
					label: __( 'Site Icon', 'newspack' ),
					type: 'image',
				} ) }
				<Grid columns={ 1 } gutter={ 16 }>
					{ renderSetting( { key: 'site_title', label: __( 'Site Title', 'newspack' ) } ) }
					{ renderSetting( { key: 'tagline', label: __( 'Tagline', 'newspack' ) } ) }
				</Grid>
				<Grid columns={ 1 } gutter={ 16 }>
					{ renderSetting( {
						options: countries,
						key: 'countrystate',
						label: __( 'Country', 'newspack' ),
					} ) }
					{ renderSetting( { options: currencies, key: 'currency', label: __( 'Currency' ) } ) }
				</Grid>
			</Grid>
			<SectionHeader
				title={ __( 'Social Accounts', 'newspack' ) }
				description={ __( 'Allow visitors to quickly access your social profiles', 'newspack' ) }
			/>
			<Grid columns={ 3 } gutter={ 32 } rowGap={ 16 }>
				{ wpseoFields.map( seoField => (
					<Fragment key={ seoField.key }>
						{ renderSetting( {
							...seoField,
						} ) }
					</Fragment>
				) ) }
			</Grid>
			<div className="newspack-buttons-card">{ renderPrimaryButton( { onClick: updateProfile } ) }</div>
		</Fragment>
	);
};

export default withWizardScreen< SetupScreenProps >( Settings, { hidePrimaryButton: true } );
