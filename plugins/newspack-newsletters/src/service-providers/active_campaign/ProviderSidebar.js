/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

/**
 * The site-wide archive visibility: "inherit", "public" or "private".
 *
 * @return {string} The setting.
 */
const getSiteDefault = () => window.newspack_newsletters_data?.active_campaign_archive_visibility || 'inherit';

/**
 * Whether a newsletter with no choice of its own is public in ActiveCampaign.
 * Mirrors Newspack_Newsletters_Active_Campaign::is_public_campaign().
 *
 * @param {boolean} isPublicOnWeb Whether the newsletter is set to "Email and web".
 * @return {boolean} The default.
 */
const getDefaultArchiveVisibility = isPublicOnWeb => {
	const siteDefault = getSiteDefault();
	if ( 'inherit' === siteDefault ) {
		return isPublicOnWeb;
	}
	return 'public' === siteDefault;
};

export const ProviderSidebar = ( { inFlight, meta, updateMeta } ) => {
	const choice = meta?.ac_archive_visibility || '';
	const defaultIsPublic = getDefaultArchiveVisibility( !! meta?.is_public );
	const isPublic = 'public' === choice || ( 'private' !== choice && defaultIsPublic );

	// Going against the newsletter's own visibility reads as forcing it. With an
	// "always" site setting, visibility doesn't drive the default, so nothing is forced.
	const isForcible = 'inherit' === getSiteDefault();
	const include = {
		value: 'include',
		label:
			isForcible && ! defaultIsPublic
				? __( 'Force include in campaign archive', 'newspack-newsletters' )
				: __( 'Include in campaign archive', 'newspack-newsletters' ),
	};
	const exclude = {
		value: 'exclude',
		label:
			isForcible && defaultIsPublic
				? __( 'Force exclude from campaign archive', 'newspack-newsletters' )
				: __( 'Exclude from campaign archive', 'newspack-newsletters' ),
	};

	// Picking the default stores no choice, so the newsletter keeps following it.
	const onChange = value => {
		const wantsPublic = 'include' === value;
		if ( wantsPublic === defaultIsPublic ) {
			updateMeta( { ac_archive_visibility: '' } );
		} else {
			updateMeta( { ac_archive_visibility: wantsPublic ? 'public' : 'private' } );
		}
	};

	return (
		<SelectControl
			label={ __( 'ActiveCampaign campaign archive', 'newspack-newsletters' ) }
			help={ __(
				'Campaigns in the archive are public in ActiveCampaign. This can’t be changed after the newsletter is sent.',
				'newspack-newsletters'
			) }
			value={ isPublic ? 'include' : 'exclude' }
			options={ defaultIsPublic ? [ include, exclude ] : [ exclude, include ] }
			onChange={ onChange }
			disabled={ inFlight }
			__next40pxDefaultSize
			__nextHasNoMarginBottom
		/>
	);
};
