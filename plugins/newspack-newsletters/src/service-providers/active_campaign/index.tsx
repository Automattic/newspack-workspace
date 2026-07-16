/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { NewsletterData, NewsletterMeta, ServiceProvider } from '../types';

/**
 * Utility to render newsletter campaign info in the pre-send confirmation modal.
 *
 * @param newsletterData Data returned from the ESP retrieve method.
 * @param meta           Post meta.
 */
const renderPreSendInfo = ( newsletterData: NewsletterData = {}, meta: NewsletterMeta = {} ) => {
	const { lists = [], sublists = [] } = newsletterData;
	const { send_list_id: listId, send_sublist_id: sublistId } = meta;

	if ( ! lists?.length ) {
		return <Spinner />;
	}

	const list = lists.find( thisList => listId!.toString() === thisList.id.toString() );
	const segment = sublists?.find( thisSegment => sublistId!.toString() === thisSegment.id.toString() );

	if ( ! list ) {
		return null;
	}

	return (
		<>
			<p>
				{ __( 'You’re about to send an ActiveCampaign newsletter to the following list:', 'newspack-newsletters' ) }{ ' ' }
				<strong>{ list.name }</strong>
				{ segment && (
					<>
						{ __( ', segmented to:', 'newspack-newsletters' ) } <strong>{ segment.name }</strong>
					</>
				) }
			</p>
			<p>{ __( 'Are you sure you want to proceed?', 'newspack-newsletters' ) }</p>
		</>
	);
};

const isCampaignSent = ( newsletterData: NewsletterData, postStatus = 'draft' ) => {
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

const provider: ServiceProvider = {
	displayName: 'ActiveCampaign',
	renderPreSendInfo,
	isCampaignSent,
};

export default provider;
