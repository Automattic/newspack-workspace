/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { NewsletterData, NewsletterMeta, ProviderSidebarProps, SendList, ServiceProvider } from '../types';

// `find` is referenced by `renderPreSendInfo` below but is not imported in this
// module (unlike the other providers, which import it from lodash). This
// type-only declaration documents the identifier without emitting any runtime
// binding, preserving the original behavior: calling `renderPreSendInfo` for
// the example provider throws a ReferenceError. See the migration report.
declare const find: < T >( collection: T[] | undefined, predicate: unknown ) => T | undefined;

/** Whether the ESP requires OAuth authentication. */
const hasOauth = false;

/**
 * Component to be rendered in the sidebar panel for data and controls that are
 * specific to the active ESP. Has full control over the panel contents
 * rendering, so that it's possible to render e.g. a loader while the data is
 * not yet available.
 */
const ProviderSidebar = ( { inFlight, postId, meta, updateMeta }: ProviderSidebarProps ) => {
	return (
		<>
			<strong className="newspack-newsletters__label">{ __( 'Provider-specific sidebar content', 'newspack-newsletters' ) }</strong>
			<p>{ __( 'Post ID:', 'newspack-newsletters' ) + postId }</p>
			<TextControl
				disabled={ inFlight }
				label={ __( 'Name placeholder', 'newspack-newsletters' ) }
				value={ meta?.field_name as string }
				onChange={ value => updateMeta!( { field_name: value } ) }
			/>
		</>
	);
};

/**
 * Utility to render newsletter campaign info in the pre-send confirmation modal.
 * Can return null if no additional info is to be presented.
 *
 * @param newsletterData Data returned from the ESP retrieve method.
 * @param meta           Post meta.
 */
const renderPreSendInfo = ( newsletterData: NewsletterData = {}, meta: NewsletterMeta = {} ) => {
	const { campaign, lists = [], sublists = [] } = newsletterData;
	const { send_list_id: listId, send_sublist_id: sublistId } = meta;
	const list = find( lists, [ 'id', listId ] );

	let listData: SendList | undefined;
	let sublistData: SendList | undefined;
	let subscriberCount: number | undefined;
	if ( list ) {
		listData = list;
	}
	const sublist = find( sublists, [ 'id', sublistId!.toString() ] );
	if ( sublist ) {
		sublistData = sublist;
	}
	if ( campaign?.recipients?.recipient_count ) {
		subscriberCount = parseInt( campaign.recipients.recipient_count as string );
	}

	return (
		<p>
			{ __( "You're sending a newsletter to:", 'newspack-newsletters' ) }
			<br />
			<strong>{ listData!.name }</strong>
			<br />
			{ sublistData && (
				<>
					{ sublistData.entity_type!.charAt( 0 ).toUpperCase() + sublistData.entity_type!.slice( 1 ) + ': ' }
					<strong>{ sublistData.name }</strong>
					<br />
				</>
			) }
			<strong>
				{ sprintf(
					// translators: %d: subscriber count.
					_n( '%d subscriber', '%d subscribers', subscriberCount as number, 'newspack-newsletters' ),
					subscriberCount as number
				) }
			</strong>
		</p>
	);
};

/**
 * Function to determine if the campaign has been sent. Can rely on data from
 * the ESP retrieve method, on the current post status, or both.
 *
 * @param newsletterData The data returned from the ESP retrieve method.
 * @param postStatus     The post's current status.
 * @return True if the campaign has been sent, otherwise false.
 */
const isCampaignSent = ( newsletterData: NewsletterData, postStatus = 'draft' ) => {
	if ( 'publish' === postStatus || 'private' === postStatus ) {
		return true;
	}
	return false;
};

const provider: ServiceProvider = {
	hasOauth,
	ProviderSidebar,
	renderPreSendInfo,
	isCampaignSent,
};

export default provider;
