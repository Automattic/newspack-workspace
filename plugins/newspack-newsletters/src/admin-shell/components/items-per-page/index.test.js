/**
 * Guards the DataViews coupling: this control portals into markup owned
 * by `@wordpress/dataviews` (`.dataviews-view-config`, placed before
 * `.dataviews-field-control`). A package bump that renames either class
 * would otherwise silently drop the control from the popover.
 */

import { act, render, screen, waitFor } from '@testing-library/react';

import ItemsPerPage from './index';
import { PER_PAGE_ALL } from '../../utils/per-page';

const openPopover = () => {
	const popover = document.createElement( 'div' );
	popover.className = 'dataviews-view-config';
	popover.innerHTML = '<div class="dataviews-field-control"></div>';
	document.body.appendChild( popover );
	return popover;
};

describe( 'ItemsPerPage', () => {
	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'renders nothing until the View options popover opens', () => {
		render( <ItemsPerPage value={ 20 } onChange={ jest.fn() } /> );
		expect( screen.queryByText( 'Items per page' ) ).toBeNull();
	} );

	it( 'portals into the popover, above the Properties block', async () => {
		render( <ItemsPerPage value={ 20 } onChange={ jest.fn() } /> );
		const popover = await act( async () => openPopover() );

		await waitFor( () => expect( popover.querySelector( '.newspack-newsletters-items-per-page' ) ).not.toBeNull() );
		const container = popover.querySelector( '.newspack-newsletters-items-per-page' );
		expect( container.nextElementSibling ).toBe( popover.querySelector( '.dataviews-field-control' ) );
		expect( screen.getByText( 'Items per page' ) ).toBeInTheDocument();
	} );

	it( 'labels the All sentinel rather than showing -1', async () => {
		render( <ItemsPerPage value={ PER_PAGE_ALL } onChange={ jest.fn() } /> );
		await act( async () => openPopover() );

		await waitFor( () => expect( screen.getByRole( 'radio', { name: 'All' } ) ).toBeChecked() );
		expect( screen.queryByRole( 'radio', { name: '-1' } ) ).toBeNull();
		[ '10', '20', '50', '100' ].forEach( label => expect( screen.getByRole( 'radio', { name: label } ) ).toBeInTheDocument() );
	} );

	it( 'renders no loading indicator of its own', () => {
		const { container } = render( <ItemsPerPage value={ PER_PAGE_ALL } onChange={ jest.fn() } /> );
		expect( container ).toBeEmptyDOMElement();
		expect( document.querySelector( '.newspack-newsletters-fetch-all-progress' ) ).toBeNull();
	} );
} );
