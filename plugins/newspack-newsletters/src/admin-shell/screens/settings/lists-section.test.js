import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import ListsSection from './lists-section';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../../../wizard-bridge/extensions', () => ( {
	getLocalListModalExtensions: jest.fn( () => [] ),
} ) );

const localList = {
	id: 'newspack-1',
	db_id: 1,
	title: 'Local list',
	description: '',
	type: 'local',
	type_label: 'Local list',
	active: true,
	audience: 'a-1',
	remote_name: '',
};

const espList = {
	id: 'esp-42',
	db_id: 42,
	title: 'Newsletter A',
	description: 'desc',
	type: 'remote',
	type_label: 'Mailchimp list',
	active: true,
	remote_name: 'Newsletter A',
};

describe( 'ListsSection', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'does not render a bulk Save button (per-row commits only)', () => {
		render(
			<ListsSection
				lists={ [ espList ] }
				isLoading={ false }
				error={ null }
				canAddLocal={ true }
				onPatchList={ jest.fn().mockResolvedValue( espList ) }
				onLocalListChanged={ jest.fn() }
			/>
		);
		expect( screen.queryByRole( 'button', { name: /Save subscription lists/ } ) ).not.toBeInTheDocument();
	} );

	it( 'commits the active toggle immediately via onPatchList', async () => {
		const onPatchList = jest.fn().mockResolvedValue( { ...espList, active: false } );
		render(
			<ListsSection
				lists={ [ espList ] }
				isLoading={ false }
				error={ null }
				canAddLocal={ false }
				onPatchList={ onPatchList }
				onLocalListChanged={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByRole( 'checkbox', { name: /Newsletter A/ } ) );
		await waitFor( () => expect( onPatchList ).toHaveBeenCalledWith( 42, { active: false } ) );
	} );

	it( 'opens the modal in ESP mode when an ESP row Edit button is clicked', async () => {
		apiFetch.mockResolvedValue( espList );
		render(
			<ListsSection
				lists={ [ espList ] }
				isLoading={ false }
				error={ null }
				canAddLocal={ false }
				onPatchList={ jest.fn() }
				onLocalListChanged={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: /^Edit$/ } ) );
		await waitFor( () => expect( screen.getByText( /Edit subscription list/ ) ).toBeInTheDocument() );
	} );

	it( 'opens the modal in local mode when a local row Edit button is clicked', async () => {
		apiFetch.mockImplementation( opts => {
			if ( opts.path === '/newspack-newsletters/v1/lists/audiences' ) {
				return Promise.resolve( { audiences: [], audience_label: 'List', help_before_save: '' } );
			}
			return Promise.resolve( localList );
		} );
		render(
			<ListsSection
				lists={ [ localList ] }
				isLoading={ false }
				error={ null }
				canAddLocal={ true }
				onPatchList={ jest.fn() }
				onLocalListChanged={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: /^Edit$/ } ) );
		await waitFor( () => expect( screen.getByText( /Edit local list/ ) ).toBeInTheDocument() );
	} );

	it( 'replaces inline title/description fields on ESP rows with an Edit button', () => {
		render(
			<ListsSection
				lists={ [ espList ] }
				isLoading={ false }
				error={ null }
				canAddLocal={ false }
				onPatchList={ jest.fn() }
				onLocalListChanged={ jest.fn() }
			/>
		);
		// No inline TextControl/TextareaControl labelled "List title" / "List description"
		// at the section level — those now live inside the modal.
		expect( screen.queryByLabelText( /List title/ ) ).not.toBeInTheDocument();
		expect( screen.queryByLabelText( /List description/ ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: /^Edit$/ } ) ).toBeInTheDocument();
	} );
} );
