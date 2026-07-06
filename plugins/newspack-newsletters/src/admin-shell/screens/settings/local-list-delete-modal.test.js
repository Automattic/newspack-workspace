import { fireEvent, render, screen } from '@testing-library/react';
import LocalListDeleteModal from './local-list-delete-modal';

describe( 'LocalListDeleteModal', () => {
	const list = { db_id: 7, title: 'Weekly Roundup' };

	it( 'renders the list title in the confirmation copy', () => {
		render( <LocalListDeleteModal list={ list } onConfirm={ jest.fn() } onCancel={ jest.fn() } isBusy={ false } /> );
		expect( screen.getByText( /Weekly Roundup/ ) ).toBeInTheDocument();
	} );

	it( 'calls onCancel when Cancel is clicked', () => {
		const onCancel = jest.fn();
		render( <LocalListDeleteModal list={ list } onConfirm={ jest.fn() } onCancel={ onCancel } isBusy={ false } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^Cancel$/ } ) );
		expect( onCancel ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'calls onConfirm when Delete is clicked', () => {
		const onConfirm = jest.fn();
		render( <LocalListDeleteModal list={ list } onConfirm={ onConfirm } onCancel={ jest.fn() } isBusy={ false } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^Delete list$/ } ) );
		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'disables Cancel + Delete and busies Delete when isBusy is true', () => {
		render( <LocalListDeleteModal list={ list } onConfirm={ jest.fn() } onCancel={ jest.fn() } isBusy={ true } /> );
		expect( screen.getByRole( 'button', { name: /^Cancel$/ } ) ).toBeDisabled();
		expect( screen.getByRole( 'button', { name: /^Delete list$/ } ) ).toBeDisabled();
	} );
} );
