/**
 * Pure-logic tests for the Insights metric mappers (NPPD-1649). No React
 * rendering, so these run without @testing-library. Render-level coverage of
 * MetricCard / MetricTable / AudienceTab lives in the .test.tsx files.
 */

/**
 * Internal dependencies
 */
import { payloadToCard, toSeries, uniformValue } from './metrics';
import { formatDuration } from './format';

describe( 'uniformValue', () => {
	it( 'returns the shared value when every row matches', () => {
		const rows = [
			{ country: 'United States', n: 1 },
			{ country: 'United States', n: 2 },
		];
		expect( uniformValue( rows, 'country' ) ).toBe( 'United States' );
	} );

	it( 'returns null when rows span multiple values', () => {
		const rows = [ { country: 'United States' }, { country: 'Canada' } ];
		expect( uniformValue( rows, 'country' ) ).toBeNull();
	} );

	it( 'does not collapse on (not set) / empty / missing values', () => {
		expect( uniformValue( [ { country: '(not set)' }, { country: '(not set)' } ], 'country' ) ).toBeNull();
		expect( uniformValue( [ { country: '' }, { country: '' } ], 'country' ) ).toBeNull();
		expect( uniformValue( [ { country: null }, { country: null } ], 'country' ) ).toBeNull();
	} );

	it( 'returns null for an empty row set', () => {
		expect( uniformValue( [], 'country' ) ).toBeNull();
	} );
} );

describe( 'payloadToCard', () => {
	it( 'skips hidden_in_v1 metrics', () => {
		expect( payloadToCard( { label: 'x', current: { hidden_in_v1: true, computable: false } } ) ).toBeNull();
	} );

	it( 'passes through an overlay', () => {
		const card = payloadToCard( { label: 'x', current: { overlay: { type: 'custom_dimension_missing', dimensions: [ 'author' ] } } } );
		expect( card?.overlay?.dimensions ).toEqual( [ 'author' ] );
	} );

	it( 'passes through an error', () => {
		expect( payloadToCard( { label: 'x', current: { error: 'boom' } } )?.error ).toBe( 'boom' );
	} );

	it( 'maps type to MetricCard format and carries a computable previous value', () => {
		const card = payloadToCard( {
			label: 'x',
			current: { value: 0.6, computable: true, type: 'rate' },
			previous: { value: 0.5, computable: true, type: 'rate' },
		} );
		expect( card?.format ).toBe( 'percent' );
		expect( card?.previousValue ).toBe( 0.5 );
	} );

	it( 'drops a non-computable previous value', () => {
		const card = payloadToCard( {
			label: 'x',
			current: { value: 5, computable: true, type: 'count' },
			previous: { value: null, computable: false },
		} );
		expect( card?.previousValue ).toBeNull();
	} );
} );

describe( 'toSeries', () => {
	it( 'maps rows to label/value pairs', () => {
		const series = toSeries(
			{
				computable: true,
				rows: [
					{ device: 'mobile', readers: 10 },
					{ device: 'desktop', readers: 4 },
				],
			},
			'device',
			'readers'
		);
		expect( series ).toEqual( [
			{ label: 'mobile', value: 10 },
			{ label: 'desktop', value: 4 },
		] );
	} );

	it( 'sums duplicate labels (e.g. date × reader_type)', () => {
		const series = toSeries(
			{
				computable: true,
				rows: [
					{ date: '20260601', readers: 3 },
					{ date: '20260601', readers: 7 },
				],
			},
			'date',
			'readers'
		);
		expect( series ).toEqual( [ { label: '20260601', value: 10 } ] );
	} );

	it( 'returns [] for overlay/error payloads', () => {
		expect( toSeries( { computable: false, overlay: { type: 'custom_dimension_missing', dimensions: [] } }, 'a', 'b' ) ).toEqual( [] );
	} );
} );

describe( 'formatDuration', () => {
	it( 'formats seconds as m:ss', () => {
		expect( formatDuration( 142 ) ).toBe( '2:22' );
		expect( formatDuration( 5 ) ).toBe( '0:05' );
	} );

	it( 'formats past an hour as h:mm:ss', () => {
		expect( formatDuration( 3661 ) ).toBe( '1:01:01' );
	} );
} );
