/**
 * External dependencies
 */
import { parse } from 'qs';

/**
 * Internal dependencies
 */
import { Notice } from '../';

const GlobalNotices = () => {
	// The notice is emitted by the plugin as a single comma-separated string parameter.
	const notice = parse( window.location.search )[ 'newspack-notice' ] as string | undefined;
	if ( ! notice ) {
		return null;
	}
	return notice.split( ',' ).map( ( text, i ) => {
		if ( text.indexOf( '_error_' ) === 0 ) {
			return <Notice isError noticeText={ text.replace( '_error_', '' ) } key={ i } rawHTML />;
		}
		return <Notice isSuccess noticeText={ text } key={ i } />;
	} );
};

export default GlobalNotices;
