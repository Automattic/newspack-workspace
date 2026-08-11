import { getSpecificPostsQuery, selectSpecificPosts } from './specific-posts';

/**
 * Stand-in for the data registry's `select`.
 *
 * @param {Object}   options              Options.
 * @param {boolean}  options.statusesFail Whether core rejected the status-filtered lookup.
 * @param {Object[]} options.records      Records to return.
 * @return {Object} The fake `select` plus the queries it was called with.
 */
const createSelect = ( { statusesFail = false, records = [] } = {} ) => {
	const queries = [];
	const select = () => ( {
		getEntityRecords: ( kind, name, query ) => {
			queries.push( query );
			return records;
		},
		hasResolutionFailed: ( selectorName, args ) => statusesFail && 'getEntityRecords' === selectorName && undefined !== args[ 2 ].status,
	} );

	return { select, queries };
};

describe( 'selectSpecificPosts', () => {
	it( 'asks for unpublished posts alongside published ones', () => {
		const { select, queries } = createSelect();

		selectSpecificPosts( select, 'post', [ 12, 34 ] );

		expect( queries ).toEqual( [ { include: [ 12, 34 ], status: [ 'publish', 'future', 'draft', 'pending' ] } ] );
	} );

	it( 'asks for nothing when nothing is picked, rather than the whole collection', () => {
		const { select, queries } = createSelect();

		expect( selectSpecificPosts( select, 'post', [] ) ).toEqual( [] );
		expect( queries ).toEqual( [] );
	} );

	it( 'returns the posts it found', () => {
		const records = [ { id: 12, status: 'draft' } ];
		const { select } = createSelect( { records } );

		expect( selectSpecificPosts( select, 'post', [ 12 ] ) ).toBe( records );
	} );

	it( 'falls back to published posts when core rejected the status filter', () => {
		const { select, queries } = createSelect( { statusesFail: true } );

		selectSpecificPosts( select, 'product', [ 12 ] );

		expect( queries[ queries.length - 1 ] ).toEqual( { include: [ 12 ] } );
	} );

	it( 'checks the rejection against the post type actually being queried', () => {
		let checkedArgs;
		const select = () => ( {
			getEntityRecords: () => [],
			hasResolutionFailed: ( selectorName, args ) => {
				checkedArgs = args;
				return false;
			},
		} );

		selectSpecificPosts( select, 'product', [ 12 ] );

		expect( checkedArgs ).toEqual( [ 'postType', 'product', getSpecificPostsQuery( [ 12 ] ) ] );
	} );
} );

describe( 'getSpecificPostsQuery', () => {
	it( 'never asks for private posts', () => {
		expect( getSpecificPostsQuery( [ 12 ] ).status ).not.toContain( 'private' );
	} );

	it( 'requests full records, so the inspector can reuse the same cached request', () => {
		expect( getSpecificPostsQuery( [ 12 ] ) ).not.toHaveProperty( '_fields' );
	} );
} );
