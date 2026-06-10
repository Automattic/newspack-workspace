import { TextEncoder, TextDecoder } from 'util';
import '@testing-library/jest-dom';

// TextEncoder/TextDecoder are Node/web globals that JSDOM does not provide, but
// some @wordpress modules (e.g. @wordpress/sync) reference them at import time.
// Polyfill from Node's util so those modules load under the jsdom environment.
if ( typeof global.TextEncoder === 'undefined' ) {
	global.TextEncoder = TextEncoder;
}
if ( typeof global.TextDecoder === 'undefined' ) {
	global.TextDecoder = TextDecoder;
}

// matchMedia does not exist in JSDOM, see https://jestjs.io/docs/manual-mocks#mocking-methods-which-are-not-implemented-in-jsdom
Object.defineProperty( window, 'matchMedia', {
	writable: true,
	value: jest.fn().mockImplementation( query => ( {
		matches: false,
		media: query,
		onchange: null,
		addListener: jest.fn(), // deprecated
		removeListener: jest.fn(), // deprecated
		addEventListener: jest.fn(),
		removeEventListener: jest.fn(),
		dispatchEvent: jest.fn(),
	} ) ),
} );

// IntersectionObserver does not exist in JSDOM.
// https://stackoverflow.com/a/58651649/3772847
class MockIntersectionObserver {
	constructor( fn ) {
		this.root = null;
		this.rootMargin = '';
		this.thresholds = [];
		this.disconnect = () => null;
		this.observe = () => null;
		this.takeRecords = () => [];
		this.unobserve = () => null;

		// Pass a single entry, which is intersecting.
		fn( [ { isIntersecting: true } ] );
	}
}

Object.defineProperty( window, 'IntersectionObserver', {
	writable: true,
	configurable: true,
	value: MockIntersectionObserver,
} );

Object.defineProperty( global, 'IntersectionObserver', {
	writable: true,
	configurable: true,
	value: MockIntersectionObserver,
} );
