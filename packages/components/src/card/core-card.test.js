/**
 * External dependencies.
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import CoreCard from './core-card';

const getHeader = container => container.querySelector( '.newspack-card--core__header' );

describe( 'CoreCard', () => {
	it( 'renders the header as a <button> when onHeaderClick is supplied and it has no interactive children', () => {
		const { container } = render( <CoreCard header="Settings" onHeaderClick={ () => {} } /> );
		expect( getHeader( container ).tagName ).toBe( 'BUTTON' );
	} );

	it( 'renders the header as a non-button when it also has a toggle', () => {
		const { container } = render( <CoreCard header="Settings" actionType="toggle" onHeaderClick={ () => {} } /> );
		expect( getHeader( container ).tagName ).not.toBe( 'BUTTON' );
	} );

	it( 'renders the header as a non-button when it also has a header action', () => {
		const { container } = render(
			<CoreCard header="Settings" headerAction={ { label: 'Edit', onClick: () => {} } } onHeaderClick={ () => {} } />
		);
		expect( getHeader( container ).tagName ).not.toBe( 'BUTTON' );
	} );

	it( 'renders the header as a non-button when it also has an actions menu', () => {
		const { container } = render(
			<CoreCard header="Settings" actions={ [ { label: 'Delete', action: () => {} } ] } onHeaderClick={ () => {} } />
		);
		expect( getHeader( container ).tagName ).not.toBe( 'BUTTON' );
	} );

	it( 'renders the header as a non-button when it is also draggable', () => {
		const { container } = render( <CoreCard header="Settings" isDraggable onHeaderClick={ () => {} } /> );
		expect( getHeader( container ).tagName ).not.toBe( 'BUTTON' );
	} );
} );
