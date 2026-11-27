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
import type { NewsletterData, NewsletterMeta, SendList, ServiceProvider } from '../types';

/**
 * Utility to render newsletter campaign info in the pre-send confirmation modal.
 *
 * @param newsletterData Data returned from the ESP retrieve method.
 * @param meta           Post meta.
 */
const renderPreSendInfo = ( newsletterData: NewsletterData = {}, meta: NewsletterMeta = {} ) => {
	const { campaign, lists = [], sublists = [] } = newsletterData;
	const { send_list_id: listId, send_sublist_id: sublistId } = meta;
	if ( ! campaign || ! listId ) {
		return null;
	}
	let listData: SendList | undefined;
	let sublistData: SendList | undefined;
	let subscriberCount = 0;
	if ( campaign?.recipients?.list_id && campaign.recipients.list_id === listId ) {
		const list = find( lists, [ 'id', listId ] );
		if ( list ) {
			listData = list;
		}
		if ( ! isNaN( listData?.count as number ) ) {
			subscriberCount = parseInt( listData!.count as string );
		}
		const sublist = find( sublists, [ 'id', sublistId!.toString() ] );
		if ( sublist ) {
			sublistData = sublist;
		}
		if ( ! isNaN( sublistData?.count as number ) ) {
			subscriberCount = parseInt( sublistData!.count as string );
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
					{ sublistData.entity_type!.charAt( 0 ).toUpperCase() + sublistData.entity_type!.slice( 1 ) + ': ' }
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

const isCampaignSent = ( newsletterData: NewsletterData, postStatus = 'draft' ) => {
	const { status } = newsletterData?.campaign || {};
	if ( 'sent' === status || 'sending' === status ) {
		return true;
	}
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

const provider: ServiceProvider = {
	displayName: 'Mailchimp',
	ProviderSidebar,
	renderPreSendInfo,
	isCampaignSent,
};

export default provider;
