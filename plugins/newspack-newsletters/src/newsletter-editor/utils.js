/* global newspack_email_editor_data */

/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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
 * Validation utility.
 *
 * @param {Object} meta              Post meta.
 * @param {string} meta.senderEmail  Sender email address.
 * @param {string} meta.senderName   Sender name.
 * @param {string} meta.send_list_id Send-to list ID.
 * @return {string[]} Array of validation messages. If empty, newsletter is valid.
 */
export const validateNewsletter = ( meta = {} ) => {
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
