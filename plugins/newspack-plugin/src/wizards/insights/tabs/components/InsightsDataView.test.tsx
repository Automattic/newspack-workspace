/**
 * Tests for the shared read-only DataViews table (InsightsDataView, NPPD-1889).
 *
 * Covers the wrapper contract every Insights table depends on: rendering rows +
 * formatted cells, the graceful-failure envelope (overlay / error /
 * not-configured route to the shared note instead of the table), the empty
 * state, numeric-default-sort with nulls last, and the expandable "See more"
 * toggle. The MetricTable / SortableTable adapters funnel through this, so this
 * is where that behavior is unit-tested.
 */

/**
 * External dependencies
 */
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Internal dependencies
 */
import InsightsDataView, { type InsightsColumn } from './InsightsDataView';

interface Row {
	id: string;
	name: string;
	score: number | null;
}

const columns: InsightsColumn< Row >[] = [
	{ key: 'name', label: 'Name', render: r => r.name, sortValue: r => r.name },
	{ key: 'score', label: 'Score', numeric: true, render: r => ( r.score === null ? '—' : String( r.score ) ), sortValue: r => r.score },
];

const rows: Row[] = [
	{ id: 'a', name: 'Alpha', score: 10 },
	{ id: 'b', name: 'Beta', score: 30 },
	{ id: 'c', name: 'Gamma', score: 20 },
];

const renderView = ( props: Partial< React.ComponentProps< typeof InsightsDataView< Row > > > = {} ) =>
	render( <InsightsDataView< Row > columns={ columns } rows={ rows } getRowKey={ r => r.id } emptyMessage="No data yet." { ...props } /> );

describe( 'InsightsDataView', () => {
	it( 'renders a cell for every row', () => {
		renderView();
		expect( screen.getByText( 'Alpha' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Beta' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Gamma' ) ).toBeInTheDocument();
	} );

	it( 'shows the empty message (not the table) when there are no rows', () => {
		renderView( { rows: [] } );
		expect( screen.getByText( 'No data yet.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Name' ) ).not.toBeInTheDocument();
	} );

	it( 'routes an overlay payload to the note instead of the table', () => {
		renderView( { overlay: { type: 'data_unavailable' } } );
		expect( screen.queryByText( 'Alpha' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'No data yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'routes an error to the note instead of the table', () => {
		renderView( { error: true } );
		expect( screen.queryByText( 'Alpha' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'No data yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'routes a not-configured payload to the note instead of the table', () => {
		renderView( { notConfigured: true } );
		expect( screen.queryByText( 'Alpha' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'No data yet.' ) ).not.toBeInTheDocument();
	} );

	it( 'applies the default sort (numeric DESC) with nulls last', () => {
		const withNull: Row[] = [
			{ id: 'a', name: 'Alpha', score: 10 },
			{ id: 'b', name: 'Beta', score: null },
			{ id: 'c', name: 'Gamma', score: 30 },
		];
		render(
			<InsightsDataView< Row >
				columns={ columns }
				rows={ withNull }
				getRowKey={ r => r.id }
				defaultSortKey="score"
				emptyMessage="No data yet."
			/>
		);
		const scores = screen
			.getAllByRole( 'cell' )
			.filter( ( _, i ) => i % 2 === 1 )
			.map( c => c.textContent );
		expect( scores ).toEqual( [ '30', '10', '—' ] );
	} );

	it( 'offers sorting but not "Move left/right" reordering in the column header menu', async () => {
		renderView( { defaultSortKey: 'score' } );
		// Open the "Score" column header dropdown.
		fireEvent.click( screen.getByRole( 'button', { name: /score/i } ) );
		// Sort affordance stays; the read-only table drops column reordering.
		expect( await screen.findByText( 'Sort descending' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Move left' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Move right' ) ).not.toBeInTheDocument();
	} );

	it( 'caps rows behind a "See more" toggle when expandable', () => {
		const many: Row[] = Array.from( { length: 5 }, ( _, i ) => ( { id: String( i ), name: `Row ${ i }`, score: i } ) );
		render(
			<InsightsDataView< Row >
				columns={ columns }
				rows={ many }
				getRowKey={ r => r.id }
				emptyMessage="No data yet."
				expandable
				defaultRowLimit={ 3 }
			/>
		);
		expect( screen.queryByText( 'Row 4' ) ).not.toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: /see more/i } ) );
		expect( screen.getByText( 'Row 4' ) ).toBeInTheDocument();
	} );
} );
