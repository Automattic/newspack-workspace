// "Edit original" on a synced pattern swaps the pattern into the newsletter's
// editor page, where this plugin used to refresh the email HTML after the
// pattern was saved and POST it to a route that does not exist for patterns.
// The store below answers only the question the gate asks, so if the plugin
// went on to read anything else for the pattern, the render would throw.
jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( 'mjml-browser', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: callback => callback( () => ( { getCurrentPostType: () => 'wp_block' } ) ),
	useDispatch: () => {
		throw new Error( 'The MJML plugin must not act on a synced pattern.' );
	},
} ) );

// Stubbed because they build on the real data store, which is mocked above.
// The gate itself is the real one.
jest.mock( '../../service-providers', () => ( { getServiceProvider: jest.fn() } ) );
jest.mock( '../../newsletter-editor/store', () => ( {} ) );
jest.mock( '../../newsletter-editor/utils', () => ( {
	...jest.requireActual( '../../utils/opened-post' ),
	isLayoutEditor: () => false,
	usePrevious: jest.fn(),
} ) );

import { render } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import MJMLPlugin from './index';

describe( 'MJML plugin while a synced pattern is open', () => {
	beforeEach( () => {
		document.body.className = 'post-type-newspack_nl_cpt';
	} );

	afterEach( () => {
		document.body.className = '';
	} );

	it( 'renders nothing and sends no request', () => {
		const { container } = render( <MJMLPlugin /> );

		expect( container ).toBeEmptyDOMElement();
		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );
