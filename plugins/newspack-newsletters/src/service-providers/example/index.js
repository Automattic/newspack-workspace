/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';

/**
 * @type {boolean} Whether the ESP requires OAuth authentication.
 */
const hasOauth = false;

/**
 * Component to be rendered in the sidebar panel for
 * data and controls that are specific to the active ESP.
 * Has full control over the panel contents rendering,
 * so that it's possible to render e.g. a loader while
 * the data is not yet available.
 *
 * @param {Object}   props            Component props.
 * @param {boolean}  props.inFlight   True if the editor is in the middle of an async operation.
 * @param {number}   props.postId     ID of the edited newsletter post.
 * @param {Object}   props.meta       Current edited post meta.
 * @param {Function} props.updateMeta Function to update meta by key.
 */
const ProviderSidebar = ( { inFlight, postId, meta, updateMeta } ) => {
	return (
		<>
			<strong className="newspack-newsletters__label">{ __( 'Provider-specific sidebar content', 'newspack-newsletters' ) }</strong>
			<p>{ __( 'Post ID:', 'newspack-newsletters' ) + postId }</p>
			<TextControl
				disabled={ inFlight }
				label={ __( 'Name placeholder', 'newspack-newsletters' ) }
				value={ meta?.field_name }
				onChange={ value => updateMeta( { field_name: value } ) }
			/>
		</>
	);
};

/**
 * Utility to render newsletter campaign info in the pre-send confirmation modal.
 * Can return null if no additional info is to be presented.
 *
 * @param {Object} newsletterData          Data returned from the ESP retrieve method.
 * @param {Object} newsletterData.campaign Campaign data returned from the ESP retrieve method.
 * @param {Object} newsletterData.lists    Available send lists.
 * @param {Object} newsletterData.sublists Available send sublists.
 * @param {Object} meta                    Post meta.
 * @param {string} meta.send_list_id       Send-to list ID.
 * @param {string} meta.send_sublist_id    Send-to sublist ID.
 */
const renderPreSendInfo = ( newsletterData = {}, meta = {} ) => {
	const { campaign, lists = [], sublists = [] } = newsletterData;
	const { send_list_id: listId, send_sublist_id: sublistId } = meta;
	const list = find( lists, [ 'id', listId ] );

	let listData, sublistData, subscriberCount;
	if ( list ) {
		listData = list;
	}
	const sublist = find( sublists, [ 'id', sublistId.toString() ] );
	if ( sublist ) {
		sublistData = sublist;
	}
	if ( campaign?.recipients?.recipient_count ) {
		subscriberCount = parseInt( campaign.recipients.recipient_count );
	}

	return (
		<p>
			{ __( "You're sending a newsletter to:", 'newspack-newsletters' ) }
			<br />
			<strong>{ listData.name }</strong>
			<br />
			{ sublistData && (
				<>
					{ sublistData.entity_type.charAt( 0 ).toUpperCase() + sublistData.entity_type.slice( 1 ) + ': ' }
					<strong>{ sublistData.name }</strong>
					<br />
				</>
			) }
			<strong>
				{ sprintf(
					// translators: %d: subscriber count.
					_n( '%d subscriber', '%d subscribers', subscriberCount, 'newspack-newsletters' ),
					subscriberCount
				) }
			</strong>
		</p>
	);
};

/**
 * Function to determine if the campaign has been sent.
 * Can rely on data from the ESP retrieve method, on the current post status, or both.
 *
 * @param {Object} newsletterData The data returned from the ESP retrieve method
 * @param {string} postStatus     The post's current status.
 * @return {boolean} True if the campaign has been sent, otherwise false.
 */
const isCampaignSent = ( newsletterData, postStatus = 'draft' ) => {
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

export default {
	hasOauth,
	ProviderSidebar,
	renderPreSendInfo,
	isCampaignSent,
};
