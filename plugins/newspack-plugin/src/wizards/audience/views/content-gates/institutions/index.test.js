/**
 * Deleting an institution drops the fetched option list the gate pickers name institutions from.
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
// jsdom env (data-store side effects throw at import); the behavior under
// test does not touch either.
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

// DataViews and the router are irrelevant to the behavior under test.
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

		const { RenderModal } = mockDataViewsProps.current.actions.find( action => action.id === 'delete' );
		render( <RenderModal items={ [ { id: 1 } ] } closeModal={ () => {} } /> );

		await act( async () => {
			fireEvent.click( screen.getByText( 'Delete' ) );
		} );

		expect( mockInvalidate ).toHaveBeenCalledWith( 'institution' );
	} );
} );

describe( 'Institutions list — address lists', () => {
	const renderCell = async ( fieldId, meta ) => {
		mockApiFetch.mockResolvedValue( [] );
		render( <Institutions /> );
		await waitFor( () => expect( mockDataViewsProps.current ).not.toBeNull() );
		const field = mockDataViewsProps.current.fields.find( ( { id } ) => id === fieldId );
		return render( field.render( { item: { id: 1, title: { raw: 'City Library' }, meta } } ) ).container;
	};

	it( 'shows one space after a separator saved with one', async () => {
		const cell = await renderCell( 'email_domain', { np_institution_email_domain: 'a.edu, b.edu' } );

		expect( cell.textContent ).toBe( 'a.edu, b.edu' );
		expect( [ ...cell.querySelectorAll( '.newspack-institutions__list-item' ) ].map( item => item.textContent ) ).toEqual( [
			'a.edu,',
			'b.edu',
		] );
	} );

	it( 'splits reader data on semicolons only', async () => {
		const cell = await renderCell( 'reader_data', { np_institution_reader_data: 'org=Acme,Inc;role=staff' } );

		expect( cell.textContent ).toBe( 'org=Acme,Inc; role=staff' );
	} );

	it( 'adds a space where none follows the separator', async () => {
		const cell = await renderCell( 'ip_range', { np_institution_ip_range: '192.0.2.0/24,198.51.100.10-198.51.100.40' } );

		expect( cell.textContent ).toBe( '192.0.2.0/24, 198.51.100.10-198.51.100.40' );
	} );
} );
