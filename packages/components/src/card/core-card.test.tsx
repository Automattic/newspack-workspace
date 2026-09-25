/**
 * External dependencies.
 */
import { fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies.
 */
import CoreCard from './core-card';

// The cast keeps the original crash-on-missing behavior if the header ever fails to render.
const getHeader = ( container: HTMLElement ) => container.querySelector( '.newspack-card--core__header' ) as HTMLElement;

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

	it( 'names the actions menu "More actions" by default', () => {
		render( <CoreCard header="Settings" actions={ [ { label: 'Delete', action: () => {} } ] } /> );
		expect( screen.getByRole( 'button', { name: 'More actions' } ) ).toBeInTheDocument();
	} );

	it( 'names the actions menu with actionsLabel when supplied', () => {
		render( <CoreCard header="Settings" actions={ [ { label: 'Delete', action: () => {} } ] } actionsLabel="Settings actions" /> );
		expect( screen.getByRole( 'button', { name: 'Settings actions' } ) ).toBeInTheDocument();
	} );

	it( 'gives a menu item its ariaLabel as the accessible name', async () => {
		render( <CoreCard header="Settings" actions={ [ { label: 'Delete', ariaLabel: 'Delete: Settings', action: () => {} } ] } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'More actions' } ) );
		expect( await screen.findByRole( 'menuitem', { name: 'Delete: Settings' } ) ).toBeInTheDocument();
	} );

	it( 'renders the header as a non-button when it is also draggable', () => {
		const { container } = render( <CoreCard header="Settings" isDraggable onHeaderClick={ () => {} } /> );
		expect( getHeader( container ).tagName ).not.toBe( 'BUTTON' );
	} );

	it( 'gives the body large padding when size is large', () => {
		const { container } = render( <CoreCard size="large" /> );
		expect( container.querySelector( '.newspack-card--core__is-large' ) ).not.toBeNull();
	} );

	it( 'lets isSmall win over a large size', () => {
		const { container } = render( <CoreCard size="large" isSmall /> );
		expect( container.querySelector( '.newspack-card--core__is-large' ) ).toBeNull();
		expect( container.querySelector( '.newspack-card--core__is-small' ) ).not.toBeNull();
	} );

	it( 'marks the card vertical only when isVertical is passed', () => {
		const { container: plain } = render( <CoreCard header="Settings" /> );
		expect( plain.querySelector( '.newspack-card--core__is-vertical' ) ).toBeNull();

		const { container: vertical } = render( <CoreCard header="Settings" isVertical /> );
		expect( vertical.querySelector( '.newspack-card--core__is-vertical' ) ).not.toBeNull();
	} );
} );
