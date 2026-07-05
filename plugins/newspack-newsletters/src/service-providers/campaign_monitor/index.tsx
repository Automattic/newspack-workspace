/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * External dependencies
 */
import { find } from 'lodash';

/**
 * Internal dependencies
 */
import type { NewsletterData, NewsletterMeta, SendList, ServiceProvider } from '../types';

/**
 * Utility to render newsletter campaign info in the pre-send confirmation modal.
 *
 * @param newsletterData Data returned from the ESP retrieve method.
 * @param meta           Post meta.
 */
const renderPreSendInfo = ( newsletterData: NewsletterData = {}, meta: NewsletterMeta = {} ) => {
	const { lists = [] } = newsletterData;
	const { send_list_id: listId } = meta;
	if ( ! listId ) {
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
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

const provider: ServiceProvider = {
	displayName: 'Campaign Monitor',
	renderPreSendInfo,
	isCampaignSent,
};

export default provider;
