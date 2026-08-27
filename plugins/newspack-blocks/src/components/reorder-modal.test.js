/**
 * External dependencies
 */
import { createEvent, fireEvent, render, screen } from '@testing-library/react';

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import ReorderModal, { findOverlay, moveItem } from './reorder-modal';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

beforeEach( () => {
	speak.mockClear();
} );

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

const discardPrompt = () => screen.queryByRole( 'dialog', { name: 'Discard the new order?' } );

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

	it( 'names the loading state and announces the list once it arrives', async () => {
		renderModal();
		expect( screen.getByText( 'Loading content…' ) ).toBeInTheDocument();
		await screen.findByText( 'Alpha' );
		expect( screen.queryByText( 'Loading content…' ) ).not.toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith( '3 items ready to reorder.' );
	} );

	it( 'names the row it moved, not just the position', async () => {
		renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		expect( speak ).toHaveBeenCalledWith( 'Gamma moved to position 2 of 3.' );
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
		expect( discardPrompt() ).not.toBeInTheDocument();
	} );

	it( 'asks before discarding and holds the modal open until answered', async () => {
		const { onSave, onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( discardPrompt() ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
		expect( onSave ).not.toHaveBeenCalled();
	} );

	// The modal takes Escape over from `Modal`, whose own handler defers the close
	// request behind an exit animation, so the confirmation is up synchronously.
	it( 'asks before discarding when Escape dismisses the modal', async () => {
		const { onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.keyDown( document.activeElement, { key: 'Escape' } );
		expect( discardPrompt() ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	it( 'closes on Escape without confirming when nothing was reordered', async () => {
		const { onClose } = renderModal();
		await screen.findByText( 'Alpha' );
		fireEvent.keyDown( screen.getByRole( 'dialog' ), { key: 'Escape' } );
		expect( onClose ).toHaveBeenCalled();
		expect( discardPrompt() ).not.toBeInTheDocument();
	} );

	it( 'asks before discarding when the header close button dismisses the modal', async () => {
		const { onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		expect( discardPrompt() ).toBeInTheDocument();
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
		expect( discardPrompt() ).toBeInTheDocument();
		expect( onClose ).not.toHaveBeenCalled();
	} );

	it( 'ignores a press that starts on a row and ends on the overlay', async () => {
		const { onClose } = renderModal();
		await screen.findByText( 'Alpha' );
		press( document.querySelector( '.newspack-blocks-reorder-modal__item' ), overlay() );
		expect( onClose ).not.toHaveBeenCalled();
		expect( discardPrompt() ).not.toBeInTheDocument();
	} );

	it( 'ignores a secondary-button press on the overlay', async () => {
		const { onClose } = renderModal();
		await screen.findByText( 'Alpha' );
		press( overlay(), overlay(), 2 );
		expect( onClose ).not.toHaveBeenCalled();
	} );

	// `ConfirmDialog` announces `contentLabel` as the dialog's name and hides its
	// header, so repeating it in the body would have it read out twice.
	it( 'states the consequence in the discard prompt rather than repeating its name', async () => {
		renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( discardPrompt() ).toHaveTextContent( 'The order you set will be lost.' );
		expect( discardPrompt() ).not.toHaveTextContent( 'Discard the new order?' );
	} );

	it( 'keeps the reordered list when the discard is dismissed', async () => {
		const { onSave, onClose } = renderModal();
		fireEvent.click( await screen.findByLabelText( 'Move Up: Gamma' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Keep editing' } ) );
		expect( discardPrompt() ).not.toBeInTheDocument();
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

	it( 'reorders as a row is dragged down past the rows below it', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );
		const dt = dataTransfer();
		fireEvent.dragStart( rows()[ 0 ], { dataTransfer: dt } );
		fireEvent.dragOver( rows()[ 1 ], { dataTransfer: dt } );
		expect( titles() ).toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );

		// The dragged row now sits under the cursor, so the next `dragover` reaches
		// it rather than the row it swapped with: it has to leave the order alone.
		fireEvent.dragOver( rows()[ 1 ], { dataTransfer: dt } );
		expect( titles() ).toEqual( [ 'Beta', 'Alpha', 'Gamma' ] );

		fireEvent.dragOver( rows()[ 2 ], { dataTransfer: dt } );
		expect( titles() ).toEqual( [ 'Beta', 'Gamma', 'Alpha' ] );
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

	// Rows are the only drop targets, so the dialog has to accept the drop as well:
	// otherwise the gaps between rows, and the frame's own padding, read as a
	// cancelled drag and undo the reorder.
	it.each( [
		[ 'frame', '.components-modal__frame' ],
		[ 'body', '.newspack-blocks-reorder-modal__body' ],
	] )( 'accepts a drop released on the %s', async ( _name, selector ) => {
		renderModal();
		await screen.findByText( 'Alpha' );
		const target = document.querySelector( selector );

		const over = createEvent.dragOver( target, { dataTransfer: dataTransfer() } );
		fireEvent( target, over );
		expect( over.defaultPrevented ).toBe( true );

		const drop = createEvent.drop( target, { dataTransfer: dataTransfer() } );
		fireEvent( target, drop );
		expect( drop.defaultPrevented ).toBe( true );
	} );

	it( 'leaves a drop released on the overlay cancelling the drag', async () => {
		renderModal();
		await screen.findByText( 'Alpha' );

		const over = createEvent.dragOver( overlay(), { dataTransfer: dataTransfer() } );
		fireEvent( overlay(), over );
		expect( over.defaultPrevented ).toBe( false );
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

describe( 'findOverlay', () => {
	const markup = html => {
		const root = document.createElement( 'div' );
		root.innerHTML = html;
		return root;
	};

	it( 'takes the node itself when it is the overlay', () => {
		const overlayNode = markup( '<div class="components-modal__screen-overlay"></div>' ).firstChild;
		expect( findOverlay( overlayNode ) ).toBe( overlayNode );
	} );

	it( 'finds the overlay from a node inside it', () => {
		const root = markup( '<div class="components-modal__screen-overlay"><div class="components-modal__frame"></div></div>' );
		expect( findOverlay( root.querySelector( '.components-modal__frame' ) ) ).toBe( root.firstChild );
	} );

	it( 'finds the overlay from a node wrapping it', () => {
		const root = markup( '<div class="components-modal__screen-overlay"></div>' );
		expect( findOverlay( root ) ).toBe( root.firstChild );
	} );

	it( 'reports nothing rather than guessing', () => {
		expect( findOverlay( null ) ).toBe( null );
	} );
} );
