/**
 * Internal dependencies.
 */
import { activeBreadcrumbs, appendSectionName } from './breadcrumbs-select';

const SECTIONS = [
	{ path: '/', breadcrumbs: [ { label: 'Audience Management' }, { label: 'Configuration' } ] },
	{ path: '/donations', breadcrumbs: [ { label: 'Audience Management' }, { label: 'Donations' } ] },
];

describe( 'activeBreadcrumbs', () => {
	it( 'returns the matching section breadcrumbs by exact path', () => {
		expect( activeBreadcrumbs( SECTIONS, '/donations' ) ).toEqual( [ { label: 'Audience Management' }, { label: 'Donations' } ] );
	} );

	it( 'falls back to the first section when no path matches', () => {
		expect( activeBreadcrumbs( SECTIONS, '/nope' ) ).toEqual( SECTIONS[ 0 ].breadcrumbs );
	} );

	it( 'returns [] when sections is empty', () => {
		expect( activeBreadcrumbs( [], '/x' ) ).toEqual( [] );
	} );

	it( 'returns [] when sections is null or undefined', () => {
		expect( activeBreadcrumbs( null, '/x' ) ).toEqual( [] );
		expect( activeBreadcrumbs( undefined, '/x' ) ).toEqual( [] );
	} );

	it( 'matches a non-exact section by path prefix', () => {
		const sections = [
			{ path: '/', breadcrumbs: [ { label: 'Root' } ] },
			{ path: '/access', exact: false, breadcrumbs: [ { label: 'Root' }, { label: 'Access control' } ] },
		];
		expect( activeBreadcrumbs( sections, '/access/institutions' ) ).toEqual( [ { label: 'Root' }, { label: 'Access control' } ] );
	} );

	it( 'matches a parameterized route to its own breadcrumbs', () => {
		const sections = [
			{ path: '/content-gates', breadcrumbs: [ { label: 'Access control' } ] },
			{
				path: '/institutions/:id',
				exact: true,
				breadcrumbs: [ { label: 'Access control' }, { label: 'Edit institution' } ],
			},
		];
		expect( activeBreadcrumbs( sections, '/institutions/123' ) ).toEqual( [ { label: 'Access control' }, { label: 'Edit institution' } ] );
	} );

	it( 'matches via activeTabPaths wildcard', () => {
		const sections = [
			{ path: '/', breadcrumbs: [ { label: 'Root' } ] },
			{
				path: '/segments',
				activeTabPaths: [ '/segments/*' ],
				breadcrumbs: [ { label: 'Root' }, { label: 'Segments' } ],
			},
		];
		expect( activeBreadcrumbs( sections, '/segments/123' ) ).toEqual( [ { label: 'Root' }, { label: 'Segments' } ] );
	} );

	it( 'prefers an exact path match over the first section', () => {
		expect( activeBreadcrumbs( SECTIONS, '/' ) ).toEqual( SECTIONS[ 0 ].breadcrumbs );
	} );

	it( 'returns [] when the matched section has no breadcrumbs', () => {
		expect( activeBreadcrumbs( [ { path: '/' } ], '/' ) ).toEqual( [] );
	} );
} );

describe( 'appendSectionName', () => {
	const base = [ { label: 'Access Control' } ];

	it( 'returns the base trail unchanged when sectionName is falsy', () => {
		expect( appendSectionName( base, undefined ) ).toBe( base );
		expect( appendSectionName( base, '' ) ).toBe( base );
		expect( appendSectionName( base, null ) ).toBe( base );
	} );

	it( 'appends a string sectionName as a leaf crumb', () => {
		expect( appendSectionName( base, 'Content Gifting' ) ).toEqual( [ { label: 'Access Control' }, { label: 'Content Gifting' } ] );
	} );

	it( 'appends an array of crumbs in order', () => {
		const sectionName = [ { label: 'Mailchimp', url: '/mailchimp' }, { label: 'Logs' } ];
		expect( appendSectionName( base, sectionName ) ).toEqual( [
			{ label: 'Access Control' },
			{ label: 'Mailchimp', url: '/mailchimp' },
			{ label: 'Logs' },
		] );
	} );

	it( 'dedupes a string leaf that repeats the current trailing label', () => {
		const trail = [ { label: 'Access Control' }, { label: 'Metered Countdown' } ];
		expect( appendSectionName( trail, 'Metered Countdown' ) ).toEqual( trail );
	} );

	it( 'dedupes an array leaf that repeats the current trailing label', () => {
		const trail = [ { label: 'Access Control' }, { label: 'Metered Countdown' } ];
		expect( appendSectionName( trail, [ { label: 'Metered Countdown' } ] ) ).toEqual( trail );
	} );

	it( 'skips crumbs without a label', () => {
		expect( appendSectionName( base, [ { url: '/no-label' }, { label: 'Edit' } ] ) ).toEqual( [
			{ label: 'Access Control' },
			{ label: 'Edit' },
		] );
	} );

	it( 'only dedupes against the immediate predecessor, not earlier crumbs', () => {
		const trail = [ { label: 'Access Control' }, { label: 'Institutions' } ];
		// 'Access Control' repeats an earlier (non-adjacent) crumb, so it is kept.
		expect( appendSectionName( trail, [ { label: 'Access Control' } ] ) ).toEqual( [
			{ label: 'Access Control' },
			{ label: 'Institutions' },
			{ label: 'Access Control' },
		] );
	} );

	it( 'does not mutate the input trail', () => {
		const trail = [ { label: 'Access Control' } ];
		appendSectionName( trail, 'Content Gifting' );
		expect( trail ).toEqual( [ { label: 'Access Control' } ] );
	} );
} );
