/**
 * Tests for the shared SortableTable primitive.
 *
 * Covers: initial render with rows, click-to-sort (header click reorders),
 * numeric-vs-string default direction, empty state, error state (shown instead
 * of emptyMessage), and initialRowLimit + "See more" toggle.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import SortableTable, { type SortableColumn } from './SortableTable';

interface TestRow {
	id: string;
	name: string;
	score: number | null;
}

const makeColumns = (): SortableColumn< TestRow >[] => [
	{
		key: 'name',
		label: 'Name',
		numeric: false,
		render: r => r.name,
		sortValue: r => r.name,
	},
	{
		key: 'score',
		label: 'Score',
		numeric: true,
		render: r => ( r.score === null ? '—' : String( r.score ) ),
		sortValue: r => r.score,
	},
];

const rows: TestRow[] = [
	{ id: 'a', name: 'Alpha', score: 10 },
	{ id: 'b', name: 'Beta', score: 30 },
	{ id: 'c', name: 'Gamma', score: 20 },
];

describe( 'SortableTable', () => {
	it( 'renders all row values', () => {
		render( <SortableTable columns={ makeColumns() } rows={ rows } getRowKey={ r => r.id } defaultSortKey="name" emptyMessage="No data." /> );
		expect( screen.getByText( 'Alpha' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Beta' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Gamma' ) ).toBeInTheDocument();
	} );

	it( 'clicking a header sorts the rows', () => {
		render( <SortableTable columns={ makeColumns() } rows={ rows } getRowKey={ r => r.id } defaultSortKey="name" emptyMessage="No data." /> );

		// Default sort: name ASC → Alpha, Beta, Gamma.
		const cells = () => screen.getAllByRole( 'cell' ).map( c => c.textContent );
		const namesInitial = cells().filter( ( _, i ) => i % 2 === 0 );
		expect( namesInitial ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );

		// Click the Score header → numeric, opens DESC (30, 20, 10).
		fireEvent.click( screen.getByRole( 'button', { name: /score/i } ) );
		const scoresDesc = screen
			.getAllByRole( 'cell' )
			.filter( ( _, i ) => i % 2 === 1 )
			.map( c => c.textContent );
		expect( scoresDesc ).toEqual( [ '30', '20', '10' ] );

		// Click again → ASC (10, 20, 30).
		fireEvent.click( screen.getByRole( 'button', { name: /score/i } ) );
		const scoresAsc = screen
			.getAllByRole( 'cell' )
			.filter( ( _, i ) => i % 2 === 1 )
			.map( c => c.textContent );
		expect( scoresAsc ).toEqual( [ '10', '20', '30' ] );
	} );

	it( 'numeric defaultSortKey opens DESC; string defaultSortKey opens ASC', () => {
		// Numeric defaultSortKey → DESC.
		const { unmount } = render(
			<SortableTable columns={ makeColumns() } rows={ rows } getRowKey={ r => r.id } defaultSortKey="score" emptyMessage="No data." />
		);
		const scoresDesc = screen
			.getAllByRole( 'cell' )
			.filter( ( _, i ) => i % 2 === 1 )
			.map( c => c.textContent );
		expect( scoresDesc ).toEqual( [ '30', '20', '10' ] );
		unmount();

		// String defaultSortKey → ASC.
		render( <SortableTable columns={ makeColumns() } rows={ rows } getRowKey={ r => r.id } defaultSortKey="name" emptyMessage="No data." /> );
		const namesAsc = screen
			.getAllByRole( 'cell' )
			.filter( ( _, i ) => i % 2 === 0 )
			.map( c => c.textContent );
		expect( namesAsc ).toEqual( [ 'Alpha', 'Beta', 'Gamma' ] );
	} );

	it( 'shows emptyMessage when rows is empty', () => {
		render(
			<SortableTable columns={ makeColumns() } rows={ [] } getRowKey={ r => r.id } defaultSortKey="name" emptyMessage="Nothing to show yet." />
		);
		expect( screen.getByText( 'Nothing to show yet.' ) ).toBeInTheDocument();
	} );

	it( 'shows errorMessage instead of emptyMessage when both are set and rows is empty', () => {
		render(
			<SortableTable
				columns={ makeColumns() }
				rows={ [] }
				getRowKey={ r => r.id }
				defaultSortKey="name"
				emptyMessage="Nothing to show yet."
				errorMessage="Something went wrong."
			/>
		);
		expect( screen.getByText( 'Something went wrong.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Nothing to show yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'initialRowLimit caps rows and "See more" reveals the rest', () => {
		const manyRows: TestRow[] = Array.from( { length: 5 }, ( _, i ) => ( {
			id: String( i ),
			name: `Row ${ i }`,
			score: i,
		} ) );

		render(
			<SortableTable
				columns={ makeColumns() }
				rows={ manyRows }
				getRowKey={ r => r.id }
				defaultSortKey="name"
				emptyMessage="No data."
				initialRowLimit={ 3 }
			/>
		);

		// Only 3 rows visible initially.
		expect( screen.queryByText( 'Row 3' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Row 4' ) ).not.toBeInTheDocument();

		// "See more" button should be present.
		const seeMore = screen.getByRole( 'button', { name: /see more/i } );
		expect( seeMore ).toBeInTheDocument();

		// Click it → all 5 rows should appear.
		fireEvent.click( seeMore );
		expect( screen.getByText( 'Row 3' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Row 4' ) ).toBeInTheDocument();
	} );
} );
