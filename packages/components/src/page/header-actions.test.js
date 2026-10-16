/**
 * External dependencies.
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { useViewportMatch } from '@wordpress/compose';
import { plus } from '@wordpress/icons';

/**
 * Internal dependencies.
 */
import Page from './';
import { isActionList } from './header-actions';

jest.mock( '@wordpress/compose', () => ( {
	...jest.requireActual( '@wordpress/compose' ),
	useViewportMatch: jest.fn(),
} ) );

const onReset = jest.fn();
const actions = [
	{ type: 'secondary', label: 'Manage in WooCommerce', href: '/wp-admin/edit.php' },
	{ type: 'primary', label: 'Add Plan', mobileIcon: plus, href: '#/new' },
	{ type: 'more', label: 'Reset', onClick: onReset },
];

const renderPage = pageActions =>
	render(
		<Page breadcrumbItems={ [ { label: 'Audience' }, { label: 'Plans' } ] } actions={ pageActions }>
			<div />
		</Page>
	);

afterEach( () => {
	jest.clearAllMocks();
} );

describe( 'Page header actions as data', () => {
	it( 'shows every button and keeps more items in a menu from 782px', () => {
		useViewportMatch.mockReturnValue( true );
		renderPage( actions );

		expect( screen.getByRole( 'link', { name: 'Manage in WooCommerce' } ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'Add Plan' } ) ).toHaveTextContent( 'Add Plan' );

		fireEvent.click( screen.getByRole( 'button', { name: 'More actions' } ) );
		expect( screen.getByRole( 'menuitem', { name: 'Reset' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'menuitem', { name: 'Add Plan' } ) ).not.toBeInTheDocument();
	} );

	it( 'keeps only the primary action out below 782px, as an icon when it has one', () => {
		useViewportMatch.mockReturnValue( false );
		renderPage( actions );

		const primary = screen.getByRole( 'link', { name: 'Add Plan' } );
		expect( primary ).not.toHaveTextContent( 'Add Plan' );
		expect( screen.queryByRole( 'link', { name: 'Manage in WooCommerce' } ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'More actions' } ) );
		const items = screen.getAllByRole( 'menuitem' ).map( item => item.textContent );
		expect( items ).toEqual( [ 'Manage in WooCommerce', 'Reset' ] );

		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Reset' } ) );
		expect( onReset ).toHaveBeenCalledTimes( 1 );
		expect( screen.queryByRole( 'menuitem', { name: 'Reset' } ) ).not.toBeInTheDocument();
	} );

	it( 'shows a primary action without a mobile icon as text, with no menu when nothing else is left', () => {
		useViewportMatch.mockReturnValue( false );
		renderPage( [ { type: 'primary', label: 'Save', onClick: jest.fn() } ] );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toHaveTextContent( 'Save' );
		expect( screen.queryByRole( 'button', { name: 'More actions' } ) ).not.toBeInTheDocument();
	} );

	it( 'still renders actions given as nodes', () => {
		useViewportMatch.mockReturnValue( false );
		renderPage( <button>Add new</button> );

		expect( screen.getByRole( 'button', { name: 'Add new' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'More actions' } ) ).not.toBeInTheDocument();
	} );
} );

describe( 'isActionList', () => {
	it( 'accepts action objects and rejects nodes', () => {
		expect( isActionList( actions ) ).toBe( true );
		expect( isActionList( [] ) ).toBe( false );
		expect( isActionList( [ <button key="a">A</button> ] ) ).toBe( false );
		expect( isActionList( <button>A</button> ) ).toBe( false );
	} );
} );
