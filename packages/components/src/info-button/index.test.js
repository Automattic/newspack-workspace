/**
 * External dependencies.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies.
 */
import { createRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import InfoButton from './';

const DESCRIPTION = 'Number of articles read in the last 30 day period.';

const getTrigger = ( name = 'More information' ) => screen.getByRole( 'button', { name } );

describe( 'InfoButton', () => {
	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'names the trigger briefly rather than with the whole description', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		expect( getTrigger() ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: DESCRIPTION } ) ).not.toBeInTheDocument();
	} );

	it( 'renders nothing at all without a description', () => {
		const { container } = render( <InfoButton /> );
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
		expect( container.firstChild ).toBeNull();
	} );

	it( 'treats an empty description as nothing to explain', () => {
		const { container } = render( <InfoButton description="" /> );
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
		expect( container.firstChild ).toBeNull();
	} );

	it( 'lets a consumer name the trigger for its own context', () => {
		render( <InfoButton description={ DESCRIPTION } triggerLabel="More information about Articles read" /> );
		expect( getTrigger( 'More information about Articles read' ) ).toBeInTheDocument();
	} );

	it( 'leaves the trigger in the tab order', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		expect( getTrigger().tabIndex ).toBeGreaterThanOrEqual( 0 );
	} );

	it( 'keeps the description hidden until the trigger is activated', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		expect( screen.queryByText( DESCRIPTION ) ).not.toBeInTheDocument();
		expect( getTrigger() ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'reveals the description on click, so touch users can reach it', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		fireEvent.click( getTrigger() );
		expect( screen.getByText( DESCRIPTION ) ).toBeInTheDocument();
		expect( getTrigger() ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'reveals the description on hover, once the pointer has rested', () => {
		jest.useFakeTimers();
		render( <InfoButton description={ DESCRIPTION } /> );
		const trigger = getTrigger();

		fireEvent.mouseEnter( trigger );
		fireEvent.mouseMove( trigger );
		expect( screen.queryByText( DESCRIPTION ) ).not.toBeInTheDocument();

		act( () => jest.advanceTimersByTime( 200 ) );

		expect( screen.getByText( DESCRIPTION ) ).toBeInTheDocument();
	} );

	it( 'holds the description open long enough to overshoot the trigger', () => {
		jest.useFakeTimers();
		render( <InfoButton description={ DESCRIPTION } /> );
		const trigger = getTrigger();

		fireEvent.mouseEnter( trigger );
		fireEvent.mouseMove( trigger );
		act( () => jest.advanceTimersByTime( 200 ) );
		expect( screen.getByText( DESCRIPTION ) ).toBeInTheDocument();

		fireEvent.mouseLeave( trigger );
		expect( screen.getByText( DESCRIPTION ) ).toBeInTheDocument();

		act( () => jest.advanceTimersByTime( 200 ) );

		expect( screen.queryByText( DESCRIPTION ) ).not.toBeInTheDocument();
	} );

	it( 'uses a native button, so Enter and Space activate it', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		const trigger = getTrigger();
		expect( trigger.tagName ).toBe( 'BUTTON' );
		expect( trigger ).toHaveAttribute( 'type', 'button' );
	} );

	it( 'closes on Escape and hands focus back to the trigger', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		const trigger = getTrigger();
		trigger.focus();
		fireEvent.click( trigger );
		expect( screen.getByText( DESCRIPTION ) ).toBeInTheDocument();

		fireEvent.keyDown( document.activeElement, { key: 'Escape' } );

		expect( screen.queryByText( DESCRIPTION ) ).not.toBeInTheDocument();
		expect( trigger ).toHaveFocus();
	} );

	it( 'gives the popup an accessible name of its own', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		fireEvent.click( getTrigger() );
		expect( screen.getByRole( 'dialog', { name: 'More information' } ) ).toBeInTheDocument();
	} );

	it( 'describes the popup with the description rather than naming it', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		fireEvent.click( getTrigger() );
		const paragraph = screen.getByText( DESCRIPTION );
		expect( paragraph.id ).toBeTruthy();
		expect( document.querySelector( `[aria-describedby="${ paragraph.id }"]` ) ).toBeInTheDocument();
	} );

	it( 'renders through its own portal and positioner', () => {
		render( <InfoButton description={ DESCRIPTION } /> );
		fireEvent.click( getTrigger() );
		const paragraph = screen.getByText( DESCRIPTION );
		expect( paragraph.closest( '.newspack-info-button__positioner' ) ).toBeInTheDocument();
		expect( paragraph.closest( '.newspack-info-button__portal' ) ).toBeInTheDocument();
	} );

	it( 'forwards unknown props to the trigger', () => {
		render( <InfoButton description={ DESCRIPTION } id="articles-read-info" data-testid="info" /> );
		const trigger = getTrigger();
		expect( trigger ).toHaveAttribute( 'id', 'articles-read-info' );
		expect( trigger ).toHaveAttribute( 'data-testid', 'info' );
	} );

	it( 'keeps its own class alongside a consumer className', () => {
		render( <InfoButton description={ DESCRIPTION } className="custom-class" /> );
		expect( getTrigger() ).toHaveClass( 'newspack-info-button', 'custom-class' );
	} );

	it( 'hands a consumer ref the trigger itself', () => {
		const ref = createRef();
		render( <InfoButton description={ DESCRIPTION } ref={ ref } /> );
		expect( ref.current ).toBe( getTrigger() );
	} );

	it( 'names the trigger and the popup alike when given an aria-label', () => {
		render( <InfoButton description={ DESCRIPTION } aria-label="More information about Articles read" /> );
		fireEvent.click( getTrigger( 'More information about Articles read' ) );
		expect( screen.getByRole( 'dialog', { name: 'More information about Articles read' } ) ).toBeInTheDocument();
	} );
} );
