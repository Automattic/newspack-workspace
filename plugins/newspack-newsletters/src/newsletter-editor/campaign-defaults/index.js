/**
 * WordPress dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { updateNewsletterData } from '../store';

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
 * Keep this outside any collapsible sidebar panel: a collapsed panel renders
 * nothing, and the newsletter would then save and test-send with empty sender
 * fields, which the provider rejects.
 */
const ApplyCampaignDefaults = () => {
	const stringifiedCampaignDefaults = useSelect( select => select( 'core/editor' ).getEditedPostAttribute( 'meta' )?.stringifiedCampaignDefaults );
	const { editPost, savePost } = useDispatch( 'core/editor' );

	useEffect( () => {
		const meta = getCampaignDefaultsMeta( stringifiedCampaignDefaults );
		if ( meta ) {
			editPost( { meta } );
			// The Newsletter Campaign panel copies any sender or list held in the
			// fetched provider data into the post when it mounts. Clear the values
			// the layout just set, or a value fetched earlier overwrites them.
			updateNewsletterData( Object.fromEntries( DEFAULT_KEYS.filter( key => key in meta ).map( key => [ key, undefined ] ) ) );
			savePost();
		}
	}, [ stringifiedCampaignDefaults ] );

	return null;
};

export default ApplyCampaignDefaults;
