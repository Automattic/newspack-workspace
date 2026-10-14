/**
 * Both validators can fail on one click, and `speak` empties the live region before
 * each write, so notices left to announce themselves report only the last one rendered.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { dispatch, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import Seo from './index';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';

const mockWizardApiFetch = jest.fn();

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: mockWizardApiFetch,
		isFetching: false,
		errorMessage: null,
		resetError: jest.fn(),
	} ),
} ) );

const typeInto = ( label: string, value: string ) => fireEvent.change( screen.getByLabelText( label ), { target: { value } } );

// Save lives in the wizard header, so the test submits through the action the tab published.
const save = () =>
	act( () => {
		select( WIZARD_STORE_NAMESPACE ).getHeaderData().actions[ 0 ].action();
	} );

beforeEach( () => {
	jest.clearAllMocks();
	( dispatch( WIZARD_STORE_NAMESPACE ) as { resetHeaderData: () => void } ).resetHeaderData();
} );

describe( 'saving SEO settings with more than one invalid field', () => {
	it( 'announces every message that blocked the save, in one announcement', () => {
		render( <Seo /> );

		typeInto( 'Google', 'nope!' );
		typeInto( 'Facebook', 'not-a-url' );
		save();

		expect( speak ).toHaveBeenCalledTimes( 1 );
		const [ announcement ] = ( speak as jest.Mock ).mock.calls[ 0 ];
		expect( announcement ).toContain( 'Google verification codes' );
		expect( announcement ).toContain( 'Facebook' );
	} );

	it( 'announces again when the same fields are submitted unchanged', () => {
		render( <Seo /> );

		typeInto( 'Google', 'nope!' );
		save();
		save();

		expect( speak ).toHaveBeenCalledTimes( 2 );
	} );
} );

describe( 'profiles stored in Yoast’s catch-all list', () => {
	it( 'blocks a save when the URL is not on the network’s own domain', () => {
		render( <Seo /> );

		typeInto( 'Bluesky', 'https://example.com/me' );
		save();

		const [ announcement ] = ( speak as jest.Mock ).mock.calls[ 0 ];
		expect( announcement ).toContain( 'bsky.app' );
	} );

	it( 'accepts a URL on the network’s own domain', () => {
		render( <Seo /> );

		typeInto( 'Bluesky', 'https://bsky.app/profile/example' );
		save();

		expect( speak ).not.toHaveBeenCalled();
		const post = mockWizardApiFetch.mock.calls.find( ( [ request ] ) => request.method === 'POST' );
		expect( post ).toBeDefined();
		expect( post[ 0 ].data.urls.bluesky ).toBe( 'https://bsky.app/profile/example' );
	} );
} );

describe( 'a successful save', () => {
	it( 'confirms with a snackbar', () => {
		( dispatch( WIZARD_STORE_NAMESPACE ) as { resetNotices: () => void } ).resetNotices();
		mockWizardApiFetch.mockImplementation( ( request, callbacks ) => {
			if ( request.method === 'POST' ) {
				callbacks?.onSuccess?.( request.data );
			}
			return Promise.resolve();
		} );
		render( <Seo /> );

		typeInto( 'Bluesky', 'https://bsky.app/profile/example' );
		save();

		expect(
			select( WIZARD_STORE_NAMESPACE )
				.getNotices()
				.map( ( notice: { message: string } ) => notice.message )
		).toContain( 'Settings saved.' );
		mockWizardApiFetch.mockReset();
	} );
} );
