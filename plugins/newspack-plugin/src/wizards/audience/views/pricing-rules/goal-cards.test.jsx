/**
 * The goal picker's cards. Rendered against the real Card so the radio semantics
 * are asserted where they land, on the DOM.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent, createEvent } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { isRTL } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import GoalCards from './goal-cards';

jest.mock( '@wordpress/i18n', () => ( {
	...jest.requireActual( '@wordpress/i18n' ),
	isRTL: jest.fn( () => false ),
} ) );

// Selection is owned by the form, so mirror that here.
const Picker = ( { initial = null, disabled = false } ) => {
	const [ selected, setSelected ] = useState( initial );
	return <GoalCards selected={ selected } onSelect={ setSelected } disabled={ disabled } />;
};

const radios = () => screen.getAllByRole( 'radio' );
const checked = () => screen.getByRole( 'radio', { checked: true } );

describe( 'GoalCards', () => {
	beforeEach( () => {
		isRTL.mockReturnValue( false );
	} );

	it( 'renders every goal as a radio in one named group', () => {
		render( <Picker /> );
		expect( screen.getByRole( 'radiogroup', { name: 'Rule goal' } ) ).toBeInTheDocument();
		expect( radios() ).toHaveLength( 5 );
	} );

	it( 'checks exactly one goal', () => {
		render( <Picker initial="winback" /> );
		expect( screen.getAllByRole( 'radio', { checked: true } ) ).toHaveLength( 1 );
		expect( checked() ).toHaveAccessibleName( /^Win-Back/ );
	} );

	it( 'keeps a single tab stop, on the selected goal', () => {
		render( <Picker initial="save" /> );
		const stops = radios().filter( r => r.getAttribute( 'tabindex' ) === '0' );
		expect( stops ).toHaveLength( 1 );
		expect( stops[ 0 ] ).toBe( checked() );
	} );

	it( 'falls back to the first goal for the tab stop when none is selected', () => {
		render( <Picker /> );
		expect( radios()[ 0 ] ).toHaveAttribute( 'tabindex', '0' );
		expect(
			radios()
				.slice( 1 )
				.every( r => r.getAttribute( 'tabindex' ) === '-1' )
		).toBe( true );
	} );

	it( 'moves selection and focus with the arrow keys', () => {
		render( <Picker initial="new_subscriptions" /> );
		const [ first, second ] = radios();
		first.focus();

		fireEvent.keyDown( first, { key: 'ArrowDown' } );
		expect( second ).toHaveFocus();
		expect( second ).toBeChecked();

		fireEvent.keyDown( second, { key: 'ArrowLeft' } );
		expect( radios()[ 0 ] ).toHaveFocus();
		expect( radios()[ 0 ] ).toBeChecked();
	} );

	it( 'wraps around at both ends', () => {
		render( <Picker initial="custom" /> );
		const last = radios()[ 4 ];
		last.focus();

		fireEvent.keyDown( last, { key: 'ArrowRight' } );
		expect( radios()[ 0 ] ).toHaveFocus();
		expect( radios()[ 0 ] ).toBeChecked();

		fireEvent.keyDown( radios()[ 0 ], { key: 'ArrowUp' } );
		expect( radios()[ 4 ] ).toHaveFocus();
		expect( radios()[ 4 ] ).toBeChecked();
	} );

	it( 'follows the layout direction under an RTL locale', () => {
		isRTL.mockReturnValue( true );
		render( <Picker initial="new_subscriptions" /> );
		const [ first, second ] = radios();
		first.focus();

		fireEvent.keyDown( first, { key: 'ArrowLeft' } );
		expect( second ).toHaveFocus();
		expect( second ).toBeChecked();

		fireEvent.keyDown( second, { key: 'ArrowRight' } );
		expect( radios()[ 0 ] ).toHaveFocus();
		expect( radios()[ 0 ] ).toBeChecked();
	} );

	it( 'keeps the vertical arrows pointing the same way under an RTL locale', () => {
		isRTL.mockReturnValue( true );
		render( <Picker initial="new_subscriptions" /> );
		const [ first, second ] = radios();
		first.focus();

		fireEvent.keyDown( first, { key: 'ArrowDown' } );
		expect( second ).toBeChecked();

		fireEvent.keyDown( second, { key: 'ArrowUp' } );
		expect( radios()[ 0 ] ).toBeChecked();
	} );

	it( 'takes the keys it does own away from the browser', () => {
		render( <Picker initial="new_subscriptions" /> );
		const first = radios()[ 0 ];
		first.focus();

		const event = createEvent.keyDown( first, { key: 'ArrowDown' } );
		fireEvent( first, event );

		expect( event.defaultPrevented ).toBe( true );
		expect( checked() ).toBe( radios()[ 1 ] );
	} );

	it( 'leaves keys it does not own to the browser', () => {
		render( <Picker initial="new_subscriptions" /> );
		const first = radios()[ 0 ];
		first.focus();

		const event = createEvent.keyDown( first, { key: 'Tab' } );
		fireEvent( first, event );

		expect( event.defaultPrevented ).toBe( false );
		expect( checked() ).toBe( radios()[ 0 ] );
		expect( first ).toHaveFocus();
	} );

	it( 'names each goal by its title and summary, not its icon', () => {
		render( <Picker /> );
		expect( radios()[ 0 ] ).toHaveAccessibleName( 'New Subscriptions An introduction or stepped offer for first-time subscribers.' );
	} );

	it( 'renders the title as a heading so the card supplies its own typography', () => {
		render( <Picker /> );
		expect( screen.getByRole( 'heading', { name: 'New Subscriptions', level: 3 } ) ).toBeInTheDocument();
	} );

	describe( 'when the goal is fixed', () => {
		it( 'marks every card aria-disabled rather than disabled, so the set stays readable', () => {
			render( <Picker initial="save" disabled /> );
			radios().forEach( radio => {
				expect( radio ).toHaveAttribute( 'aria-disabled', 'true' );
				expect( radio ).not.toBeDisabled();
			} );
		} );

		it( 'still shows which goal was chosen, and keeps it reachable', () => {
			render( <Picker initial="save" disabled /> );
			expect( checked() ).toHaveAccessibleName( /^Save/ );
			expect( checked() ).toHaveAttribute( 'tabindex', '0' );
		} );

		it( 'ignores a click on another goal', () => {
			render( <Picker initial="save" disabled /> );
			fireEvent.click( radios()[ 0 ] );
			expect( checked() ).toHaveAccessibleName( /^Save/ );
		} );

		it( 'ignores the arrow keys', () => {
			render( <Picker initial="save" disabled /> );
			const current = checked();
			current.focus();

			const event = createEvent.keyDown( current, { key: 'ArrowRight' } );
			fireEvent( current, event );

			expect( event.defaultPrevented ).toBe( false );
			expect( checked() ).toBe( current );
		} );
	} );
} );
