window.newspack_blocks_data = { assets_path: '' };

const { queryCriteriaFromAttributes } = require( './utils' );

describe( 'queryCriteriaFromAttributes', () => {
	it( 'keeps the included post statuses in Static mode, so selected drafts can load', () => {
		const criteria = queryCriteriaFromAttributes( {
			specificMode: true,
			specificPosts: [ 12 ],
			includedPostStatuses: [ 'publish', 'draft' ],
		} );

		expect( criteria.include ).toEqual( [ 12 ] );
		expect( criteria.includedPostStatuses ).toEqual( [ 'publish', 'draft' ] );
	} );
} );
