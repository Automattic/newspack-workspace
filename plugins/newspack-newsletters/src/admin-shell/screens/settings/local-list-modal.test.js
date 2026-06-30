import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { dispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import LocalListModal from './local-list-modal';
import * as extensions from '../../../wizard-bridge/extensions';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../../wizard-bridge/extensions', () => ( {
	getLocalListModalExtensions: jest.fn( () => [] ),
} ) );

describe( 'LocalListModal', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		extensions.getLocalListModalExtensions.mockReturnValue( [] );
	} );

	it( 'passes the saved list and mode to onSaved on create', async () => {
		const saved = { db_id: 42, title: 'New list', description: '', type: 'local' };
		apiFetch.mockImplementation( opts => {
			if ( opts.path === '/newspack-newsletters/v1/lists/audiences' ) {
				return Promise.resolve( { audiences: [], audience_label: 'List', help_before_save: '' } );
			}
			return Promise.resolve( saved );
		} );
		const onSaved = jest.fn();
		const onClose = jest.fn();
		render( <LocalListModal list={ null } onClose={ onClose } onSaved={ onSaved } /> );
		await waitFor( () => expect( screen.getByLabelText( /List title/ ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByLabelText( /List title/ ), { target: { value: 'New list' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /^Add list$/ } ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( onSaved ).toHaveBeenCalledWith( { list: saved, mode: 'add', kind: 'local' } );
	} );

	it( 'passes the saved list and mode to onSaved on edit', async () => {
		const list = { db_id: 9, title: 'Existing', description: '', audience: '', type: 'local' };
		const saved = { ...list, title: 'Renamed' };
		apiFetch.mockImplementation( opts => {
			if ( opts.path === '/newspack-newsletters/v1/lists/audiences' ) {
				return Promise.resolve( { audiences: [], audience_label: 'List', help_before_save: '' } );
			}
			return Promise.resolve( saved );
		} );
		const onSaved = jest.fn();
		render( <LocalListModal list={ list } onClose={ jest.fn() } onSaved={ onSaved } /> );
		await waitFor( () => expect( screen.getByLabelText( /List title/ ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByLabelText( /List title/ ), { target: { value: 'Renamed' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /^Save changes$/ } ) );
		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( onSaved ).toHaveBeenCalledWith( { list: saved, mode: 'edit', kind: 'local' } );
	} );
} );

describe( 'LocalListModal — ESP mode', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		extensions.getLocalListModalExtensions.mockReturnValue( [] );
	} );

	it( 'submits to PATCH /lists/{db_id} with title + description (no audience)', async () => {
		const list = { db_id: 17, title: 'Newsletter A', description: 'old desc', type: 'remote' };
		const saved = { ...list, title: 'Newsletter A — renamed', description: 'fresh desc' };
		apiFetch.mockResolvedValue( saved );

		const onSaved = jest.fn();
		const onClose = jest.fn();
		render( <LocalListModal list={ list } kind="esp" onClose={ onClose } onSaved={ onSaved } /> );

		expect( screen.getByText( /Edit subscription list/ ) ).toBeInTheDocument();

		fireEvent.change( screen.getByLabelText( /List title/ ), { target: { value: 'Newsletter A — renamed' } } );
		fireEvent.change( screen.getByLabelText( /List description/ ), { target: { value: 'fresh desc' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /^Save changes$/ } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/newspack-newsletters/v1/lists/17',
			method: 'PATCH',
			data: { title: 'Newsletter A — renamed', description: 'fresh desc' },
		} );
		expect( onSaved ).toHaveBeenCalledWith( { list: saved, mode: 'edit', kind: 'esp' } );
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'never fetches the audiences endpoint in ESP mode', async () => {
		apiFetch.mockResolvedValue( { db_id: 17, title: 'X' } );
		render( <LocalListModal list={ { db_id: 17, title: 'X', description: '' } } kind="esp" onClose={ jest.fn() } onSaved={ jest.fn() } /> );
		await waitFor( () => expect( screen.getByText( /Edit subscription list/ ) ).toBeInTheDocument() );
		const audiencesCall = apiFetch.mock.calls.find( call => call[ 0 ]?.path === '/newspack-newsletters/v1/lists/audiences' );
		expect( audiencesCall ).toBeUndefined();
	} );

	it( 'queries the registry with kind=esp so local-only extensions are filtered out', async () => {
		apiFetch.mockResolvedValue( { db_id: 17, title: 'X' } );
		render( <LocalListModal list={ { db_id: 17, title: 'X', description: '' } } kind="esp" onClose={ jest.fn() } onSaved={ jest.fn() } /> );
		await waitFor( () => expect( screen.getByText( /Edit subscription list/ ) ).toBeInTheDocument() );
		expect( extensions.getLocalListModalExtensions ).toHaveBeenCalledWith( 'esp' );
	} );

	it( 'surfaces the ESP-specific fallback error when the PATCH fails', async () => {
		apiFetch.mockRejectedValue( new Error( '' ) );
		render( <LocalListModal list={ { db_id: 17, title: 'X', description: '' } } kind="esp" onClose={ jest.fn() } onSaved={ jest.fn() } /> );
		fireEvent.click( screen.getByRole( 'button', { name: /^Save changes$/ } ) );
		await waitFor( () =>
			expect(
				// Scope to the notice — wp's a11y-speak live region mirrors the same text into another node.
				document.querySelector( '.components-notice__content' )
			).toHaveTextContent( /Could not update subscription list/ )
		);
	} );
} );

describe( 'LocalListModal — extensions', () => {
	beforeEach( () => {
		apiFetch.mockReset();
		apiFetch.mockImplementation( opts => {
			if ( opts.path === '/newspack-newsletters/v1/lists/audiences' ) {
				return Promise.resolve( { audiences: [], audience_label: 'List', help_before_save: '' } );
			}
			return Promise.resolve( { db_id: 1 } );
		} );
		extensions.getLocalListModalExtensions.mockReturnValue( [] );
	} );

	it( 'renders extension JSX after the built-in fields, in registration order', async () => {
		extensions.getLocalListModalExtensions.mockReturnValue( [
			{ render: () => <span data-testid="ext-a">A</span> },
			{ render: () => <span data-testid="ext-b">B</span> },
		] );
		render( <LocalListModal list={ null } onClose={ jest.fn() } onSaved={ jest.fn() } /> );
		await waitFor( () => expect( screen.getByTestId( 'ext-a' ) ).toBeInTheDocument() );
		expect( screen.getByTestId( 'ext-b' ) ).toBeInTheDocument();
	} );

	it( 'awaits extension onSave callbacks after a successful POST and includes their rejections in error notices', async () => {
		const createErrorNotice = jest.fn();
		jest.spyOn( dispatch( noticesStore ), 'createErrorNotice' ).mockImplementation( createErrorNotice );

		const onSaveResolved = jest.fn().mockResolvedValue( undefined );
		const onSaveRejected = jest.fn().mockRejectedValue( new Error( 'image upload failed' ) );

		extensions.getLocalListModalExtensions.mockReturnValue( [
			{ render: () => null, onSave: onSaveResolved },
			{ render: () => null, onSave: onSaveRejected },
		] );

		const onSaved = jest.fn();
		const onClose = jest.fn();
		render( <LocalListModal list={ null } onClose={ onClose } onSaved={ onSaved } /> );
		await waitFor( () => expect( screen.getByLabelText( /List title/ ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByLabelText( /List title/ ), { target: { value: 'X' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /^Add list$/ } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( onSaveResolved ).toHaveBeenCalled();
		expect( onSaveRejected ).toHaveBeenCalled();
		expect( createErrorNotice ).toHaveBeenCalledWith(
			expect.stringContaining( 'image upload failed' ),
			expect.objectContaining( { type: 'snackbar' } )
		);
		// Rejection does not block close.
		expect( onClose ).toHaveBeenCalled();
	} );

	it( 'treats a synchronous throw inside an extension onSave as a rejection, not a list-save failure', async () => {
		const createErrorNotice = jest.fn();
		jest.spyOn( dispatch( noticesStore ), 'createErrorNotice' ).mockImplementation( createErrorNotice );

		const onSaveSyncThrow = jest.fn( () => {
			throw new Error( 'extension blew up sync' );
		} );

		extensions.getLocalListModalExtensions.mockReturnValue( [ { render: () => null, onSave: onSaveSyncThrow } ] );

		const onSaved = jest.fn();
		const onClose = jest.fn();
		render( <LocalListModal list={ null } onClose={ onClose } onSaved={ onSaved } /> );
		await waitFor( () => expect( screen.getByLabelText( /List title/ ) ).toBeInTheDocument() );
		fireEvent.change( screen.getByLabelText( /List title/ ), { target: { value: 'Y' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /^Add list$/ } ) );

		await waitFor( () => expect( onSaved ).toHaveBeenCalled() );
		expect( onSaveSyncThrow ).toHaveBeenCalled();
		expect( createErrorNotice ).toHaveBeenCalledWith(
			expect.stringContaining( 'extension blew up sync' ),
			expect.objectContaining( { type: 'snackbar' } )
		);
		expect( onClose ).toHaveBeenCalled();
		expect( screen.queryByText( /Could not (create|update) local list/ ) ).not.toBeInTheDocument();
	} );

	it( 'runs extensions registered after the modal mounted', async () => {
		const onSave = jest.fn().mockResolvedValue( undefined );
		// Modal mounts with no extensions.
		extensions.getLocalListModalExtensions.mockReturnValue( [] );
		render( <LocalListModal list={ null } onClose={ jest.fn() } onSaved={ jest.fn() } /> );
		await waitFor( () => expect( screen.getByLabelText( /List title/ ) ).toBeInTheDocument() );
		// Late registration — modal must read the registry at submit time.
		extensions.getLocalListModalExtensions.mockReturnValue( [ { render: () => null, onSave } ] );
		fireEvent.change( screen.getByLabelText( /List title/ ), { target: { value: 'Z' } } );
		fireEvent.click( screen.getByRole( 'button', { name: /^Add list$/ } ) );
		await waitFor( () => expect( onSave ).toHaveBeenCalled() );
	} );
} );
