/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import SegmentsList from './segments-list';

const segment = ( id, name, isDisabled = false ) => ( { id, name, configuration: { is_disabled: isDisabled }, criteria: [] } );
// The live region repeats a spoken notice, and keeps it after the notice has gone.
const NOTICE = { selector: '.components-notice__content' };

const SEGMENTS = [ segment( 'a', 'Donors' ), segment( 'b', 'Newsletter readers', true ) ];

const renderList = ( props = {} ) => {
	window.newspackAudienceCampaigns = { api: '/api', criteria: [] };
	const merged = { segments: SEGMENTS, setSegments: jest.fn(), wizardApiFetch: jest.fn(), ...props };
	render(
		<MemoryRouter>
			<SegmentsList { ...merged } />
		</MemoryRouter>
	);
	return merged;
};

const rowTitles = () => screen.queryAllByRole( 'heading', { level: 3 } ).map( heading => heading.textContent );

beforeAll( () => {
	window.scrollTo = jest.fn();
} );

describe( 'SegmentsList', () => {
	it( 'shows the empty state without a toolbar when there are no segments', () => {
		renderList( { segments: [] } );
		expect( screen.getByText( 'No segments yet' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'searchbox' ) ).not.toBeInTheDocument();
	} );

	it( 'names the toggle and the menu after the segment', () => {
		renderList();
		expect( screen.getByRole( 'checkbox', { name: 'Donors' } ) ).toBeChecked();
		expect( screen.getByRole( 'checkbox', { name: 'Newsletter readers' } ) ).not.toBeChecked();
		expect( screen.getByRole( 'button', { name: 'Segment actions: Donors' } ) ).toBeInTheDocument();
	} );

	it( 'has no move buttons', () => {
		renderList();
		expect( screen.queryByRole( 'button', { name: 'Move one position up' } ) ).not.toBeInTheDocument();
	} );

	it( 'searches by segment name, with a message when nothing matches', () => {
		renderList();
		const search = screen.getByRole( 'searchbox', { name: 'Search segments' } );

		fireEvent.change( search, { target: { value: 'donor' } } );
		return waitFor( () => expect( rowTitles() ).toEqual( [ 'Donors' ] ) ).then( () => {
			fireEvent.change( search, { target: { value: 'nothing like this' } } );
			return waitFor( () => expect( screen.getByText( 'No segments match your search.' ) ).toBeInTheDocument() );
		} );
	} );

	it( 'offers Segment Priority only with two or more segments', () => {
		renderList( { segments: [ SEGMENTS[ 0 ] ] } );
		const header = document.querySelector( '.newspack-page__header-actions' );
		expect( within( header ).getByRole( 'link', { name: 'Add Segment' } ) ).toHaveAttribute( 'href', '#/segments/new' );
		expect( within( header ).queryByRole( 'button', { name: 'More actions' } ) ).not.toBeInTheDocument();
	} );

	it( 'opens the modal from the header menu', async () => {
		renderList();
		fireEvent.click( screen.getByRole( 'button', { name: 'More actions' } ) );
		fireEvent.click( await screen.findByRole( 'menuitem', { name: 'Segment Priority' } ) );
		expect( await screen.findByRole( 'dialog', { name: 'Segment Priority' } ) ).toBeInTheDocument();
	} );

	it( 'disables only the row being toggled, and hands on the reply', async () => {
		let resolve;
		const wizardApiFetch = jest.fn( () => new Promise( done => ( resolve = done ) ) );
		const { setSegments } = renderList( { wizardApiFetch } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Donors' } ) );

		expect( screen.getByRole( 'checkbox', { name: 'Donors', hidden: true } ).closest( '[inert]' ) ).not.toBeNull();
		expect( screen.getByRole( 'checkbox', { name: 'Newsletter readers' } ).closest( '[inert]' ) ).toBeNull();
		expect( wizardApiFetch.mock.calls[ 0 ][ 0 ].data.configuration.is_disabled ).toBe( true );

		resolve( SEGMENTS );
		await waitFor( () => expect( setSegments ).toHaveBeenCalledWith( SEGMENTS ) );
		await waitFor( () => expect( screen.getByRole( 'checkbox', { name: 'Donors' } ).closest( '[inert]' ) ).toBeNull() );
	} );

	it( 'does not let an older reply undo a newer one', async () => {
		const replies = [];
		const wizardApiFetch = jest.fn( () => new Promise( done => replies.push( done ) ) );
		const { setSegments } = renderList( { wizardApiFetch } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Donors' } ) );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Newsletter readers' } ) );

		replies[ 1 ]( [ 'newer' ] );
		await waitFor( () => expect( setSegments ).toHaveBeenCalledWith( [ 'newer' ] ) );
		replies[ 0 ]( [ 'older' ] );
		await waitFor( () => expect( screen.getByRole( 'checkbox', { name: 'Donors' } ).closest( '[inert]' ) ).toBeNull() );
		expect( setSegments ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows a failed toggle, and clears it at the next attempt', async () => {
		let resolveRetry;
		const wizardApiFetch = jest
			.fn()
			.mockRejectedValueOnce( { message: 'Toggle failed.' } )
			.mockImplementationOnce( () => new Promise( resolve => ( resolveRetry = resolve ) ) );
		renderList( { wizardApiFetch } );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Donors' } ) );
		await screen.findByText( 'Toggle failed.', NOTICE );
		await waitFor( () => expect( screen.getByRole( 'checkbox', { name: 'Donors' } ).closest( '[inert]' ) ).toBeNull() );

		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Donors' } ) );
		expect( screen.queryByText( 'Toggle failed.', NOTICE ) ).not.toBeInTheDocument();
		resolveRetry( SEGMENTS );
	} );

	it( 'shows a failed delete, with a fallback message', async () => {
		renderList( { wizardApiFetch: jest.fn().mockRejectedValue( {} ) } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Segment actions: Donors' } ) );
		fireEvent.click( await screen.findByRole( 'menuitem', { name: 'Delete' } ) );
		await screen.findByText( 'There was an error deleting the segment. Please try again.', NOTICE );
		expect( rowTitles() ).toEqual( [ 'Donors', 'Newsletter readers' ] );
	} );

	describe( 'Status filter', () => {
		const statusChip = () => screen.queryByRole( 'button', { name: /^Status/ } );

		it( 'is hidden while every segment is enabled, leaving search', () => {
			renderList( { segments: [ segment( 'a', 'Donors' ), segment( 'c', 'Members' ) ] } );
			expect( statusChip() ).not.toBeInTheDocument();
			expect( screen.getByRole( 'searchbox', { name: 'Search segments' } ) ).toBeInTheDocument();
		} );

		it( 'shows when a segment is disabled', () => {
			renderList();
			expect( statusChip() ).toBeInTheDocument();
		} );

		it( 'clears itself when the last disabled segment is enabled, so the list is not stuck empty', async () => {
			const enabled = [ segment( 'a', 'Donors' ), segment( 'b', 'Newsletter readers' ) ];
			const wizardApiFetch = jest.fn().mockResolvedValue( enabled );
			const StatefulList = () => {
				const [ segments, setSegments ] = useState( SEGMENTS );
				return <SegmentsList segments={ segments } setSegments={ setSegments } wizardApiFetch={ wizardApiFetch } />;
			};
			window.newspackAudienceCampaigns = { api: '/api', criteria: [] };
			render(
				<MemoryRouter>
					<StatefulList />
				</MemoryRouter>
			);

			fireEvent.click( statusChip() );
			fireEvent.click( await screen.findByRole( 'option', { name: 'Disabled' } ) );
			await waitFor( () => expect( rowTitles() ).toEqual( [ 'Newsletter readers' ] ) );

			fireEvent.click( screen.getByRole( 'checkbox', { name: 'Newsletter readers' } ) );

			await waitFor( () => expect( rowTitles() ).toEqual( [ 'Donors', 'Newsletter readers' ] ) );
			expect( statusChip() ).not.toBeInTheDocument();
		} );
	} );
} );
