/**
 * The screen's route table, exercised through the real wizard. #/new and
 * #/new/<goal> must stay one entry: the wizard keys each section's <Route>, so a
 * second entry would remount the editor when choosing a goal rewrites the URL.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { Wizard } from '../../../../../packages/components/src';
import { SECTIONS } from './index';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );
jest.mock( './list', () => () => <p>Rules list</p> );

global.newspack_aux_data = { is_debug_mode: false };
global.newspack_urls = { support: 'https://newspack.com/support/' };
window.scrollTo = jest.fn();

const mockMounts = { count: 0 };
let mockHistory;

// Stands in for the editor: mount-counted, holding a value that survives only if
// the component is not remounted.
jest.mock( './rule-edit', () => {
	const { useState, useEffect } = require( '@wordpress/element' );
	return function RuleEditStub( { history, match } ) {
		const [ typed, setTyped ] = useState( '' );
		mockHistory = history;
		useEffect( () => {
			mockMounts.count += 1;
		}, [] );
		return (
			<>
				<input aria-label="Name" value={ typed } onChange={ e => setTyped( e.target.value ) } />
				<span data-testid="goal">{ match.params.goal ?? '' }</span>
			</>
		);
	};
} );

const renderAt = async path => {
	window.location.hash = `#${ path }`;
	let result;
	await act( async () => {
		result = render( <Wizard sections={ SECTIONS } /> );
	} );
	return result;
};

describe( 'pricing rules routes', () => {
	beforeEach( () => {
		mockMounts.count = 0;
	} );

	it( 'keeps one editor instance when choosing a goal rewrites the URL', async () => {
		await renderAt( '/new' );
		fireEvent.change( screen.getByLabelText( 'Name' ), { target: { value: 'Loyalty deal' } } );

		await act( async () => {
			mockHistory.replace( '/new/winback' );
		} );

		expect( screen.getByTestId( 'goal' ) ).toHaveTextContent( 'winback' );
		expect( screen.getByLabelText( 'Name' ) ).toHaveValue( 'Loyalty deal' );
		expect( mockMounts.count ).toBe( 1 );
	} );

	it( 'still routes the list and a saved rule to their own sections', async () => {
		const { unmount } = await renderAt( '/' );
		expect( screen.getByText( 'Rules list' ) ).toBeInTheDocument();
		unmount();

		await renderAt( '/edit/7' );
		expect( screen.getByLabelText( 'Name' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'goal' ) ).toHaveTextContent( '' );
	} );
} );
