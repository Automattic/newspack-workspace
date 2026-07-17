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
	const history = { push: jest.fn() };
	return { Router: { useHistory: () => history } };
} );

// Stub RuleForm with a component that seeds its displayed rule from mount-only
// state — mirroring the real form, whose useState initializers run only on mount.
// It therefore reflects a new rule only when actually remounted, so the assertions
// below verify RuleEdit remounts the form on an id change.
jest.mock( './rule-form', () => ( { rule } ) => {
	const { useState } = require( '@wordpress/element' );
	const [ seeded ] = useState( rule );
	return <div data-testid="rule-form">{ seeded ? seeded.title : 'new' }</div>;
} );

describe( 'RuleEdit routing', () => {
	beforeEach( () => {
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
} );
