/* global newspack_email_editor_data */

/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getServiceProvider } from '../service-providers';
import { LAYOUT_CPT_SLUG } from '../utils/consts';
import { isManualProvider } from '../utils/service-provider';

/**
 * Is the current editor session editing a layout post?
 *
 * Reads WP's `post-type-{cpt}` body class so the check is independent
 * of script load order (the localised global races on some loads).
 *
 * @return {boolean} True if editing a layout.
 */
export const isLayoutEditor = () => typeof document !== 'undefined' && !! document.body?.classList?.contains( `post-type-${ LAYOUT_CPT_SLUG }` );

/**
 * Is the current ESP a supported ESP?
 *
 * @return {boolean} True if the ESP is supported.
 */
export const isSupportedESP = () => {
	const { supported_esps: supportedESPs } = newspack_email_editor_data || {};
	const { name: serviceProviderName } = getServiceProvider();
	return serviceProviderName && supportedESPs?.includes( serviceProviderName );
};

/**
 * The ESP's send lists, but only once a missing stored id would mean something.
 *
 * Gated on the `retrieve` request rather than on the send-list fetch. Every
 * active provider's `retrieve` asks for the stored `send_list_id` by id and
 * widens only if that lookup fails, so once it has completed, a stored id still
 * absent from `lists` did not resolve against the roster `retrieve` returned.
 * That also survives the cap on how many lists `retrieve` returns for the
 * autocomplete, since the stored id is requested explicitly rather than hoped
 * for among the first page.
 *
 * The flag latches. `retrieve` runs again after every save, and withholding the
 * answer for each in-flight request re-enabled Send on exactly the newsletters
 * this check exists to block. A request in flight cannot change the answer
 * anyway, because the store keeps the roster it already has across a refetch.
 *
 * `hasRetrievedLists` tracks a different request, which only the sidebar issues.
 * Gating on it would tie the send guard to whether the author had opened a panel,
 * leaving the check asleep whenever they had not.
 *
 * @param {Object}  newsletterDataState                  Result of the `useNewsletterData` hook.
 * @param {Object}  newsletterDataState.newsletterData   Newsletter data from the store.
 * @param {boolean} newsletterDataState.hasRetrievedData Whether a `retrieve` has succeeded.
 * @return {?Object[]} The provider's lists once they are known, which may be an
 *                     empty array when the account genuinely has none. Null
 *                     while the answer is still unknown. The two are different
 *                     answers: an empty roster settles that a stored id cannot
 *                     resolve, where null settles nothing.
 */
export const getSettledSendLists = ( { newsletterData, hasRetrievedData } = {} ) => {
	if ( ! hasRetrievedData ) {
		return null;
	}
	return newsletterData?.lists ?? [];
};

/**
 * Validation utility.
 *
 * @param {Object}    meta              Post meta.
 * @param {string}    meta.senderEmail  Sender email address.
 * @param {string}    meta.senderName   Sender name.
 * @param {string}    meta.send_list_id Send-to list ID.
 * @param {?Object[]} sendLists         Send lists from the connected ESP, or null
 *                                      while that is still unknown. Null skips the
 *                                      resolution check; an empty array does not.
 * @return {string[]} Array of validation messages. If empty, newsletter is valid.
 */
export const validateNewsletter = ( meta = {}, sendLists = null ) => {
	if ( isManualProvider() ) {
		return [];
	}
	const { senderEmail, senderName, send_list_id: listId } = meta;
	const messages = [];
	if ( ! senderEmail || ! senderName ) {
		messages.push( __( 'Missing required sender info.', 'newspack-newsletters' ) );
	}
	if ( ! listId ) {
		messages.push( __( 'Missing required list.', 'newspack-newsletters' ) );
	} else if ( sendLists && ! sendLists.find( item => item.id.toString() === listId.toString() ) ) {
		// A stored list id survives an ESP switch, so a set id is not the same thing
		// as a reachable audience — only one the connected provider still knows
		// about counts.
		//
		// The provider's own word for a list, matching the sidebar warning for this
		// same condition: a Mailchimp publisher reading "list" here and "audience"
		// there sees two problems where there is one.
		const listLabel = window.newspack_newsletters_data?.labels?.list || __( 'list', 'newspack-newsletters' );
		messages.push(
			sprintf(
				// Translators: Shown when a newsletter's stored list isn't available in the connected ESP. %s is the ESP's label for the list entity.
				__(
					'The saved %s isn’t available in the connected email service provider. Choose a new one before sending.',
					'newspack-newsletters'
				),
				listLabel
			)
		);
	}
	return messages;
};

/**
 * Test if a string contains valid email addresses.
 *
 * @param {string} string String to test.
 * @return {boolean} True if it contains a valid email string.
 */
export const hasValidEmail = string => /\S+@\S+/.test( string );

/**
 * Custom hook to fetch a previous state or prop value.
 *
 * @param {string} value of the prop or state to fetch.
 * @return {*} The previous value of the prop or state.
 */
export const usePrevious = value => {
	const ref = useRef();
	useEffect( () => {
		ref.current = value;
	}, [ value ] );
	return ref.current;
};
