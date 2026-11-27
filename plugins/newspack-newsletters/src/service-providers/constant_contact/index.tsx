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
import type { NewsletterData, NewsletterMeta, SendList, ServiceProvider } from '../types';

const hasOauth = true;

/**
 * Utility to render newsletter campaign info in the pre-send confirmation modal.
 *
 * @param newsletterData Data returned from the ESP retrieve method.
 * @param meta           Post meta.
 */
const renderPreSendInfo = ( newsletterData: NewsletterData = {}, meta: NewsletterMeta = {} ) => {
	const { campaign, lists = [] } = newsletterData;
	const { send_list_id: listId } = meta;
	if ( ! campaign || ! listId ) {
		return null;
	}
	let listData: SendList | undefined;
	let subscriberCount: number | string | undefined;
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
			{ ! isNaN( subscriberCount as number ) && (
				<strong>
					{ sprintf(
						// translators: %d: subscriber count.
						_n( '%d subscriber', '%d subscribers', subscriberCount as number, 'newspack-newsletters' ),
						subscriberCount as number
					) }
				</strong>
			) }
		</p>
	);
};

const isCampaignSent = ( newsletterData: NewsletterData, postStatus = 'draft' ) => {
	const { current_status: status } = newsletterData?.campaign || {};
	if ( 'DRAFT' !== status ) {
		return true;
	}
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

const provider: ServiceProvider = {
	displayName: 'Constant Contact',
	hasOauth,
	renderPreSendInfo,
	isCampaignSent,
};

export default provider;
