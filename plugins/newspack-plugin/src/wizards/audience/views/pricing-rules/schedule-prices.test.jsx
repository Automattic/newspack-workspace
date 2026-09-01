/**
 * External dependencies
 */
import { render, screen, fireEvent, act, within, waitForElementToBeRemoved } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { dispatch, select } from '@wordpress/data';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import SchedulePrices from './schedule-prices';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';

const CALC_TYPES = [
	{ value: 'fixed_price', label: 'Set price to' },
	{ value: 'percent_of_base', label: 'Percentage of regular price' },
];
const USD = { code: 'USD', symbol: '$', decimals: 2 };

const LADDER = [
	{ at: '1', calc_type: 'fixed_price', value: '8', label: 'Welcome back offer' },
	{ at: '2', calc_type: 'percent_of_base', value: '80', label: 'Second quarter' },
	{ at: '7', calc_type: 'discount_fixed', value: '2', label: '' },
];

function renderPrices( overrides = {} ) {
	const onChange = jest.fn();
	render(
		<SchedulePrices steps={ LADDER } onChange={ onChange } publicize={ false } calcTypes={ CALC_TYPES } currency={ USD } { ...overrides } />
	);
	return { onChange };
}

// The rule form owns the list, so the component only ever sees what its own
// onChange handed back. A jest.fn() freezes the prop and hides that round trip.
const Schedule = ( { initial = LADDER, ...props } ) => {
	const [ steps, setSteps ] = useState( initial );
	return <SchedulePrices steps={ steps } onChange={ setSteps } publicize={ false } calcTypes={ CALC_TYPES } currency={ USD } { ...props } />;
};

const type = ( label, value ) => act( async () => void fireEvent.change( screen.getByLabelText( label ), { target: { value } } ) );

const clickButton = name => act( async () => void fireEvent.click( screen.getByRole( 'button', { name } ) ) );

// The panel stays mounted through its exit, and the modal hides the rest of the
// page from the accessibility tree meanwhile, so the table only reads once it goes.
const waitForPanelToClose = () => waitForElementToBeRemoved( () => screen.queryByRole( 'dialog' ) );

const bodyRow = index => screen.getAllByRole( 'row' )[ index + 1 ];

const cell = ( row, index ) => within( bodyRow( row ) ).getAllByRole( 'cell' )[ index ];

// The title cell's only control; the row also carries the inline Edit and Remove.
const cycleButton = index => within( cell( index, 0 ) ).getByRole( 'button' );
const clickCycle = index => act( () => void fireEvent.click( cycleButton( index ) ) );

// Every action is primary, so both render as row buttons and there is no kebab.
const clickRowAction = ( index, name ) => act( () => void fireEvent.click( within( bodyRow( index ) ).getByRole( 'button', { name } ) ) );

// The confirm button shares its name with the row actions, so scope to the dialog.
const confirmRemoval = async () => {
	const dialog = await screen.findByRole( 'dialog', { name: 'Remove Price' } );
	await act( async () => {
		fireEvent.click( within( dialog ).getByRole( 'button', { name: 'Remove' } ) );
	} );
};

const removePrice = async index => {
	await clickRowAction( index, 'Remove' );
	await confirmRemoval();
};

// The spoken form, not the visible one: identifying a row is what these assert,
// and the display string carries an arrow the accessible name spells out.
const cycleCells = () =>
	screen
		.getAllByRole( 'row' )
		.slice( 1 )
		.map( ( _, i ) => cycleButton( i ).getAttribute( 'aria-label' ) );

