/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, CheckboxControl, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Whether a newsletter with no choice of its own is public in ActiveCampaign.
 * Mirrors Newspack_Newsletters_Active_Campaign::is_public_campaign().
 *
 * @param {boolean} isPublicOnWeb Whether the newsletter is set to "Email and web".
 * @return {boolean} The default.
 */
const getDefaultArchiveVisibility = isPublicOnWeb => {
	const siteDefault = window.newspack_newsletters_data?.active_campaign_archive_visibility || 'inherit';
	if ( 'inherit' === siteDefault ) {
		return isPublicOnWeb;
	}
	return 'public' === siteDefault;
};

export const ProviderSidebar = ( { inFlight, meta, updateMeta } ) => {
	const choice = meta?.ac_archive_visibility || '';
	const isOverridden = 'public' === choice || 'private' === choice;
	const isPublic = isOverridden ? 'public' === choice : getDefaultArchiveVisibility( !! meta?.is_public );

	return (
		<VStack spacing={ 1 }>
			<CheckboxControl
				label={ __( 'Include in campaign archive', 'newspack-newsletters' ) }
				help={
					isOverridden
						? __(
								'Makes this newsletter public in ActiveCampaign. Overrides the default in Newsletters settings.',
								'newspack-newsletters'
						  )
						: __( 'Makes this newsletter public in ActiveCampaign. Follows the default in Newsletters settings.', 'newspack-newsletters' )
				}
				checked={ isPublic }
				onChange={ value => updateMeta( { ac_archive_visibility: value ? 'public' : 'private' } ) }
				disabled={ inFlight }
				__nextHasNoMarginBottom
			/>
			{ isOverridden && (
				<Button variant="link" disabled={ inFlight } onClick={ () => updateMeta( { ac_archive_visibility: '' } ) }>
					{ __( 'Use default', 'newspack-newsletters' ) }
				</Button>
			) }
		</VStack>
	);
};
