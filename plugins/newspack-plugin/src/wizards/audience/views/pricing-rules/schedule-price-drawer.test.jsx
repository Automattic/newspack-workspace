/**
 * External dependencies
 */
import { render, screen, fireEvent, act } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SchedulePriceDrawer from './schedule-price-drawer';

const CALC_TYPES = [
	{ value: 'fixed_price', label: 'Set price to' },
	{ value: 'percent_of_base', label: 'Percentage of regular price' },
];
const USD = { code: 'USD', symbol: '$', decimals: 2 };
const BLANK = { at: '2', calc_type: 'fixed_price', value: '', label: '' };

function renderDrawer( overrides = {} ) {
	const onSave = jest.fn();
	const onClose = jest.fn();
	render(
		<SchedulePriceDrawer
			isOpen
			price={ BLANK }
			isNew
			takenCycles={ [] }
			publicize={ false }
			calcTypes={ CALC_TYPES }
			currency={ USD }
			onSave={ onSave }
			onClose={ onClose }
			{ ...overrides }
		/>
	);
	return { onSave, onClose };
}

const save = () => act( () => void fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) ) );
const type = ( label, value ) => act( () => void fireEvent.change( screen.getByLabelText( label ), { target: { value } } ) );
// The close button composes its name from the title, so this is also the only
// place the header's own wiring is exercised.
const dismiss = () => act( () => void fireEvent.click( screen.getByRole( 'button', { name: 'Close Add Price' } ) ) );

