/**
 * WordPress dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';

const DEFAULT_KEYS = [ 'senderEmail', 'senderName', 'send_list_id', 'send_sublist_id' ];

/**
 * Turn a layout's stored campaign defaults into the post meta they set.
 *
 * @param {string|Object} stringifiedCampaignDefaults Defaults as the layout picker stores them.
 * @return {Object|null} Meta to apply, or null when there is nothing to apply.
 */
export const getCampaignDefaultsMeta = stringifiedCampaignDefaults => {
	if ( ! stringifiedCampaignDefaults ) {
		return null;
	}
	let campaignDefaults = stringifiedCampaignDefaults;
	if ( 'string' === typeof campaignDefaults ) {
		try {
			campaignDefaults = JSON.parse( campaignDefaults );
		} catch {
			return null;
		}
	}
	const meta = {};
	DEFAULT_KEYS.forEach( key => {
		if ( campaignDefaults?.[ key ] ) {
			meta[ key ] = campaignDefaults[ key ];
		}
	} );
	if ( ! Object.keys( meta ).length ) {
		return null;
	}
	meta.stringifiedCampaignDefaults = '';
	return meta;
};

/**
 * Copies a chosen layout's sender and list defaults onto the newsletter, then saves.
 *
 * This must stay mounted whatever the sidebar shows. It used to live in the
 * Newsletter Campaign panel, which renders nothing while collapsed, so a new
 * newsletter could be saved and test-sent with empty sender fields and the
 * provider rejected it.
 */
const ApplyCampaignDefaults = () => {
	const stringifiedCampaignDefaults = useSelect( select => select( 'core/editor' ).getEditedPostAttribute( 'meta' )?.stringifiedCampaignDefaults );
	const { editPost, savePost } = useDispatch( 'core/editor' );

	useEffect( () => {
		const meta = getCampaignDefaultsMeta( stringifiedCampaignDefaults );
		if ( meta ) {
			editPost( { meta } );
			savePost();
		}
	}, [ stringifiedCampaignDefaults ] );

	return null;
};

export default ApplyCampaignDefaults;
