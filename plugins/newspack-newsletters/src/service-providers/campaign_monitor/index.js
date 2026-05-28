/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import { find } from 'lodash';

/**
 * Utility to render newsletter campaign info in the pre-send confirmation modal.
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
	const { lists = [] } = newsletterData;
	const { send_list_id: listId } = meta;
	if ( ! listId ) {
		return null;
	}
	let listData, subscriberCount;
	const list = find( lists, [ 'id', listId.toString() ] );
	if ( list ) {
		listData = list;
		subscriberCount = listData?.count;
	}

	if ( ! listData ) {
		return null;
	}

	return (
		<p>
			{ __( "You're sending a newsletter to:", 'newspack-newsletters' ) }
			<br />
			<strong>{ listData.name }</strong>
			<br />
			{ ! isNaN( subscriberCount ) && (
				<strong>
					{ sprintf(
						// translators: %d: subscriber count.
						_n( '%d subscriber', '%d subscribers', subscriberCount, 'newspack-newsletters' ),
						subscriberCount
					) }
				</strong>
			) }
		</p>
	);
};

const isCampaignSent = ( newsletterData, postStatus = 'draft' ) => {
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

export default {
	displayName: 'Campaign Monitor',
	renderPreSendInfo,
	isCampaignSent,
};
