/**
 * WordPress dependencies
 */
import { isInTheFuture, date } from '@wordpress/date';

interface Ad {
	status: string;
	meta: {
		expiry_date?: string;
	};
}

export const isAdActive = ( ad: Ad ) => {
	if ( ad.status === 'publish' ) {
		if ( ad.meta.expiry_date ) {
			const adDayDate = date( 'Y-m-d', ad.meta.expiry_date );
			const todayDate = date( 'Y-m-d' );
			return adDayDate === todayDate || isInTheFuture( adDayDate );
		}
		return true;
	}
	return false;
};
