/**
 * Unit tests for the GA4-backed Insights UI atoms (NPPD-1649): MetricCard's
 * new overlay/error states, the payloadToCard mapper (incl. hidden_in_v1
 * skip), and MetricTable's graceful-failure states.
 */

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import MetricCard from './MetricCard';
import MetricTable from './MetricTable';
import Scorecard from './Scorecard';
import { payloadToCard } from './metrics';

describe( 'MetricCard graceful states', () => {
	it( 'renders a formatted value normally', () => {
		render( <MetricCard label="Active Readers" value={ 128430 } format="number" /> );
		expect( screen.getByText( '128,430' ) ).toBeInTheDocument();
	} );

	it( 'renders the custom-dimension overlay instead of a value', () => {
		render(
			<MetricCard
				label="Newsletter Subscriber Rate"
				overlay={ { type: 'custom_dimension_missing', dimensions: [ 'is_newsletter_subscriber' ] } }
			/>
		);
		expect( screen.getByText( 'is_newsletter_subscriber' ) ).toBeInTheDocument();
		expect( screen.getByText( /Custom dimension/ ) ).toBeInTheDocument();
		// No dash placeholder above the note (NPPD-1649 fix #4).
		expect( screen.queryByText( '—' ) ).not.toBeInTheDocument();
	} );

	it( 'renders an error state', () => {
		render( <MetricCard label="Local Reader Rate" error="boom" /> );
		expect( screen.getByText( 'Data temporarily unavailable.' ) ).toBeInTheDocument();
	} );

	it( 'prefixes an up arrow on a rising delta and a down arrow on a falling one', () => {
		const { rerender } = render( <MetricCard label="x" value={ 120 } previousValue={ 100 } format="number" /> );
		expect( screen.getByText( '↑' ) ).toBeInTheDocument();
		expect( screen.getByText( '20%' ) ).toBeInTheDocument();
		rerender( <MetricCard label="x" value={ 80 } previousValue={ 100 } format="number" /> );
		expect( screen.getByText( '↓' ) ).toBeInTheDocument();
		expect( screen.getByText( '20%' ) ).toBeInTheDocument();
	} );

	it( 'shows no arrow for a zero delta', () => {
		render( <MetricCard label="x" value={ 100 } previousValue={ 100 } format="number" /> );
		expect( screen.getByText( '0%' ) ).toBeInTheDocument();
		expect( screen.queryByText( '↑' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( '↓' ) ).not.toBeInTheDocument();
	} );

	it( 'keeps arrow direction factual while tone follows lowerIsBetter (down arrow, positive tone)', () => {
		const { container } = render( <MetricCard label="Bounce Rate" value={ 40 } previousValue={ 50 } format="percent" lowerIsBetter /> );
		expect( screen.getByText( '↓' ) ).toBeInTheDocument();
		// A decrease is good news when lowerIsBetter → positive (green) tone.
		expect( container.querySelector( '.newspack-insights__metric-card-delta--positive' ) ).toBeTruthy();
	} );
} );

describe( 'payloadToCard', () => {
	it( 'returns null for hidden_in_v1 metrics', () => {
		expect( payloadToCard( { label: 'Returning', current: { value: null, computable: false, hidden_in_v1: true } } ) ).toBeNull();
	} );

	it( 'maps a rate to a percent card with a delta', () => {
		const card = payloadToCard( {
			label: 'Engaged',
			current: { value: 0.6, computable: true, type: 'rate' },
			previous: { value: 0.5, computable: true, type: 'rate' },
		} );
		expect( card ).not.toBeNull();
		expect( card?.format ).toBe( 'percent' );
		expect( card?.value ).toBe( 0.6 );
		expect( card?.previousValue ).toBe( 0.5 );
	} );
} );

describe( 'Scorecard', () => {
	it( 'renders nothing for a hidden metric', () => {
		const { container } = render( <Scorecard label="Returning" current={ { value: null, computable: false, hidden_in_v1: true } } /> );
		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'MetricTable graceful states', () => {
	const columns = [
		{ key: 'country', label: 'Country' },
		{ key: 'readers', label: 'Readers', format: 'number' as const, align: 'right' as const },
	];

	it( 'renders rows', () => {
		render(
			<MetricTable
				columns={ columns }
				emptyMessage="none"
				payload={ { computable: true, type: 'table', rows: [ { country: 'United States', readers: 112900 } ] } }
			/>
		);
		expect( screen.getByText( 'United States' ) ).toBeInTheDocument();
		expect( screen.getByText( '112,900' ) ).toBeInTheDocument();
	} );

	it( 'renders the empty message when there are no rows', () => {
		render( <MetricTable columns={ columns } emptyMessage="No data here" payload={ { computable: true, type: 'table', rows: [] } } /> );
		expect( screen.getByText( 'No data here' ) ).toBeInTheDocument();
	} );

	it( 'renders the overlay note for a missing custom dimension', () => {
		render(
			<MetricTable
				columns={ columns }
				emptyMessage="none"
				payload={ { computable: false, overlay: { type: 'custom_dimension_missing', dimensions: [ 'author' ] } } }
			/>
		);
		expect( screen.getByText( /author/ ) ).toBeInTheDocument();
	} );
} );
