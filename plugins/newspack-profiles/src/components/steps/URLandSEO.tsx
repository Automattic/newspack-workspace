import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';

import { store as onboardingStore } from '../../stores/onboarding';
/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	__experimentalVStack as VStack,
	__experimentalDivider as Divider,
	FormTokenField,
	Notice,
	TextControl,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { useEditContext } from '../../context/EditContext';
import { SEOFieldInput } from '../SEOFieldInput';
import {
	sanitizeName,
	sanitizeNameLenient,
	sanitizeSlug,
	sanitizeSlugLenient,
} from '../../utils';
import './URLandSEO.scss';

/**
 * Component for configuring profile URL structure and SEO settings.
 *
 * @return JSX.Element The URLAndSEO component.
 */
export const URLAndSEO = () => {
	const isEdit = useEditContext();

	const {
		selectedProfileName,
		selectedProfileSlug,
		selectedSlugFields,
		selectedTitleFields,
		dataSourceFields,
		dataSourceType,
		seoFields,
	} = useSelect( ( select ) => {
		const {
			getProfileName,
			getProfileSlug,
			getSlugFields,
			getTitleFields,
			getDataSource,
			getSEOFields,
		} = select( onboardingStore );

		return {
			selectedProfileName: getProfileName(),
			selectedProfileSlug: getProfileSlug(),
			selectedSlugFields: getSlugFields(),
			selectedTitleFields: getTitleFields(),
			dataSourceFields: getDataSource().fields,
			dataSourceType: getDataSource().type,
			seoFields: getSEOFields(),
		};
	}, [] );

	const {
		setProfileName,
		setProfileSlug,
		setSlugFields,
		setTitleFields,
		setSeoFields,
	} = useDispatch( onboardingStore );

	return (
		<div className="newspack-profiles__url-seo">
			<h3>{ __( 'Profile URL Structure', 'newspack-profiles' ) }</h3>
			<p>
				{ __(
					'Define the name and URL structure for your profile. You can also choose which fields to use for generating individual profile URLs.',
					'newspack-profiles'
				) }
			</p>
			{ isEdit && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'The Profile Base Slug cannot be changed for existing profiles to prevent breaking existing URLs and SEO impact.',
						'newspack-profiles'
					) }
				</Notice>
			) }
			<VStack
				spacing={ 4 }
				className="newspack-profiles__seo-group newspack-profiles__seo-group--top"
			>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Profile Name (required)',
						'newspack-profiles'
					) }
					placeholder={ __(
						'Enter profile name',
						'newspack-profiles'
					) }
					value={ selectedProfileName }
					onChange={ ( value ) => {
						setProfileName( sanitizeNameLenient( value ) );
					} }
					onBlur={ () => {
						setProfileName( sanitizeName( selectedProfileName ) );
					} }
					maxLength={ 100 }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Profile Base Slug (required)',
						'newspack-profiles'
					) }
					help={ __(
						'Used in URLs for profile pages (list and single). Lowercase letters, numbers, and hyphens only.',
						'newspack-profiles'
					) }
					placeholder={ __(
						'Enter profile slug',
						'newspack-profiles'
					) }
					value={ selectedProfileSlug }
					onChange={ ( value ) => {
						setProfileSlug( sanitizeSlugLenient( value ) );
					} }
					onBlur={ () => {
						setProfileSlug( sanitizeSlug( selectedProfileSlug ) );
					} }
					maxLength={ 100 }
					disabled={ isEdit }
				/>
				<div>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						__experimentalShowHowTo={ false }
						__experimentalExpandOnFocus={ true }
						label={ __(
							'Profile Slug Generation Fields (required)',
							'newspack-profiles'
						) }
						placeholder={ __(
							'Add fields for generating profile slugs',
							'newspack-profiles'
						) }
						value={ selectedSlugFields }
						suggestions={ dataSourceFields }
						onChange={ ( tokens ) => {
							const validFields = tokens.filter( ( token ) =>
								dataSourceFields.includes( token as string )
							) as string[];

							setSlugFields( validFields );
						} }
						disabled={ isEdit && dataSourceType === 'wpdb' }
					/>
					<p className="newspack-profiles__help-text">
						{ sprintf(
							/* translators: %s is a sample single profile URL */
							__(
								'Select fields whose combination will create a unique URL for each profile. For example: First Name + Last Name creates %s',
								'newspack-profiles'
							),
							`/${ selectedProfileSlug || 'profiles' }/john-doe`
						) }
					</p>
				</div>
				<div>
					<FormTokenField
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						__experimentalShowHowTo={ false }
						__experimentalExpandOnFocus={ true }
						label={ __(
							'Profile Title Generation Fields',
							'newspack-profiles'
						) }
						placeholder={ __(
							'Add fields for generating profile titles',
							'newspack-profiles'
						) }
						value={ selectedTitleFields }
						suggestions={ dataSourceFields }
						onChange={ ( tokens ) => {
							const validFields = tokens.filter( ( token ) =>
								dataSourceFields.includes( token as string )
							) as string[];

							setTitleFields( validFields );
						} }
					/>
					<p className="newspack-profiles__help-text">
						{ sprintf(
							/* translators: %s is a person name */
							__(
								'Select fields to generate the profile page title. For example: First Name + Last Name creates "%s" as the profile page title.',
								'newspack-profiles'
							),
							'John Doe'
						) }
					</p>
				</div>
			</VStack>

			<Divider />

			<VStack
				spacing={ 4 }
				className="newspack-profiles__seo-group newspack-profiles__seo-group--bottom"
			>
				<h3>{ __( 'Profile SEO', 'newspack-profiles' ) }</h3>
				<p>
					{ __(
						'Optimize how your profile appears in search engines by customizing the name and slug used in URLs.',
						'newspack-profiles'
					) }
				</p>
				<SEOFieldInput
					label={ __( 'SEO Title', 'newspack-profiles' ) }
					fields={ dataSourceFields }
					value={ seoFields.title }
					onChange={ ( tokens ) =>
						setSeoFields( {
							...seoFields,
							title: tokens,
						} )
					}
				/>
				<SEOFieldInput
					label={ __( 'SEO Description', 'newspack-profiles' ) }
					fields={ dataSourceFields }
					value={ seoFields.description }
					onChange={ ( tokens ) =>
						setSeoFields( {
							...seoFields,
							description: tokens,
						} )
					}
				/>
				<FormTokenField
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					__experimentalShowHowTo={ false }
					__experimentalExpandOnFocus={ true }
					label={ __( 'SEO Image', 'newspack-profiles' ) }
					value={ seoFields.image ? [ seoFields.image ] : [] }
					suggestions={ dataSourceFields }
					onChange={ ( tokens ) => {
						if (
							tokens.length > 0 &&
							! dataSourceFields.includes(
								( tokens?.[ 0 ] as string ) ?? ''
							)
						) {
							return;
						}

						setSeoFields( {
							...seoFields,
							image: ( tokens?.[ 0 ] as string ) ?? '',
						} );
					} }
				/>
			</VStack>
		</div>
	);
};
