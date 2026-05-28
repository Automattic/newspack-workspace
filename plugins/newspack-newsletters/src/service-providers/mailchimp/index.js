/**
 * WordPress dependencies
 */
import { __, sprintf, _n } from '@wordpress/i18n';

/**
 * External dependencies
 */
import { find } from 'lodash';

/**
 * Internal dependencies
 */
import { ProviderSidebar } from './ProviderSidebar';

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
	const { campaign, lists = [], sublists = [] } = newsletterData;
	const { send_list_id: listId, send_sublist_id: sublistId } = meta;
	if ( ! campaign || ! listId ) {
		return null;
	}
	let listData,
		sublistData,
		subscriberCount = 0;
	if ( campaign?.recipients?.list_id && campaign.recipients.list_id === listId ) {
		const list = find( lists, [ 'id', listId ] );
		if ( list ) {
			listData = list;
		}
		if ( ! isNaN( listData?.count ) ) {
			subscriberCount = parseInt( listData.count );
		}
		const sublist = find( sublists, [ 'id', sublistId.toString() ] );
		if ( sublist ) {
			sublistData = sublist;
		}
		if ( ! isNaN( sublistData?.count ) ) {
			subscriberCount = parseInt( sublistData.count );
		}
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
			{ sublistData && (
				<>
					{ sublistData.entity_type.charAt( 0 ).toUpperCase() + sublistData.entity_type.slice( 1 ) + ': ' }
					<strong>{ sublistData.name }</strong>
					<br />
				</>
			) }
			{ subscriberCount && (
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
	const { status } = newsletterData?.campaign || {};
	if ( 'sent' === status || 'sending' === status ) {
		return true;
	}
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

export default {
	displayName: 'Mailchimp',
	ProviderSidebar,
	renderPreSendInfo,
	isCampaignSent,
};
