/**
 * The institutions list.
 */

/**
 * External dependencies
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import Institutions from './index';

// mock-prefixed so Jest's hoisted jest.mock factories may close over them.
const mockApiFetch = jest.fn();
const mockInvalidate = jest.fn();
const mockDataViewsProps = { current: null };

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: ( ...args ) => mockApiFetch( ...args ) } ) );

// One object for the life of the suite: the list's fetch callback closes over these, and
// a fresh object per render would re-run the fetch effect on every render.
jest.mock( '@wordpress/data', () => {
	const dispatch = {
		setHeaderData: jest.fn(),
		addNotice: jest.fn(),
	};
	return { useDispatch: () => dispatch };
} );

// The real @wordpress/components and @wordpress/dataviews cannot load in this
// jsdom env (data-store side effects throw at import); the store-sync contract
// under test does not touch either.
jest.mock( '@wordpress/components', () => {
	const React = require( 'react' );
	const Passthrough = ( { children } ) => React.createElement( 'div', null, children );
	// A real button, so the delete confirmation's click handler can be exercised.
	const Button = ( { children, onClick, disabled } ) => React.createElement( 'button', { onClick, disabled }, children );
	return { Button, Spinner: Passthrough };
} );

jest.mock( '@wordpress/dataviews', () => ( {
	filterSortAndPaginate: data => ( { data, paginationInfo: { totalItems: data.length, totalPages: 1 } } ),
} ) );

// DataViews and the router are irrelevant to the store-sync contract.
jest.mock( '../../../../../../packages/components/src', () => {
	const React = require( 'react' );
	return {
		// Captured rather than rendered: the row actions are declared here and rendered
		// by the real DataViews, which cannot load in this jsdom env.
		DataViews: props => {
			mockDataViewsProps.current = props;
			return React.createElement( 'div', null, 'DataViews' );
		},
		Router: { useHistory: () => ( { push: jest.fn() } ) },
	};
} );

jest.mock( '../../../../../content-gate/access-rule-option-sources', () => ( {
	INSTITUTION_RULE_SLUG: 'institution',
	invalidateAccessRuleOptions: ( ...args ) => mockInvalidate( ...args ),
} ) );

jest.mock( '../../../../../../packages/components/src/wizard/store', () => ( {
	WIZARD_STORE_NAMESPACE: 'newspack/wizards',
} ) );

jest.mock( '../consts', () => ( {
	AUDIENCE_CONTENT_GATES_WIZARD_SLUG: 'newspack-audience-access-control',
} ) );

jest.mock( './onboarding', () => () => null );

describe( 'Institutions list — fetched option list invalidation', () => {
	// The gate pickers and summaries name institutions from a list fetched once per app
	// lifetime, so a deletion here has to drop it, or a deleted institution stays named
	// and selectable wherever a gate is inspected.
	it( 'drops the cached list when an institution is deleted', async () => {
		mockInvalidate.mockClear();
		mockApiFetch.mockResolvedValue( [ { id: 1, title: { raw: 'City Library' }, meta: {} } ] );

		render( <Institutions /> );
		await waitFor( () => expect( mockDataViewsProps.current ).not.toBeNull() );

		const { RenderModal } = mockDataViewsProps.current.actions.find( action => action.isDestructive );
		render( <RenderModal items={ [ { id: 1 } ] } closeModal={ () => {} } /> );

		await act( async () => {
			fireEvent.click( screen.getByText( 'Delete' ) );
		} );

		expect( mockInvalidate ).toHaveBeenCalledWith( 'institution' );
	} );
} );