describe( 'the schedule price drawer', () => {
	it( 'names the panel for what it is doing', () => {
		renderDrawer();
		expect( screen.getByText( 'Add Price' ) ).toBeInTheDocument();
	} );

	it( 'titles an existing price as an edit', () => {
		renderDrawer( { isNew: false } );
		expect( screen.getByText( 'Edit Price' ) ).toBeInTheDocument();
	} );

	it( 'refuses a blank value', async () => {
		const { onSave } = renderDrawer();
		await save();
		expect( screen.getByText( 'Enter a value for this price.' ) ).toBeInTheDocument();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	it( 'accepts a typed zero as a deliberate free price', async () => {
		const { onSave } = renderDrawer();
		await type( 'Value ($)', '0' );
		await save();
		expect( onSave ).toHaveBeenCalledWith( expect.objectContaining( { value: '0' } ) );
	} );

	it( 'refuses a cycle below one', async () => {
		const { onSave } = renderDrawer();
		await type( 'From cycle #', '0' );
		await type( 'Value ($)', '5' );
		await save();
		expect( screen.getByText( 'Enter a cycle number of 1 or higher.' ) ).toBeInTheDocument();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	it( 'refuses a cycle that is not a whole number', async () => {
		const { onSave } = renderDrawer();
		await type( 'From cycle #', '1.5' );
		await type( 'Value ($)', '5' );
		await save();
		expect( screen.getByText( 'Enter a whole cycle number.' ) ).toBeInTheDocument();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	// Whole, but past the range the engine can be handed as an integer.
	it( 'refuses a cycle beyond the safe integer range', async () => {
		const { onSave } = renderDrawer( {
			isNew: false,
			price: { at: '1e21', calc_type: 'fixed_price', value: '5', label: '' },
		} );
		await save();
		expect( screen.getByText( 'Enter a whole cycle number.' ) ).toBeInTheDocument();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	it( 'refuses a cycle another price already claims', async () => {
		const { onSave } = renderDrawer( { takenCycles: [ 1, 2 ] } );
		await type( 'Value ($)', '5' );
		await save();
		expect( screen.getByText( 'Cycle 2 already has a price.' ) ).toBeInTheDocument();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	// Schedules written by the previous editor can hold two prices at one cycle.
	it( 'reprices a price that shares its cycle without making it move first', async () => {
		const { onSave } = renderDrawer( {
			isNew: false,
			price: { at: '2', calc_type: 'fixed_price', value: '5', label: '' },
			takenCycles: [ 2 ],
		} );
		await type( 'Value ($)', '7' );
		await save();
		expect( onSave ).toHaveBeenCalledWith( expect.objectContaining( { at: '2', value: '7' } ) );
	} );

	it( 'still refuses to move a price onto a cycle another one claims', async () => {
		const { onSave } = renderDrawer( {
			isNew: false,
			price: { at: '2', calc_type: 'fixed_price', value: '5', label: '' },
			takenCycles: [ 3 ],
		} );
		await type( 'From cycle #', '3' );
		await save();
		expect( screen.getByText( 'Cycle 3 already has a price.' ) ).toBeInTheDocument();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	it( 'lets an edited price keep its own cycle', async () => {
		const { onSave } = renderDrawer( {
			isNew: false,
			price: { at: '2', calc_type: 'fixed_price', value: '5', label: '' },
			takenCycles: [ 1 ],
		} );
		await save();
		expect( onSave ).toHaveBeenCalledWith( expect.objectContaining( { at: '2' } ) );
	} );

	it( 'moves focus to the field it rejected', async () => {
		renderDrawer();
		await save();
		expect( screen.getByLabelText( 'Value ($)' ) ).toHaveFocus();
	} );

	it( 'hides the reader-facing name while pricing details are hidden', () => {
		renderDrawer();
		expect( screen.queryByLabelText( 'Name shown to reader' ) ).not.toBeInTheDocument();
	} );

	it( 'offers the reader-facing name once pricing details are shown', () => {
		renderDrawer( { publicize: true } );
		expect( screen.getByLabelText( 'Name shown to reader' ) ).toBeInTheDocument();
	} );

	it( 'relabels the value field with the unit the calculation implies', async () => {
		renderDrawer();
		await act( async () => {
			fireEvent.change( screen.getByLabelText( 'Calculation' ), { target: { value: 'percent_of_base' } } );
		} );
		expect( screen.getByLabelText( 'Value (%)' ) ).toBeInTheDocument();
	} );

	it( 'lets an untouched panel go', async () => {
		const { onClose } = renderDrawer();
		await dismiss();
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'holds an edited panel open for confirmation rather than closing it', async () => {
		const { onClose } = renderDrawer();
		await type( 'Value ($)', '5' );
		await dismiss();
		expect( screen.getByText( 'You have unsaved changes that will be lost. Discard changes?' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	it( 'offers a way out that does not save', async () => {
		const { onSave, onClose } = renderDrawer();
		await act( () => void fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) ) );
		expect( onSave ).not.toHaveBeenCalled();
		expect( onClose ).toHaveBeenCalled();
	} );

	// `1e999` reaches Number() as Infinity, which JSON.stringify posts as null.
	it( 'refuses a value that is not a finite number', async () => {
		const { onSave } = renderDrawer( {
			isNew: false,
			price: { at: '2', calc_type: 'fixed_price', value: '1e999', label: '' },
		} );
		await save();
		expect( screen.getByText( 'Enter a number.' ) ).toBeInTheDocument();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	it( 'refuses a negative value', async () => {
		const { onSave } = renderDrawer();
		await type( 'Value ($)', '-5' );
		await save();
		expect( screen.getByText( 'Enter a value of 0 or higher.' ) ).toBeInTheDocument();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	// Clearing one rejection must not send focus chasing the other one.
	it( 'leaves focus alone while a rejected field is being corrected', async () => {
		renderDrawer();
		await type( 'From cycle #', '0' );
		await save();
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveFocus();

		await type( 'From cycle #', '3' );

		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveFocus();
		expect( screen.getByText( 'Enter a value for this price.' ) ).toBeInTheDocument();
	} );

	it( 'sends focus to the still-rejected field on the next save', async () => {
		renderDrawer();
		await type( 'From cycle #', '0' );
		await save();
		await type( 'From cycle #', '3' );
		await save();
		expect( screen.getByLabelText( 'Value ($)' ) ).toHaveFocus();
	} );

	it( 'drops a rejection as soon as the field is corrected', async () => {
		renderDrawer();
		await save();
		expect( screen.getByLabelText( 'Value ($)' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		await type( 'Value ($)', '5' );
		expect( screen.getByLabelText( 'Value ($)' ) ).toHaveAttribute( 'aria-invalid', 'false' );
		expect( screen.queryByText( 'Enter a value for this price.' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps showing a calculation the vocabulary no longer offers', () => {
		renderDrawer( {
			isNew: false,
			price: { at: '2', calc_type: 'discount_fixed', value: '5', label: '' },
		} );
		expect( screen.getByLabelText( 'Calculation' ) ).toHaveValue( 'discount_fixed' );
	} );

	it( 'marks the rejected field invalid and leaves the accepted one alone', async () => {
		renderDrawer();
		await save();
		expect( screen.getByLabelText( 'Value ($)' ) ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveAttribute( 'aria-invalid', 'false' );
	} );

	it( 'sends focus to the cycle field when both fields fail', async () => {
		renderDrawer();
		await type( 'From cycle #', '0' );
		await save();
		expect( screen.getByLabelText( 'From cycle #' ) ).toHaveFocus();
	} );

	// The reseed clears errors on both edges, so neither the exit nor the next
	// entrance carries the last rejection.
	it( 'reopens on another price with no trace of the last rejection', async () => {
		const panel = props => (
			<SchedulePriceDrawer
				isOpen={ props.isOpen }
				price={ props.price }
				isNew={ false }
				takenCycles={ [] }
				publicize={ false }
				calcTypes={ CALC_TYPES }
				currency={ USD }
				onSave={ jest.fn() }
				onClose={ jest.fn() }
			/>
		);
		const { rerender } = render( panel( { isOpen: true, price: BLANK } ) );
		await save();
		expect( screen.getByText( 'Enter a value for this price.' ) ).toBeInTheDocument();

		await act( async () => rerender( panel( { isOpen: false, price: BLANK } ) ) );
		expect( screen.queryByText( 'Enter a value for this price.' ) ).not.toBeInTheDocument();

		await act( async () => rerender( panel( { isOpen: true, price: { at: '5', calc_type: 'fixed_price', value: '9', label: '' } } ) ) );

		expect( screen.queryByText( 'Enter a value for this price.' ) ).not.toBeInTheDocument();
		expect( screen.getByLabelText( 'Value ($)' ) ).toHaveAttribute( 'aria-invalid', 'false' );
		expect( screen.getByLabelText( 'Value ($)' ) ).toHaveValue( 9 );
	} );

	it( 'survives a parent re-render that rebuilds the price it was given', async () => {
		const panel = props => (
			<SchedulePriceDrawer
				isOpen
				price={ props.price }
				isNew
				takenCycles={ [] }
				publicize={ false }
				calcTypes={ CALC_TYPES }
				currency={ USD }
				onSave={ jest.fn() }
				onClose={ jest.fn() }
			/>
		);
		const { rerender } = render( panel( { price: { ...BLANK } } ) );
		await type( 'Value ($)', '7' );
		await act( async () => rerender( panel( { price: { ...BLANK } } ) ) );
		expect( screen.getByLabelText( 'Value ($)' ) ).toHaveValue( 7 );
	} );
} );
