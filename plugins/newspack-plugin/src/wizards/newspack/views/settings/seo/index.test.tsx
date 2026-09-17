/**
 * Both validators can fail on one click, and `speak` empties the live region before
 * each write, so notices left to announce themselves report only the last one rendered.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import Seo from './index';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '../../../../hooks/use-wizard-api-fetch', () => ( {
	useWizardApiFetch: () => ( {
		wizardApiFetch: jest.fn(),
		isFetching: false,
		errorMessage: null,
		resetError: jest.fn(),
	} ),
} ) );

const typeInto = ( label: string, value: string ) => fireEvent.change( screen.getByLabelText( label ), { target: { value } } );

const save = () => fireEvent.click( screen.getByRole( 'button', { name: 'Save Settings' } ) );

beforeEach( () => jest.clearAllMocks() );

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
