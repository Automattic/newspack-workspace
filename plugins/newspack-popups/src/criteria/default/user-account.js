/* globals newspackPopupsCriteria */
import { setMatchingFunction } from '../utils';

setMatchingFunction( 'user_account', ( config, { store } ) => {
	switch ( config.value ) {
		case 'with-account':
			return newspackPopupsCriteria.is_non_preview_user || store.get( 'reader' )?.email;
		case 'without-account':
			return ! newspackPopupsCriteria.is_non_preview_user && ! store.get( 'reader' )?.email;
	}
} );
