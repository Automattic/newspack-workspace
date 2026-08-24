/**
 * Regression test for the edit-view routing bug: navigating directly between rule
 * ids must remount RuleForm so it re-seeds from the newly-fetched rule, instead of
 * keeping the previous rule's mount-only state.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import RuleEdit from './rule-edit';
import { RULES_API_PATH as API_PATH } from './constants';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Stub the Router barrel so RuleEdit's useHistory() resolves without a real router.
// The returned history must be a STABLE reference: RuleEdit's load effect depends on
// it, so a fresh object per render would retrigger the effect and loop.
jest.mock( '../../../../../packages/components/src', () => {
	const history = { push: jest.fn(), replace: jest.fn() };
	return { Router: { useHistory: () => history } };
} );

// The stub seeds from mount-only state, mirroring the real form, so
// `data-seeded-path` distinguishes a genuine remount from a prop update.
jest.mock( './rule-form', () => ( { rule, initialPath } ) => {
	const { useState } = require( '@wordpress/element' );
	const [ seeded ] = useState( rule );
	const [ seededPath ] = useState( initialPath ?? '' );
	return (
		<div data-testid="rule-form" data-initial-path={ initialPath ?? '' } data-seeded-path={ seededPath }>
			{ seeded ? seeded.title : 'new' }
		</div>
	);
} );

describe( 'RuleEdit routing', () => {
	beforeEach( () => {
		apiFetch.mockClear();
		apiFetch.mockImplementation( ( { path } ) => {
			if ( path === API_PATH ) {
				return Promise.resolve( { rules: [] } ); // Vocab payload; only truthiness matters here.
			}
			if ( path === `${ API_PATH }/1` ) {
				return Promise.resolve( { id: 1, title: 'Rule A' } );
			}
			if ( path === `${ API_PATH }/2` ) {
				return Promise.resolve( { id: 2, title: 'Rule B' } );
			}
			return Promise.resolve( null );
		} );
	} );

	it( 'reseeds the form when the route switches to a different rule id', async () => {
		const { rerender } = render( <RuleEdit match={ { params: { id: '1' } } } /> );
		expect( await screen.findByText( 'Rule A' ) ).toBeInTheDocument();

		rerender( <RuleEdit match={ { params: { id: '2' } } } /> );
		expect( await screen.findByText( 'Rule B' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Rule A' ) ).not.toBeInTheDocument();
	} );

	it( 'passes the goal from the URL to the form when creating', async () => {
		render( <RuleEdit match={ { params: { goal: 'winback' } } } /> );
		expect( await screen.findByTestId( 'rule-form' ) ).toHaveAttribute( 'data-initial-path', 'winback' );
	} );

	it( 'keeps the mounted form on a goal change, passing the new goal through', async () => {
		const { rerender } = render( <RuleEdit match={ { params: { goal: 'retention' } } } /> );
		expect( await screen.findByTestId( 'rule-form' ) ).toHaveAttribute( 'data-seeded-path', 'retention' );

		rerender( <RuleEdit match={ { params: { goal: 'save' } } } /> );
		expect( await screen.findByTestId( 'rule-form' ) ).toHaveAttribute( 'data-initial-path', 'save' );
		expect( screen.getByTestId( 'rule-form' ) ).toHaveAttribute( 'data-seeded-path', 'retention' );
	} );

	it( 'treats an unrecognised goal as no goal', async () => {
		render( <RuleEdit match={ { params: { goal: 'bogus' } } } /> );
		expect( await screen.findByTestId( 'rule-form' ) ).toHaveAttribute( 'data-initial-path', '' );
	} );

	it( 'renders the form with no goal at all at #/new', async () => {
		render( <RuleEdit match={ { params: {} } } /> );
		expect( await screen.findByTestId( 'rule-form' ) ).toHaveAttribute( 'data-initial-path', '' );
	} );
} );
