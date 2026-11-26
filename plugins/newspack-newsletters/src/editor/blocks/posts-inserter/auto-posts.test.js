import { selectAutoPosts } from './auto-posts';

const QUERY = { per_page: 3, exclude: [ 7 ] };

/**
 * Stand-in for the data registry's `select`.
 *
 * @param {Object}  options          Options.
 * @param {?Array}  options.records  What `getEntityRecords` returns; null while unresolved.
 * @param {boolean} options.finished Whether the resolution has finished (or failed).
 * @return {Object} The fake `select` plus the resolution args it was asked about.
 */
const createSelect = ( { records = null, finished = false } = {} ) => {
	const checked = [];
	const select = () => ( {
		getEntityRecords: () => records,
		hasFinishedResolution: ( selectorName, args ) => {
			checked.push( [ selectorName, args ] );
			return finished;
		},
	} );

	return { select, checked };
};

describe( 'selectAutoPosts', () => {
	it( 'reads as loading while the query has not resolved', () => {
		const { select } = createSelect();

		expect( selectAutoPosts( select, 'post', QUERY ) ).toEqual( { posts: [], isLoading: true } );
	} );

	it( 'returns the posts once the query resolves', () => {
		const records = [ { id: 1 } ];
		const { select } = createSelect( { records, finished: true } );

		expect( selectAutoPosts( select, 'post', QUERY ) ).toEqual( { posts: records, isLoading: false } );
	} );

	it( 'never reads records already on hand as loading', () => {
		const records = [ { id: 1 } ];
		const { select } = createSelect( { records, finished: false } );

		expect( selectAutoPosts( select, 'post', QUERY ) ).toEqual( { posts: records, isLoading: false } );
	} );

	it( 'reads as empty, not loading, when the request failed', () => {
		const { select } = createSelect( { records: null, finished: true } );

		expect( selectAutoPosts( select, 'post', QUERY ) ).toEqual( { posts: [], isLoading: false } );
	} );

	it( 'checks the resolution of the same request it reads records from', () => {
		const { select, checked } = createSelect();

		selectAutoPosts( select, 'page', QUERY );

		expect( checked ).toEqual( [ [ 'getEntityRecords', [ 'postType', 'page', QUERY ] ] ] );
	} );
} );
