import '@testing-library/jest-dom';

// matchMedia does not exist in JSDOM, see https://jestjs.io/docs/manual-mocks#mocking-methods-which-are-not-implemented-in-jsdom
Object.defineProperty( window, 'matchMedia', {
	writable: true,
	value: jest.fn().mockImplementation( ( query ) => ( {
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
