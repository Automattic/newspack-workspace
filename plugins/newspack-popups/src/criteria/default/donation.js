import { setMatchingFunction } from '../utils';

setMatchingFunction( 'donation', ( config, { store } ) => {
	switch ( config.value ) {
		case 'donors':
			return store.get( 'is_donor' );
		case 'non-donors':
			return ! store.get( 'is_donor' );
		case 'formers-donors':
			return store.get( 'is_former_donor' );
	}
} );
