// https://gist.github.com/sibelius/60a4e11da1f826b8d60dc3975a1ac805

/**
 * External dependencies.
 */
import { useEffect, useRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Router from '../proxied-imports/router';

const { useHistory } = Router;

export default ( when: boolean, message: string ): ( () => void ) => {
	const history = useHistory();
	const self = useRef( null );

	const onWindowOrTabClose = ( event: Event | undefined ) => {
		if ( ! when ) {
			return;
		}

		if ( typeof event === 'undefined' ) {
			event = window.event;
		}

		return message;
	};

	useEffect( () => {
		if ( when ) {
			self.current = history.block( message );
			window.addEventListener( 'beforeunload', onWindowOrTabClose );
		}

		return () => {
			if ( self.current ) {
				( self.current as () => void )();
			}
			window.removeEventListener( 'beforeunload', onWindowOrTabClose );
		};
	}, [ message, when ] );

	return self.current || ( () => {} );
};
