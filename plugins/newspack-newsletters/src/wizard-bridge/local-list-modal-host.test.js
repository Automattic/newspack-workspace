import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import LocalListModalHost from './local-list-modal-host';
import { EVENTS } from './events';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const dispatchEvent = ( name, detail ) => {
	document.dispatchEvent( new CustomEvent( name, { detail } ) );
};

describe( 'LocalListModalHost', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockImplementation( opts => {
			if ( opts.path === '/newspack-newsletters/v1/lists/audiences' ) {
				return Promise.resolve( { audiences: [], audience_label: 'List', help_before_save: '' } );
			}
			return Promise.resolve( { db_id: 99, title: 'Created' } );
		} );
	} );

	it( 'mounts LocalListModal in add mode when OPEN_MODAL fires with mode=add', async () => {
		render( <LocalListModalHost /> );
		dispatchEvent( EVENTS.OPEN_MODAL, { mode: 'add' } );
		await waitFor( () => expect( screen.getByText( /Add new local list/ ) ).toBeInTheDocument() );
	} );

	it( 'mounts LocalListModal in edit mode pre-populated when OPEN_MODAL fires with mode=edit + list', async () => {
		render( <LocalListModalHost /> );
		const list = { db_id: 5, title: 'Existing', description: '', audience: '', type: 'local' };
		dispatchEvent( EVENTS.OPEN_MODAL, { mode: 'edit', list } );
		await waitFor( () => expect( screen.getByDisplayValue( 'Existing' ) ).toBeInTheDocument() );
	} );

	it( 'fires LOCAL_LIST_SAVED with detail after a successful save', async () => {
		render( <LocalListModalHost /> );
		const savedListener = jest.fn();
		document.addEventListener( EVENTS.LOCAL_LIST_SAVED, savedListener );
		dispatchEvent( EVENTS.OPEN_MODAL, { mode: 'add' } );
		await waitFor( () => expect( screen.getByLabelText( /List title/ ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByLabelText( /List title/ ), { target: { value: 'Created' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /^Add list$/ } ) );
		await waitFor( () => expect( savedListener ).toHaveBeenCalled() );
		const detail = savedListener.mock.calls[ 0 ][ 0 ].detail;
		expect( detail ).toEqual(
			expect.objectContaining( { listId: 99, mode: 'add', kind: 'local', list: expect.objectContaining( { db_id: 99 } ) } )
		);
		document.removeEventListener( EVENTS.LOCAL_LIST_SAVED, savedListener );
	} );

	it( 'mounts LocalListModal in ESP mode when OPEN_MODAL fires with kind=esp', async () => {
		render( <LocalListModalHost /> );
		const list = { db_id: 22, title: 'Mailchimp list', description: '', type: 'remote' };
		dispatchEvent( EVENTS.OPEN_MODAL, { mode: 'edit', kind: 'esp', list } );
		await waitFor( () => expect( screen.getByText( /Edit subscription list/ ) ).toBeInTheDocument() );
		// Audiences endpoint must not be hit in ESP mode.
		const audiencesCall = apiFetch.mock.calls.find( call => call[ 0 ]?.path === '/newspack-newsletters/v1/lists/audiences' );
		expect( audiencesCall ).toBeUndefined();
	} );

	it( 'forwards kind on LOCAL_LIST_SAVED after an ESP-mode save', async () => {
		apiFetch.mockReset();
		apiFetch.mockResolvedValue( { db_id: 22, title: 'Mailchimp list (renamed)' } );
		render( <LocalListModalHost /> );
		const savedListener = jest.fn();
		document.addEventListener( EVENTS.LOCAL_LIST_SAVED, savedListener );
		dispatchEvent( EVENTS.OPEN_MODAL, {
			mode: 'edit',
			kind: 'esp',
			list: { db_id: 22, title: 'Mailchimp list', description: '', type: 'remote' },
		} );
		await waitFor( () => expect( screen.getByText( /Edit subscription list/ ) ).toBeInTheDocument() );
		fireEvent.click( screen.getByRole( 'button', { name: /^Save changes$/ } ) );
		await waitFor( () => expect( savedListener ).toHaveBeenCalled() );
		expect( savedListener.mock.calls[ 0 ][ 0 ].detail ).toEqual( expect.objectContaining( { listId: 22, kind: 'esp', mode: 'edit' } ) );
		document.removeEventListener( EVENTS.LOCAL_LIST_SAVED, savedListener );
	} );

	it( 'mounts LocalListDeleteModal when OPEN_CONFIRM_DELETE fires', async () => {
		render( <LocalListModalHost /> );
		dispatchEvent( EVENTS.OPEN_CONFIRM_DELETE, { list: { db_id: 7, title: 'Doomed' } } );
		await waitFor( () => expect( screen.getByText( /Delete the local list "Doomed"/ ) ).toBeInTheDocument() );
	} );

	it( 'sets readiness flag and dispatches bridge-mounted after document listeners are installed', async () => {
		// A consumer registers for bridge-mounted and reacts by synchronously
		// dispatching open-local-list-modal. The host must already be
		// listening for OPEN_MODAL when the consumer's reaction fires —
		// otherwise the open event is lost.
		delete window.newspackNewslettersBridgeReady;
		const ready = jest.fn( () => {
			document.dispatchEvent( new CustomEvent( EVENTS.OPEN_MODAL, { detail: { mode: 'add' } } ) );
		} );
		document.addEventListener( EVENTS.BRIDGE_MOUNTED, ready );
		render( <LocalListModalHost /> );
		await waitFor( () => expect( ready ).toHaveBeenCalled() );
		expect( window.newspackNewslettersBridgeReady ).toBe( true );
		await waitFor( () => expect( screen.getByText( /Add new local list/ ) ).toBeInTheDocument() );
		document.removeEventListener( EVENTS.BRIDGE_MOUNTED, ready );
	} );

	it( 'fires LOCAL_LIST_DELETED with detail after a successful DELETE', async () => {
		render( <LocalListModalHost /> );
		const deletedListener = jest.fn();
		document.addEventListener( EVENTS.LOCAL_LIST_DELETED, deletedListener );
		dispatchEvent( EVENTS.OPEN_CONFIRM_DELETE, { list: { db_id: 7, title: 'Doomed' } } );
		await waitFor( () => expect( screen.getByRole( 'button', { name: /^Delete list$/ } ) ).toBeInTheDocument() );
		fireEvent.click( screen.getByRole( 'button', { name: /^Delete list$/ } ) );
		await waitFor( () => expect( deletedListener ).toHaveBeenCalled() );
		expect( deletedListener.mock.calls[ 0 ][ 0 ].detail ).toEqual( { listId: 7 } );
		document.removeEventListener( EVENTS.LOCAL_LIST_DELETED, deletedListener );
	} );
} );
