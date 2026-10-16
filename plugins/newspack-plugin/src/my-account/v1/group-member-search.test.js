import { initGroupMemberSearch, ANNOUNCE_DELAY_MS } from './group-member-search';

const renderPanel = () => {
	document.body.innerHTML = `
		<div class="newspack-my-account__group_subscription__search" data-status-template="Showing %1$d of %2$d members">
			<label for="members-search">Search members</label>
			<input type="search" id="members-search" aria-controls="members-table">
			<p data-search-status aria-live="polite"></p>
		</div>
		<table id="members-table">
			<tbody>
				<tr data-search-text="Jane Doe jane@example.test"><td><button class="remove">Remove</button></td></tr>
				<tr data-search-text="John Smith jsmith@example.test"><td></td></tr>
				<tr data-search-text="José Lima jose@lima.test"><td></td></tr>
			</tbody>
		</table>
		<p data-search-empty hidden>No members match your search.</p>
	`;
	initGroupMemberSearch( document );
	const input = document.getElementById( 'members-search' );
	const type = value => {
		input.value = value;
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	};
	const visibleRows = () => [ ...document.querySelectorAll( 'tbody tr' ) ].filter( row => ! row.hidden ).map( row => row.dataset.searchText );
	const status = () => document.querySelector( '[data-search-status]' ).textContent;
	return { type, visibleRows, status };
};

describe( 'group member search', () => {
	beforeEach( () => jest.useFakeTimers() );
	afterEach( () => jest.useRealTimers() );

	it( 'narrows rows by name or email, ignoring case and accents', () => {
		const { type, visibleRows } = renderPanel();

		type( 'JANE' );
		expect( visibleRows() ).toEqual( [ 'Jane Doe jane@example.test' ] );

		type( '@example.test' );
		expect( visibleRows() ).toEqual( [ 'Jane Doe jane@example.test', 'John Smith jsmith@example.test' ] );

		type( 'jose lima' );
		expect( visibleRows() ).toEqual( [ 'José Lima jose@lima.test' ] );
	} );

	it( 'announces the count once typing pauses, not on every keystroke', () => {
		const { type, status } = renderPanel();

		type( 'j' );
		type( 'ja' );
		expect( status() ).toBe( '' );

		jest.advanceTimersByTime( ANNOUNCE_DELAY_MS );
		expect( status() ).toBe( 'Showing 1 of 3 members' );
	} );

	it( 'swaps the table for the no-results copy, and restores everything when cleared', () => {
		const { type, visibleRows, status } = renderPanel();
		const table = document.getElementById( 'members-table' );
		const empty = document.querySelector( '[data-search-empty]' );

		type( 'nobody' );
		jest.advanceTimersByTime( ANNOUNCE_DELAY_MS );
		expect( table.hidden ).toBe( true );
		expect( empty.hidden ).toBe( false );
		expect( status() ).toBe( 'Showing 0 of 3 members' );

		// Whitespace-only counts as cleared: full list, and a count still pending
		// from the last keystroke is never announced.
		type( 'jane' );
		type( '  ' );
		jest.advanceTimersByTime( ANNOUNCE_DELAY_MS );
		expect( visibleRows() ).toHaveLength( 3 );
		expect( table.hidden ).toBe( false );
		expect( empty.hidden ).toBe( true );
		expect( status() ).toBe( '' );
	} );

	it( 'hides filtered-out rows without detaching them, so their row actions stay wired', () => {
		const { type } = renderPanel();
		const janeRow = document.querySelector( '.remove' ).closest( 'tr' );

		type( 'smith' );

		expect( janeRow.hidden ).toBe( true );
		expect( janeRow.isConnected ).toBe( true );
	} );
} );
