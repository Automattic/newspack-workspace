import { setMatchingFunction } from '../utils';

setMatchingFunction( 'newsletter', ( config, { store } ) => {
	switch ( config.value ) {
		case 'subscribers':
			return store.get( 'is_newsletter_subscriber' );
		case 'non-subscribers':
			return ! store.get( 'is_newsletter_subscriber' );
	}
} );
