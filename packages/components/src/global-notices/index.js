/**
 * External dependencies
 */
import { parse } from 'qs';

/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';

/**
 * Renders notices passed through the `newspack-notice` query parameter.
 *
 * The text comes off the query string, so it is rendered as plain text rather than
 * markup. Do not reach for `__unstableHTML` here to match the other notices: that
 * would let a crafted admin URL inject markup into wp-admin.
 *
 * These mount together, and `speak()` clears the live regions before it writes, so
 * only the last announcement would survive. The first message is the one that
 * matters, since a redirect puts the error first, so it is the one announced.
 */
const GlobalNotices = () => {
	const notice = parse( window.location.search, { ignoreQueryPrefix: true } )[ 'newspack-notice' ];
	if ( typeof notice !== 'string' || ! notice ) {
		return null;
	}
	return notice.split( ',' ).map( ( text, i ) => {
		const isError = text.indexOf( '_error_' ) === 0;
		const message = isError ? text.replace( '_error_', '' ) : text;
		return (
			<Notice isDismissible={ false } status={ isError ? 'error' : 'success' } key={ i } spokenMessage={ 0 === i ? message : '' }>
				{ message }
			</Notice>
		);
	} );
};

export default GlobalNotices;
