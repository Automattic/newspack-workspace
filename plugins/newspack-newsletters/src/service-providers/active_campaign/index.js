/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Spinner } from '@wordpress/components';

/**
 * Utility to render newsletter campaign info in the pre-send confirmation modal.
 *
 * @param {Object} newsletterData          Data returned from the ESP retrieve method.
 * @param {Object} newsletterData.lists    Available send lists.
 * @param {Object} newsletterData.sublists Available send sublists (segments).
 * @param {Object} meta                    Post meta.
 * @param {string} meta.send_list_id       Send-to list ID.
 * @param {string} meta.send_sublist_id    Send-to sublist ID.
 */
const renderPreSendInfo = ( newsletterData = {}, meta = {} ) => {
	const { lists = [], sublists = [] } = newsletterData;
	const { send_list_id: listId, send_sublist_id: sublistId } = meta;

	if ( ! lists?.length ) {
		return <Spinner />;
	}

	const list = lists.find( thisList => listId.toString() === thisList.id.toString() );
	const segment = sublists?.find( thisSegment => sublistId.toString() === thisSegment.id.toString() );

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

const isCampaignSent = ( newsletterData, postStatus = 'draft' ) => {
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

export default {
	displayName: 'ActiveCampaign',
	renderPreSendInfo,
	isCampaignSent,
};
