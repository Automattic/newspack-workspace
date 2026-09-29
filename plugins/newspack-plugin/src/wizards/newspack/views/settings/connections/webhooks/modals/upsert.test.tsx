/**
 * Validation runs synchronously, so a clear and a set in one handler batch into a
 * single commit and the message never transitions. Without something else forcing it,
 * a second submit against the same bad input announces nothing.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import Upsert from './upsert';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const ENDPOINT = { id: 0, url: '', actions: [], disabled: false, label: '' };

// `error` lives on the parent screen, so the modal only sees a message once it comes back down.
const Harness = () => {
	const [ error, setError ] = useState< string | null >( null );
	return (
		<Upsert
			endpoint={ ENDPOINT as never }
			actions={ [ 'reader_registered' ] }
			errorMessage={ error }
			inFlight={ false }
			setError={ setError as never }
			setAction={ jest.fn() }
			setEndpoints={ jest.fn() }
			wizardApiFetch={ jest.fn() as never }
		/>
	);
};

const save = () => fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

// jsdom ships no Element.scrollTo.
beforeAll( () => {
	Element.prototype.scrollTo = jest.fn();
} );

beforeEach( () => jest.clearAllMocks() );

describe( 'submitting the endpoint form with an invalid URL', () => {
	it( 'announces the same failure again on a second submit', () => {
		render( <Harness /> );

		save();
		expect( speak ).toHaveBeenCalledTimes( 1 );

		save();
		expect( speak ).toHaveBeenCalledTimes( 2 );
		expect( ( speak as jest.Mock ).mock.calls[ 1 ][ 0 ] ).toEqual( ( speak as jest.Mock ).mock.calls[ 0 ][ 0 ] );
	} );
} );
