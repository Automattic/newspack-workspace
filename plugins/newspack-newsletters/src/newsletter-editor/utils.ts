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
import type { NewsletterMeta } from '../service-providers/types';
import { LAYOUT_CPT_SLUG } from '../utils/consts';

/**
 * Is the current editor session editing a layout post?
 *
 * Reads WP's `post-type-{cpt}` body class so the check is independent
 * of script load order (the localised global races on some loads).
 *
 * @return True if editing a layout.
 */
export const isLayoutEditor = (): boolean =>
	typeof document !== 'undefined' && !! document.body?.classList?.contains( `post-type-${ LAYOUT_CPT_SLUG }` );

/**
 * Is the current ESP a supported ESP?
 *
 * @return True if the ESP is supported.
 */
export const isSupportedESP = () => {
	const { supported_esps: supportedESPs } = newspack_email_editor_data || {};
	const { name: serviceProviderName } = getServiceProvider();
	return serviceProviderName && supportedESPs?.includes( serviceProviderName );
};

/**
 * Is the current ESP "manual"?
 *
 * @return True if the ESP is supported and connected.
 */
export const isManualESP = (): boolean => {
	const { name: serviceProviderName } = getServiceProvider();
	return 'manual' === serviceProviderName;
};

/**
 * Validation utility.
 *
 * @param meta              Post meta.
 * @param meta.senderEmail  Sender email address.
 * @param meta.senderName   Sender name.
 * @param meta.send_list_id Send-to list ID.
 * @return Array of validation messages. If empty, newsletter is valid.
 */
export const validateNewsletter = ( meta: NewsletterMeta = {} ): string[] => {
	const { name: serviceProviderName } = getServiceProvider();
	if ( 'manual' === serviceProviderName ) {
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
 * @param string String to test.
 * @return True if it contains a valid email string.
 */
export const hasValidEmail = ( string: string ): boolean => /\S+@\S+/.test( string );

/**
 * Custom hook to fetch a previous state or prop value.
 *
 * @param value of the prop or state to fetch.
 * @return The previous value of the prop or state.
 */
export const usePrevious = < T >( value: T ): T | undefined => {
	const ref = useRef< T >();
	useEffect( () => {
		ref.current = value;
	}, [ value ] );
	return ref.current;
};
