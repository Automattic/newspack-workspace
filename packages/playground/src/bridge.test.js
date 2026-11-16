import { MESSAGE, readState, writeState, connectScreen, connectFrame } from './bridge';

const dispatch = ( data, { origin = window.location.origin, source = null } = {} ) =>
	window.dispatchEvent( new MessageEvent( 'message', { data, origin, source } ) );

describe( 'writeState', () => {
	it( 'serializes in insertion order, omitting null, undefined and false', () => {
		expect( writeState( { step: 3, entry: 'tab', empty: true, x: null, y: false, z: undefined } ) ).toBe( '?step=3&entry=tab&empty=1' );
	} );
	it( 'returns an empty string for an empty result', () => {
		expect( writeState( {} ) ).toBe( '' );
	} );
} );

describe( 'readState', () => {
	it( 'parses a query string', () => {
		expect( readState( '?step=3&entry=tab' ) ).toEqual( { step: '3', entry: 'tab' } );
	} );
	it( 'returns an empty object for an empty string', () => {
		expect( readState( '' ) ).toEqual( {} );
	} );
} );

describe( 'connectFrame', () => {
	let iframe;
	beforeEach( () => {
		iframe = document.createElement( 'iframe' );
		document.body.appendChild( iframe );
	} );
	afterEach( () => iframe.remove() );

	it( 'calls onState for a valid message', () => {
		const onState = jest.fn();
		const bridge = connectFrame( iframe, { onState } );
		dispatch( { type: MESSAGE.STATE, state: { step: '2' } }, { source: iframe.contentWindow } );
		expect( onState ).toHaveBeenCalledWith( { step: '2' } );
		bridge.dispose();
	} );

	it( 'ignores a foreign origin or source', () => {
		const onState = jest.fn();
		const bridge = connectFrame( iframe, { onState } );
		dispatch( { type: MESSAGE.STATE, state: {} }, { origin: 'https://evil.test', source: iframe.contentWindow } );
		dispatch( { type: MESSAGE.STATE, state: {} }, { source: window } );
		expect( onState ).not.toHaveBeenCalled();
		bridge.dispose();
	} );

	it( 'calls onHeight for a valid height message', () => {
		const onHeight = jest.fn();
		const bridge = connectFrame( iframe, { onHeight } );
		dispatch( { type: MESSAGE.HEIGHT, height: 812 }, { source: iframe.contentWindow } );
		expect( onHeight ).toHaveBeenCalledWith( 812 );
		bridge.dispose();
	} );

	it( 'posts set-state to the frame', () => {
		const spy = jest.spyOn( iframe.contentWindow, 'postMessage' ).mockImplementation( () => {} );
		const bridge = connectFrame( iframe, {} );
		bridge.setState( { step: 1 } );
		expect( spy ).toHaveBeenCalledWith( { type: MESSAGE.SET_STATE, state: { step: 1 } }, window.location.origin );
		bridge.dispose();
	} );

	it( 'stops listening after dispose', () => {
		const onState = jest.fn();
		connectFrame( iframe, { onState } ).dispose();
		dispatch( { type: MESSAGE.STATE, state: {} }, { source: iframe.contentWindow } );
		expect( onState ).not.toHaveBeenCalled();
	} );
} );

describe( 'connectScreen', () => {
	const makeWin = () => {
		const listeners = [];
		const win = {
			location: { origin: 'http://localhost', pathname: '/p', hash: '' },
			history: { replaceState: jest.fn() },
			parent: { postMessage: jest.fn() },
			addEventListener: jest.fn( ( type, fn ) => listeners.push( fn ) ),
			removeEventListener: jest.fn( ( type, fn ) => listeners.splice( listeners.indexOf( fn ), 1 ) ),
		};
		win.emit = event => [ ...listeners ].forEach( fn => fn( event ) );
		return win;
	};

	it( 'reports state to the parent and the URL', () => {
		const win = makeWin();
		const screen = connectScreen( { win } );
		screen.report( { step: 4 } );
		expect( win.parent.postMessage ).toHaveBeenCalledWith( { type: MESSAGE.STATE, state: { step: 4 } }, 'http://localhost' );
		expect( win.history.replaceState ).toHaveBeenCalledWith( null, '', '/p?step=4' );
		screen.dispose();
	} );

	it( 'clears the query for an empty state and keeps the hash', () => {
		const win = makeWin();
		win.location.hash = '#top';
		const screen = connectScreen( { win } );
		screen.report( { a: 1 } );
		screen.report( {} );
		expect( win.history.replaceState ).toHaveBeenLastCalledWith( null, '', '/p#top' );
		screen.dispose();
	} );

	it( 'skips posting when not framed', () => {
		const win = makeWin();
		win.parent = win;
		win.postMessage = jest.fn();
		const onSetState = jest.fn();
		connectScreen( { win, onSetState } ).report( { step: 1 } );
		expect( win.postMessage ).not.toHaveBeenCalled();
		win.emit( { data: { type: MESSAGE.SET_STATE, state: {} }, origin: 'http://localhost', source: win } );
		expect( onSetState ).not.toHaveBeenCalled();
	} );

	it( 'accepts set-state only from the parent on the same origin', () => {
		const win = makeWin();
		const onSetState = jest.fn();
		const screen = connectScreen( { win, onSetState } );
		const data = { type: MESSAGE.SET_STATE, state: { step: '2' } };
		win.emit( { data, origin: 'http://localhost', source: {} } );
		win.emit( { data, origin: 'https://evil.test', source: win.parent } );
		expect( onSetState ).not.toHaveBeenCalled();
		win.emit( { data, origin: 'http://localhost', source: win.parent } );
		expect( onSetState ).toHaveBeenCalledWith( { step: '2' } );
		screen.dispose();
		win.emit( { data, origin: 'http://localhost', source: win.parent } );
		expect( onSetState ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports the content height, including when it shrinks', () => {
		const win = makeWin();
		let callback;
		const disconnect = jest.fn();
		win.ResizeObserver = jest.fn( cb => {
			callback = cb;
			return { observe: jest.fn(), disconnect };
		} );
		win.document = { documentElement: { getBoundingClientRect: () => ( { height: 100.2 } ) } };
		const screen = connectScreen( { win } );
		callback( [ { borderBoxSize: [ { blockSize: 640 } ] } ] );
		callback( [ { borderBoxSize: [ { blockSize: 300 } ] } ] );
		callback( [] );
		const heights = win.parent.postMessage.mock.calls.map( ( [ message ] ) => message.height );
		expect( heights ).toEqual( [ 640, 300, 101 ] );
		screen.dispose();
		expect( disconnect ).toHaveBeenCalled();
	} );
} );
