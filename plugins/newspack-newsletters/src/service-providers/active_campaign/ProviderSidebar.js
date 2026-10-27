/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';

export const ProviderSidebar = ( { inFlight, meta, updateMeta } ) => {
	// Newsletters published on the web are always public in ActiveCampaign, so
	// the choice only applies to email-only newsletters.
	const isPublicOnWeb = !! meta?.is_public;

	return (
		<CheckboxControl
			label={ __( 'Include in campaign archive', 'newspack-newsletters' ) }
			help={
				isPublicOnWeb
					? __(
							'Makes this newsletter public in ActiveCampaign. (Newsletters published on the web are always public in ActiveCampaign.)',
							'newspack-newsletters'
					  )
					: __( 'Makes this newsletter public in ActiveCampaign.', 'newspack-newsletters' )
			}
			checked={ isPublicOnWeb || !! meta?.ac_public_campaign }
			onChange={ value => updateMeta( { ac_public_campaign: value } ) }
			disabled={ inFlight || isPublicOnWeb }
			__nextHasNoMarginBottom
		/>
	);
};
