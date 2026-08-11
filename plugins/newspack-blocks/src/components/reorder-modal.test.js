/**
 * External dependencies
 */
import { createEvent, fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ReorderModal, { moveItem } from './reorder-modal';

describe( 'moveItem', () => {
	it( 'moves an item down', () => {
		expect( moveItem( [ 'a', 'b', 'c' ], 0, 2 ) ).toEqual( [ 'b', 'c', 'a' ] );
	} );

	it( 'moves an item up', () => {
		expect( moveItem( [ 'a', 'b', 'c' ], 2, 0 ) ).toEqual( [ 'c', 'a', 'b' ] );
	} );

	it( 'returns the original array when the indices match', () => {
		const items = [ 'a', 'b' ];
		expect( moveItem( items, 1, 1 ) ).toBe( items );
	} );

	it( 'returns the original array when an index is out of range', () => {
		const items = [ 'a', 'b' ];
		expect( moveItem( items, 0, 5 ) ).toBe( items );
		expect( moveItem( items, -1, 0 ) ).toBe( items );
	} );
} );

const ITEMS = [
	{ value: 11, label: 'Alpha' },
	{ value: 22, label: 'Beta' },
	{ value: 33, label: 'Gamma' },
];

const renderModal = ( props = {} ) => {
	const onSave = jest.fn();
	const onClose = jest.fn();
	render(
		<ReorderModal
			title="Reorder Content"
			ids={ [ 11, 22, 33 ] }
			fetchItems={ () => Promise.resolve( ITEMS ) }
			onSave={ onSave }
			onClose={ onClose }
			{ ...props }
		/>
	);
	return { onSave, onClose };
};

const titles = () => Array.from( document.querySelectorAll( '.newspack-blocks-reorder-modal__title' ) ).map( el => el.textContent );

const overlay = () => document.querySelector( '.components-modal__screen-overlay' );

// jsdom has no `PointerEvent`, and `fireEvent.pointerDown` would fall back to a
// plain `Event` and drop `button`.
const press = ( from, to, button = 0 ) => {
	fireEvent( from, new MouseEvent( 'pointerdown', { bubbles: true, button } ) );
	fireEvent( to, new MouseEvent( 'pointerup', { bubbles: true, button } ) );
};

describe( 'ReorderModal', () => {
	it( 'lists the items in the order the IDs were given', async () => {
		renderModal();
		expect( await screen.findByText( 'Alpha' ) ).toBeInTheDocument();
		expect( titles() ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
	} );

	it( 'falls back to (no title) for IDs the fetch does not resolve', async () => {
		renderModal( { ids: [ 11, 99 ], fetchItems: () => Promise.resolve( [ ITEMS[ 0 ] ] ) } );
		expect( await screen.findByText( 'Alpha' ) ).toBeInTheDocument();
		expect( titles() ).toEqual( [ 'Alpha', '(no title)' ] );
	} );

	it( 'moves an item up with the chevron', async () => {
		renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		expect( titles() ).toEqual( [ 'Alpha', 'Gamma', 'Beta' ] );
	} );

	it( 'moves an item down with the chevron', async () => {
		renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Down: Alpha' ) );
		expect( titles() ).toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );
	} );

	it( 'disables the chevrons at the ends of the list', async () => {
		renderModal();
		expect( await screen.findByLabelText( 'Move Up: Alpha' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.getByLabelText( 'Move Down: Gamma' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.getByLabelText( 'Move Down: Alpha' ) ).not.toHaveAttribute( 'aria-disabled' );
	} );

	it( 'keeps a disabled chevron focusable but inert', async () => {
		renderModal();
		const chevron = await screen.findByLabelText( 'Move Up: Alpha' );
		chevron.focus();
		expect( document.activeElement ).toBe( chevron );
		fireEvent.click( chevron );
		expect( titles() ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
	} );

	it( 'keeps the disabled Save button focusable but inert', async () => {
		const { onSave } = renderModal();
		const save = await screen.findByRole( 'button', { name: 'Save' } );
		save.focus();
		expect( document.activeElement ).toBe( save );
		fireEvent.click( save );
		expect( onSave ).not.toHaveBeenCalled();
	} );

	it( 'keeps the item title in the accessible name and out of the tooltip', async () => {
		renderModal();
		const chevron = await screen.findByLabelText( 'Move Up: Gamma' );
		expect( screen.queryAllByLabelText( 'Move Up' ) ).toHaveLength( 0 );

		chevron.focus();
		const tooltip = await screen.findByRole( 'tooltip' );
		expect( tooltip ).toHaveTextContent( 'Move Up' );
		expect( tooltip ).not.toHaveTextContent( 'Gamma' );
	} );

	it( 'starts the accessible name with the visible label', async () => {
		renderModal();
		const chevron = await screen.findByLabelText( 'Move Up: Gamma' );
		expect( chevron.getAttribute( 'aria-label' ) ).toContain( 'Move Up' );
		expect( screen.getByLabelText( 'Move Down: Alpha' ).getAttribute( 'aria-label' ) ).toContain( 'Move Down' );
	} );

	it( 'keeps focus on the pressed chevron while it stays enabled', async () => {
		renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		expect( document.activeElement ).toBe( screen.getByLabelText( 'Move Up: Gamma' ) );
	} );

	it( 'moves focus to the other chevron when the pressed one disables', async () => {
		renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Beta' ) );
		expect( screen.getByLabelText( 'Move Up: Beta' ) ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( document.activeElement ).toBe( screen.getByLabelText( 'Move Down: Beta' ) );
	} );

	it( 'saves the reordered IDs', async () => {
		const { onSave } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect( onSave ).toHaveBeenCalledWith( [ 11, 33, 22 ] );
	} );

	it( 'discards the new order once the discard is confirmed', async () => {
		const { onSave, onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Discard' } ) );
		expect( onSave ).not.toHaveBeenCalled();
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'disables saving until the order changes', async () => {
		renderModal();
		expect( await screen.findByRole( 'button', { name: 'Save' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( screen.getByLabelText( 'Move Up: Gamma' ) );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).not.toHaveAttribute( 'aria-disabled' );
	} );

	it( 'disables saving again when the order is moved back', async () => {
		renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).not.toHaveAttribute( 'aria-disabled' );
		fireEvent.click( screen.getByLabelText( 'Move Down: Gamma' ) );
		expect( titles() ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'closes without confirming when nothing was reordered', async () => {
		const { onClose } = renderModal();
		fireEvent.click( await screen.findByRole( 'button', { name: 'Cancel' } ) );
		expect( onClose ).toHaveBeenCalled();
		expect( screen.queryByText( 'Discard the new order?' ) ).not.toBeInTheDocument();
	} );

	it( 'asks before discarding and holds the modal open until answered', async () => {
		const { onSave, onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( screen.getByText( 'Discard the new order?' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	// The modal takes Escape over from `Modal`, whose own handler defers the close
	// request behind an exit animation, so the confirmation is up synchronously.
	it( 'asks before discarding when Escape dismisses the modal', async () => {
		const { onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.keyDown( document.activeElement, { key: 'Escape' } );
		expect( screen.getByText( 'Discard the new order?' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	it( 'closes on Escape without confirming when nothing was reordered', async () => {
		const { onClose } = renderModal();
		await screen.findByText( 'Alpha' );
		fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );
		expect( onClose ).toHaveBeenCalled();
		expect( screen.queryByText( 'Discard the new order?' ) ).not.toBeInTheDocument();
	} );

	it( 'asks before discarding when the header close button dismisses the modal', async () => {
		const { onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		expect( screen.getByText( 'Discard the new order?' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	it( 'closes when a press starts and ends on the overlay', async () => {
		const { onClose } = renderModal();
		await screen.findByText( 'Alpha' );
		press( overlay(), overlay() );
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'asks before discarding when an overlay press dismisses a reordered list', async () => {
		const { onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		press( overlay(), overlay() );
		expect( screen.getByText( 'Discard the new order?' ) ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	it( 'ignores a press that starts on a row and ends on the overlay', async () => {
		const { onClose } = renderModal();
		await screen.findByText( 'Alpha' );
		press( document.querySelector( '.newspack-blocks-reorder-modal__item' ), overlay() );
		expect( onClose ).not.toHaveBeenCalled();
		expect( screen.queryByText( 'Discard the new order?' ) ).not.toBeInTheDocument();
	} );

	it( 'ignores a secondary-button press on the overlay', async () => {
		const { onClose } = renderModal();
		await screen.findByText( 'Alpha' );
		press( overlay(), overlay(), 2 );
		expect( onClose ).not.toHaveBeenCalled();
	} );

	it( 'keeps the reordered list when the discard is dismissed', async () => {
		const { onSave, onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Keep editing' } ) );
		expect( screen.queryByText( 'Discard the new order?' ) ).not.toBeInTheDocument();
		expect( titles() ).toEqual( [ 'Alpha', 'Gamma', 'Beta' ] );
		expect( onClose ).not.toHaveBeenCalled();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	it( 'exposes the rows as a list', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );
		expect( screen.getByRole( 'list' ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 3 );
	} );

	it( 'carries the full title on a row whose text is clipped', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );
		expect( document.querySelector( '.newspack-blocks-reorder-modal__title' ) ).toHaveAttribute( 'title', 'Alpha' );
	} );
} );

// jsdom ships no `DataTransfer`, so the drag payload is stubbed.
const dataTransfer = () => ( {
	data: {},
	dropEffect: 'none',
	effectAllowed: 'none',
	setData( type, value ) {
		this.data[ type ] = value;
	},
	getData( type ) {
		return this.data[ type ];
	},
} );

const rows = () => Array.from( document.querySelectorAll( '.newspack-blocks-reorder-modal__item' ) );

describe( 'ReorderModal drag and drop', () => {
	it( 'reorders as a row is dragged over another', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );
		const dt = dataTransfer();
		fireEvent.dragStart( rows()[ 2 ], { dataTransfer: dt } );
		expect( dt.getData( 'text/plain' ) ).toBe( '33' );
		fireEvent.dragOver( rows()[ 0 ], { dataTransfer: dt } );
		expect( titles() ).toEqual( [ 'Gamma', 'Alpha', 'Beta' ] );
	} );

	it( 'keeps the new order when the drag is dropped', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );
		const dt = dataTransfer();
		fireEvent.dragStart( rows()[ 2 ], { dataTransfer: dt } );
		fireEvent.dragOver( rows()[ 0 ], { dataTransfer: dt } );
		dt.dropEffect = 'move';
		fireEvent.dragEnd( rows()[ 0 ], { dataTransfer: dt } );
		expect( titles() ).toEqual( [ 'Gamma', 'Alpha', 'Beta' ] );
	} );

	it( 'puts the order back when the drag is cancelled', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );
		const dt = dataTransfer();
		fireEvent.dragStart( rows()[ 2 ], { dataTransfer: dt } );
		fireEvent.dragOver( rows()[ 0 ], { dataTransfer: dt } );
		expect( titles() ).toEqual( [ 'Gamma', 'Alpha', 'Beta' ] );
		dt.dropEffect = 'none';
		fireEvent.dragEnd( rows()[ 0 ], { dataTransfer: dt } );
		expect( titles() ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
	} );

	// `dragstart` fires at the draggable row even when the gesture began on a
	// chevron inside it, so the guard keys off where the pointer went down.
	it( 'does not start a drag that began on a chevron', async () => {
		renderModal();
		const chevron = await screen.findByLabelText( 'Move Up: Gamma' );
		fireEvent( chevron, new MouseEvent( 'pointerdown', { bubbles: true } ) );

		const dt = dataTransfer();
		fireEvent.dragStart( rows()[ 2 ], { dataTransfer: dt } );
		expect( dt.getData( 'text/plain' ) ).toBeUndefined();

		fireEvent.dragOver( rows()[ 0 ], { dataTransfer: dt } );
		expect( titles() ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
	} );

	it( 'starts a drag that began on the row itself', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );
		fireEvent( rows()[ 2 ], new MouseEvent( 'pointerdown', { bubbles: true } ) );

		const dt = dataTransfer();
		fireEvent.dragStart( rows()[ 2 ], { dataTransfer: dt } );
		expect( dt.getData( 'text/plain' ) ).toBe( '33' );
	} );

	// Rows are the only drop targets, so the body has to accept the drop as well
	// or releasing in the gap between two rows reads as a cancelled drag.
	it( 'accepts a drop anywhere in the modal body', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );
		const body = document.querySelector( '.newspack-blocks-reorder-modal__body' );

		const over = createEvent.dragOver( body, { dataTransfer: dataTransfer() } );
		fireEvent( body, over );
		expect( over.defaultPrevented ).toBe( true );

		const drop = createEvent.drop( body, { dataTransfer: dataTransfer() } );
		fireEvent( body, drop );
		expect( drop.defaultPrevented ).toBe( true );
	} );
} );

describe( 'ReorderModal when the titles cannot be loaded', () => {
	let errorSpy;

	beforeEach( () => {
		errorSpy = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		errorSpy.mockRestore();
	} );

	it( 'says so instead of offering rows nobody can tell apart', async () => {
		const { onClose } = renderModal( { fetchItems: () => Promise.reject( new Error( 'unreachable' ) ) } );
		// The same wording also lands in the live region core announces it through.
		expect(
			await screen.findByText( 'The content could not be loaded, so the order cannot be saved.', {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();

		expect( titles() ).toEqual( [] );
		expect( screen.queryByRole( 'list' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Save' } ) ).not.toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( onClose ).toHaveBeenCalled();
	} );
} );
