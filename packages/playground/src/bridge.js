export const MESSAGE = {
	STATE: 'np-playground:state',
	HEIGHT: 'np-playground:height',
	SET_STATE: 'np-playground:set-state',
};

export function readState( search ) {
	return Object.fromEntries( new URLSearchParams( search ) );
}

export function writeState( state ) {
	const params = new URLSearchParams();
	Object.entries( state ).forEach( ( [ key, value ] ) => {
		if ( value === null || value === undefined || value === false ) {
			return;
		}
		params.set( key, value === true ? '1' : String( value ) );
	} );
	const query = params.toString();
	return query ? '?' + query : '';
}

export function connectScreen( { win = window, onSetState } = {} ) {
	const framed = win.parent !== win;
	const post = message => framed && win.parent.postMessage( message, win.location.origin );

	const onMessage = event => {
		if ( event.source !== win.parent || event.origin !== win.location.origin ) {
			return;
		}
		if ( event.data?.type === MESSAGE.SET_STATE && onSetState ) {
			onSetState( event.data.state );
		}
	};
	win.addEventListener( 'message', onMessage );

	let observer;
	if ( typeof win.ResizeObserver !== 'undefined' ) {
		const root = win.document.documentElement;
		observer = new win.ResizeObserver( () => post( { type: MESSAGE.HEIGHT, height: root.scrollHeight } ) );
		observer.observe( root );
	}

	return {
		report( state ) {
			post( { type: MESSAGE.STATE, state } );
			win.history.replaceState( null, '', writeState( state ) );
		},
		dispose() {
			win.removeEventListener( 'message', onMessage );
			observer?.disconnect();
		},
	};
}

export function connectFrame( iframe, { onState, onHeight } = {} ) {
	const onMessage = event => {
		if ( event.source !== iframe.contentWindow || event.origin !== window.location.origin ) {
			return;
		}
		const { type, state, height } = event.data || {};
		if ( type === MESSAGE.STATE && onState ) {
			onState( state );
		} else if ( type === MESSAGE.HEIGHT && onHeight ) {
			onHeight( height );
		}
	};
	window.addEventListener( 'message', onMessage );

	return {
		setState( state ) {
			iframe.contentWindow.postMessage( { type: MESSAGE.SET_STATE, state }, window.location.origin );
		},
		dispose() {
			window.removeEventListener( 'message', onMessage );
		},
	};
}
