import { filter, sort } from './stories';

describe( 'stories utils', () => {
	describe( 'filter', () => {
		const mockFields = [
			{ slug: 'status', type: 'text', is_filterable: 'yes' },
			{
				slug: 'categories',
				type: 'text',
				is_filterable: 'yes',
				is_multiple: true,
			},
			{ slug: 'priority', type: 'number', is_filterable: 'yes' },
			{ slug: 'author', type: 'text', is_filterable: 'no' },
			{ slug: 'featured', type: 'boolean', is_filterable: 'always' },
		];

		const mockStories = [
			{
				status: 'draft',
				categories: [ 'news', 'politics' ],
				priority: 1,
				author: 'John Doe',
				featured: true,
			},
			{
				status: 'published',
				categories: [ 'sports' ],
				priority: 2,
				author: 'Jane Smith',
				featured: false,
			},
			{
				status: 'draft',
				categories: [ 'news' ],
				priority: 3,
				author: 'Bob Johnson',
				featured: true,
			},
		];

		it( 'should filter stories by exact match', () => {
			const view = {
				filters: [ { operator: 'is', field: 'status', value: 'draft' } ],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toHaveLength( 2 );
			expect( result.every( story => story.status === 'draft' ) ).toBe( true );
		} );

		it( 'should filter stories by not equal', () => {
			const view = {
				filters: [ { operator: 'isNot', field: 'status', value: 'draft' } ],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toHaveLength( 1 );
			expect( result[ 0 ].status ).toBe( 'published' );
		} );

		it( 'should filter stories by multiple values (isAny)', () => {
			const view = {
				filters: [
					{
						operator: 'isAny',
						field: 'categories',
						value: [ 'sports', 'politics' ],
					},
				],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toHaveLength( 2 );
			expect( result.some( story => story.categories.includes( 'sports' ) ) ).toBe( true );
			expect( result.some( story => story.categories.includes( 'politics' ) ) ).toBe( true );
		} );

		it( 'should filter stories by excluding multiple values (isNone)', () => {
			const view = {
				filters: [
					{
						operator: 'isNone',
						field: 'categories',
						value: [ 'sports', 'politics' ],
					},
				],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toHaveLength( 1 );
			expect( result[ 0 ].categories ).toEqual( [ 'news' ] );
		} );

		it( 'should filter stories by all values (isAll)', () => {
			const view = {
				filters: [
					{
						operator: 'isAll',
						field: 'categories',
						value: [ 'news' ],
					},
				],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toHaveLength( 2 );
			expect( result.every( story => story.categories.includes( 'news' ) ) ).toBe( true );
		} );

		it( 'should handle empty filter values', () => {
			const view = {
				filters: [ { operator: 'is', field: 'status', value: '' } ],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toEqual( mockStories );
		} );

		it( 'should handle non-filterable fields', () => {
			const view = {
				filters: [ { operator: 'is', field: 'nonFilterable', value: 'test' } ],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toEqual( mockStories );
		} );

		it( 'should handle fields with is_filterable set to "no"', () => {
			const view = {
				filters: [ { operator: 'is', field: 'author', value: 'John Doe' } ],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toEqual( mockStories );
		} );

		it( 'should filter fields with is_filterable set to "yes"', () => {
			const view = {
				filters: [ { operator: 'is', field: 'priority', value: 1 } ],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toHaveLength( 1 );
			expect( result[ 0 ].priority ).toBe( 1 );
		} );

		it( 'should filter fields with is_filterable set to "always"', () => {
			const view = {
				filters: [ { operator: 'is', field: 'featured', value: true } ],
			};
			const result = filter( mockStories, mockFields, view );
			expect( result ).toHaveLength( 2 );
			expect( result.every( story => story.featured === true ) ).toBe( true );
		} );
	} );

	describe( 'sort', () => {
		const mockFields = [
			{ slug: 'title', type: 'text', is_sortable: true },
			{ slug: 'priority', type: 'number', is_sortable: true },
			{ slug: 'date', type: 'date', is_sortable: true },
		];

		const mockStories = [
			{
				title: 'C',
				priority: 3,
				date: new Date( '2024-03-01' ).getTime(),
			},
			{
				title: 'A',
				priority: 1,
				date: new Date( '2024-03-03' ).getTime(),
			},
			{
				title: 'B',
				priority: 2,
				date: new Date( '2024-03-02' ).getTime(),
			},
		];

		it( 'should sort stories by text field in ascending order', () => {
			const view = {
				sort: { field: 'title', direction: 'asc' },
			};
			const result = sort( mockStories, mockFields, view );
			expect( result.map( story => story.title ) ).toEqual( [ 'A', 'B', 'C' ] );
		} );

		it( 'should sort stories by text field in descending order', () => {
			const view = {
				sort: { field: 'title', direction: 'desc' },
			};
			const result = sort( mockStories, mockFields, view );
			expect( result.map( story => story.title ) ).toEqual( [ 'C', 'B', 'A' ] );
		} );

		it( 'should sort stories by number field', () => {
			const view = {
				sort: { field: 'priority', direction: 'asc' },
			};
			const result = sort( mockStories, mockFields, view );
			expect( result.map( story => story.priority ) ).toEqual( [ 1, 2, 3 ] );
		} );

		it( 'should sort stories by date field', () => {
			const view = {
				sort: { field: 'date', direction: 'asc' },
			};
			const result = sort( mockStories, mockFields, view );
			expect( result.map( story => story.date ) ).toEqual( [
				new Date( '2024-03-01' ).getTime(),
				new Date( '2024-03-02' ).getTime(),
				new Date( '2024-03-03' ).getTime(),
			] );
		} );

		it( 'should handle non-sortable fields', () => {
			const view = {
				sort: { field: 'nonSortable', direction: 'asc' },
			};
			const result = sort( mockStories, mockFields, view );
			expect( result ).toEqual( mockStories );
		} );

		it( 'should handle missing sort configuration', () => {
			const view = {};
			const result = sort( mockStories, mockFields, view );
			expect( result ).toEqual( mockStories );
		} );

		it( 'should handle missing number field values', () => {
			const storiesWithMissingValues = [ { title: 'C', priority: 3 }, { title: 'A' }, { title: 'B', priority: 2 } ];
			const view = {
				sort: { field: 'priority', direction: 'asc' },
			};
			const result = sort( storiesWithMissingValues, mockFields, view );
			expect( result[ 0 ].priority ).toBe( 2 );
			expect( result[ 1 ].priority ).toBe( 3 );
			expect( result[ 2 ].priority ).toBeUndefined();
		} );

		it( 'should handle missing text field values', () => {
			const storiesWithMissingValues = [ { title: 'C', priority: 3 }, { priority: 1 }, { title: 'B', priority: 2 } ];
			const view = {
				sort: { field: 'title', direction: 'asc' },
			};
			const result = sort( storiesWithMissingValues, mockFields, view );
			expect( result[ 0 ].title ).toBe( 'B' );
			expect( result[ 1 ].title ).toBe( 'C' );
			expect( result[ 2 ].title ).toBeUndefined();
		} );
	} );
} );
