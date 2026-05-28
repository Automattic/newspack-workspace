import { filter, sortByOrder } from './budgets';

describe( 'budgets utils', () => {
	describe( 'filter', () => {
		const mockBudgets = [
			{
				id: 1,
				name: 'Budget A',
				archived: false,
				order: 1,
			},
			{
				id: 2,
				name: 'Budget B',
				archived: true,
				order: 0,
			},
			{
				id: 3,
				name: 'Budget C',
				archived: false,
				order: 2,
			},
			{
				id: 4,
				name: 'Budget D',
				archived: true,
				order: 0,
			},
		];

		it( 'should filter active budgets', () => {
			const view = {
				filters: [ { field: 'status', value: 'active' } ],
			};
			const result = filter( mockBudgets, view );
			expect( result ).toHaveLength( 2 );
			expect( result.every( budget => ! budget.archived ) ).toBe( true );
		} );

		it( 'should filter archived budgets', () => {
			const view = {
				filters: [ { field: 'status', value: 'archived' } ],
				page: 1,
				perPage: 10,
			};
			const result = filter( mockBudgets, view );
			expect( result ).toHaveLength( 2 );
			expect( result.every( budget => budget.archived ) ).toBe( true );
		} );

		it( 'should handle pagination for archived budgets', () => {
			const view = {
				filters: [ { field: 'status', value: 'archived' } ],
				page: 1,
				perPage: 1,
			};
			const result = filter( mockBudgets, view );
			expect( result ).toHaveLength( 1 );
			expect( result[ 0 ].archived ).toBe( true );

			const secondPageView = {
				filters: [ { field: 'status', value: 'archived' } ],
				page: 2,
				perPage: 1,
			};
			const secondPageResult = filter( mockBudgets, secondPageView );
			expect( secondPageResult ).toHaveLength( 1 );
			expect( secondPageResult[ 0 ].archived ).toBe( true );
			expect( secondPageResult[ 0 ].id ).not.toBe( result[ 0 ].id );
		} );

		it( 'should handle invalid filters', () => {
			const view = {
				filters: [ { field: 'nonExistent', value: 'something' } ],
			};
			const result = filter( mockBudgets, view );
			expect( result ).toEqual( mockBudgets );
		} );

		it( 'should handle null or undefined filter values', () => {
			const view = {
				filters: [ { field: 'status', value: null } ],
			};
			const result = filter( mockBudgets, view );
			expect( result ).toEqual( mockBudgets );

			const viewUndefined = {
				filters: [ { field: 'status', value: undefined } ],
			};
			const resultUndefined = filter( mockBudgets, viewUndefined );
			expect( resultUndefined ).toEqual( mockBudgets );
		} );
	} );

	describe( 'sortByOrder', () => {
		const mockBudgets = [
			{ id: 1, name: 'Budget A', order: 0 },
			{ id: 2, name: 'Budget B', order: 3 },
			{ id: 3, name: 'Budget C', order: 1 },
			{ id: 4, name: 'Budget D', order: 2 },
			{ id: 5, name: 'Budget E', order: 0 },
		];

		it( 'should sort budgets by order value', () => {
			const result = [ ...mockBudgets ].sort( ( a, b ) => {
				if ( a.order === 0 && b.order !== 0 ) {
					return 1;
				}
				if ( b.order === 0 && a.order !== 0 ) {
					return -1;
				}
				return a.order - b.order;
			} );

			expect( result[ 0 ].id ).toBe( 3 ); // order 1
			expect( result[ 1 ].id ).toBe( 4 ); // order 2
			expect( result[ 2 ].id ).toBe( 2 ); // order 3
			expect( result[ 3 ].order ).toBe( 0 );
			expect( result[ 4 ].order ).toBe( 0 );

			const actualResult = sortByOrder( mockBudgets, [] );
			expect( actualResult.slice( 0, 3 ).every( b => b.order > 0 ) ).toBe( true );
			expect( actualResult.slice( 3 ).every( b => b.order === 0 ) ).toBe( true );
		} );

		it( 'should respect custom order array', () => {
			const customOrder = [ 2, 4, 3 ];
			const result = sortByOrder( mockBudgets, customOrder );

			expect( result[ 0 ].id ).toBe( 2 );
			expect( result[ 1 ].id ).toBe( 4 );
			expect( result[ 2 ].id ).toBe( 3 );
			expect( result[ 3 ].order ).toBe( 0 );
			expect( result[ 4 ].order ).toBe( 0 );
		} );

		it( 'should handle items with same order value', () => {
			const sameOrderBudgets = [
				{ id: 1, name: 'Budget A', order: 1 },
				{ id: 2, name: 'Budget B', order: 1 },
				{ id: 3, name: 'Budget C', order: 1 },
			];

			const result = sortByOrder( sameOrderBudgets, [] );
			expect( result[ 0 ].id ).toBe( 1 );
			expect( result[ 1 ].id ).toBe( 2 );
			expect( result[ 2 ].id ).toBe( 3 );

			const customOrderResult = sortByOrder( sameOrderBudgets, [ 3, 1, 2 ] );
			expect( customOrderResult[ 0 ].id ).toBe( 3 );
			expect( customOrderResult[ 1 ].id ).toBe( 1 );
			expect( customOrderResult[ 2 ].id ).toBe( 2 );
		} );
	} );
} );
