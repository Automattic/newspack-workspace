/**
 * External dependencies
 */
import { parse } from 'qs';

/**
 * Internal dependencies
 */
import { Notice } from '../';

const GlobalNotices = () => {
	const notice = parse( window.location.search )[ 'newspack-notice' ];
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