describe( 'the schedule prices table', () => {
	it( 'runs each price to the cycle before the next one starts', () => {
		renderPrices();
		expect( cycleCells() ).toEqual( [ '1', '2 to 6', '7 onward' ] );
		// The arrow is what shows; the words above are what a screen reader gets.
		expect( screen.getByText( '2 → 6' ) ).toBeInTheDocument();
	} );

	it( 'reads each price without naming its calculation', () => {
		renderPrices();
		expect( screen.getByText( '$8.00' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Pay 80%' ) ).toBeInTheDocument();
		expect( screen.getByText( '$2.00 off' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Percentage of regular price' ) ).not.toBeInTheDocument();
	} );

	it( 'hides the reader-facing name while pricing details are hidden', () => {
		renderPrices();
		expect( screen.queryByText( 'Welcome back offer' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the reader-facing name once pricing details are shown', () => {
		renderPrices( { publicize: true } );
		expect( screen.getByText( 'Welcome back offer' ) ).toBeInTheDocument();
	} );

	it( 'invites a first price when the schedule is empty', () => {
		renderPrices( { steps: [] } );
		expect( screen.getByRole( 'heading', { level: 3, name: 'No prices yet' } ) ).toBeInTheDocument();
		expect( screen.getByText( /Add the first one to build the schedule/ ) ).toBeInTheDocument();
		// The card title and header action give way to the empty state and its
		// single centered button.
		expect( screen.queryByRole( 'heading', { name: 'Price Schedule' } ) ).not.toBeInTheDocument();
		expect( screen.getAllByRole( 'button', { name: 'Add Price' } ) ).toHaveLength( 1 );
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	it( 'opens the drawer on the cycle after the last price', async () => {
		renderPrices();
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Add Price' } ) );
		} );
		// Not getByText( 'Add Price' ): the button and the drawer title both match.
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveValue( 8 );
	} );

	it( 'starts an empty schedule at cycle one', async () => {
		renderPrices( { steps: [] } );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Add Price' } ) );
		} );
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveValue( 1 );
	} );

	it( 'keeps the list in cycle order when a price is added out of order', async () => {
		const { onChange } = renderPrices();
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Add Price' } ) );
		} );
		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'From cycle #' ), { target: { value: '4' } } );
		} );
		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Value ($)' ), { target: { value: '6' } } );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		} );
		expect( onChange ).toHaveBeenCalledWith( [
			expect.objectContaining( { at: '1' } ),
			expect.objectContaining( { at: '2' } ),
			expect.objectContaining( { at: '4' } ),
			expect.objectContaining( { at: '7' } ),
		] );
	} );

	it( 'edits a price without its own cycle counting as taken', async () => {
		const { onChange } = renderPrices();
		await clickCycle( 1 );
		expect( screen.getByText( 'Edit Price' ) ).toBeInTheDocument();
		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Value (%)' ), { target: { value: '70' } } );
		} );
		await act( async () => {
			fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		} );
		expect( onChange ).toHaveBeenCalledWith( [
			expect.objectContaining( { at: '1' } ),
			expect.objectContaining( { at: '2', value: '70' } ),
			expect.objectContaining( { at: '7' } ),
		] );
	} );

	it( 'ranges the cycles however the saved prices are ordered', () => {
		renderPrices( { steps: [ LADDER[ 2 ], LADDER[ 0 ], LADDER[ 1 ] ] } );
		expect( cycleCells() ).toEqual( [ '1', '2 to 6', '7 onward' ] );
	} );

	it( 'edits the price a row shows, not the one the saved order lists there', async () => {
		renderPrices( { steps: [ LADDER[ 2 ], LADDER[ 0 ], LADDER[ 1 ] ] } );
		await clickCycle( 1 );
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveValue( 2 );
		expect( screen.getByLabelText( 'Value (%)' ) ).toHaveValue( 80 );
	} );

	it( 'keeps a moved price addressable by the row that shows it', async () => {
		render( <Schedule /> );
		await clickCycle( 1 );
		await type( 'From cycle #', '9' );
		await clickButton( 'Save' );
		await waitForPanelToClose();
		expect( cycleCells() ).toEqual( [ '1 to 6', '7 to 8', '9 onward' ] );
		await clickCycle( 1 );
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveValue( 7 );
	} );

	it( 'frees a cycle for reuse once its price is removed', async () => {
		render( <Schedule /> );
		await removePrice( 1 );
		await clickButton( 'Add Price' );
		await type( 'From cycle #', '2' );
		await type( 'Value ($)', '5' );
		await clickButton( 'Save' );
		await waitForPanelToClose();
		expect( cycleCells() ).toEqual( [ '1', '2 to 6', '7 onward' ] );
	} );

	it( 'keeps every row as the list grows', async () => {
		render( <Schedule /> );
		await clickButton( 'Add Price' );
		await type( 'Value ($)', '5' );
		await clickButton( 'Save' );
		await waitForPanelToClose();
		expect( cycleCells() ).toEqual( [ '1', '2 to 6', '7', '8 onward' ] );
	} );

	it( 'shows an Edit button in every row', () => {
		renderPrices();
		expect( screen.getAllByRole( 'button', { name: 'Edit' } ) ).toHaveLength( 3 );
	} );

	it( 'edits a price from its row button', async () => {
		renderPrices();
		await clickRowAction( 1, 'Edit' );
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveValue( 2 );
	} );

	it( 'removes a price once its dialog is confirmed', async () => {
		const { onChange } = renderPrices();
		await removePrice( 1 );
		expect( onChange ).toHaveBeenCalledWith( [ expect.objectContaining( { at: '1' } ), expect.objectContaining( { at: '7' } ) ] );
	} );

	it( 'confirms the removal in a snackbar', async () => {
		// The store outlives each test, so a stale notice would satisfy the assertion.
		dispatch( WIZARD_STORE_NAMESPACE ).resetNotices();
		renderPrices();
		await removePrice( 1 );
		const notice = select( WIZARD_STORE_NAMESPACE )
			.getNotices()
			.find( n => 'pricing-rule-price-removed' === n.id );
		expect( notice ).toMatchObject( { type: 'success', message: 'Price removed.' } );
	} );

	it( 'keeps the price when the removal is cancelled', async () => {
		const { onChange } = renderPrices();
		await clickRowAction( 1, 'Remove' );
		const dialog = await screen.findByRole( 'dialog', { name: 'Remove Price' } );
		// The dialog's close icon shares the accessible name of the text Cancel button.
		const cancel = within( dialog )
			.getAllByRole( 'button', { name: 'Cancel' } )
			.find( button => 'Cancel' === button.textContent.trim() );
		await act( async () => {
			fireEvent.click( cancel );
		} );
		expect( onChange ).not.toHaveBeenCalled();
	} );

	// The Remove button that triggered it unmounts with its row, so focus would
	// otherwise fall to the body.
	it( 'keeps focus in the form after a price is removed', async () => {
		render( <Schedule /> );
		await removePrice( 1 );
		expect( screen.getByRole( 'button', { name: 'Add Price' } ) ).toHaveFocus();
	} );

	// The empty-state button that opened the drawer unmounts as the table appears.
	it( 'parks focus on Add Price after the first price is added', async () => {
		render( <Schedule initial={ [] } /> );
		await clickButton( 'Add Price' );
		await type( 'Value ($)', '5' );
		await clickButton( 'Save' );
		await waitForPanelToClose();
		expect( screen.getByRole( 'button', { name: 'Add Price' } ) ).toHaveFocus();
	} );

	// Rows are keyed by position, so without a claim the drawer would return focus
	// to the row that now holds a different price.
	it( 'follows the edited price to its new row when the edit reorders the schedule', async () => {
		render( <Schedule /> );
		await clickRowAction( 0, 'Edit' );
		await type( 'From cycle #', '9' );
		await clickButton( 'Save' );
		await waitForPanelToClose();
		expect( cycleCells() ).toEqual( [ '2 to 6', '7 to 8', '9 onward' ] );
		expect( cycleButton( 2 ) ).toHaveFocus();
	} );

	it( 'names a calculation it has no wording for rather than pricing it', () => {
		renderPrices( {
			steps: [ { at: '1', calc_type: 'discount_percent', value: '20', label: '' } ],
			calcTypes: [ ...CALC_TYPES, { value: 'discount_percent', label: 'Percentage off' } ],
		} );
		expect( screen.getByText( 'Percentage off: 20' ) ).toBeInTheDocument();
	} );

	it( 'ignores a click on a non-interactive part of a row', async () => {
		renderPrices();
		await act( async () => {
			fireEvent.click( cell( 1, 1 ) );
		} );
		expect( screen.queryByLabelText( 'From cycle #' ) ).not.toBeInTheDocument();
	} );

	it( 'reaches a price from the keyboard through its Cycles control', async () => {
		renderPrices();
		const control = cycleButton( 1 );
		expect( control ).toHaveAccessibleName( '2 to 6' );
		control.focus();
		expect( control ).toHaveFocus();
		await clickCycle( 1 );
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveValue( 2 );
	} );

	it( 'counts a cycle written with a decimal as the cycle it resolves to', async () => {
		render( <Schedule initial={ [ LADDER[ 0 ], { ...LADDER[ 1 ], at: '2.0' }, LADDER[ 2 ] ] } /> );
		await clickButton( 'Add Price' );
		await type( 'From cycle #', '2' );
		await type( 'Value ($)', '5' );
		await clickButton( 'Save' );
		expect( screen.getByText( 'Cycle 2 already has a price.' ) ).toBeInTheDocument();
	} );
} );
