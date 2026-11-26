/**
 * Internal dependencies
 */
import { columnShareWidth, evenColumns, titleColumnWidth, withoutColumnStyles } from './even-columns';

describe( 'evenColumns', () => {
	it( 'gives the title the same share as the other columns when they are few', () => {
		const view = { type: 'table', titleField: 'name', fields: [ 'status', 'plan' ] };
		expect( evenColumns( view ).layout.styles ).toEqual( {
			status: { width: '33.33%' },
			plan: { width: '33.33%' },
		} );
		expect( titleColumnWidth( view ) ).toBe( '33.33%' );
	} );

	it( 'keeps at least 30% for the title when there are many columns', () => {
		const view = { type: 'table', titleField: 'name', fields: [ 'status', 'plan', 'date' ] };
		expect( titleColumnWidth( view ) ).toBe( '30%' );
		expect( evenColumns( view ).layout.styles.status ).toEqual( { width: '23.33%' } );
	} );

	it( 'follows the columns the viewer has switched on', () => {
		const view = evenColumns( { type: 'table', titleField: 'name', fields: [ 'status', 'plan', 'date', 'author' ] } );
		expect( view.layout.styles.status ).toEqual( { width: '17.5%' } );
		expect( Object.keys( view.layout.styles ) ).toEqual( [ 'status', 'plan', 'date', 'author' ] );
	} );

	it( 'splits every column evenly when the title is hidden', () => {
		const view = { type: 'table', titleField: 'name', showTitle: false, fields: [ 'status', 'plan' ] };
		expect( evenColumns( view ).layout.styles ).toEqual( { status: { width: '50%' }, plan: { width: '50%' } } );
		expect( titleColumnWidth( view ) ).toBeUndefined();
	} );

	it( 'keeps the rest of the layout', () => {
		const view = evenColumns( { type: 'table', titleField: 'name', fields: [ 'status' ], layout: { density: 'compact' } } );
		expect( view.layout.density ).toBe( 'compact' );
	} );

	it( 'leaves a grid alone', () => {
		const grid = { type: 'grid', titleField: 'name', fields: [ 'status' ] };
		expect( evenColumns( grid ) ).toBe( grid );
		expect( evenColumns( grid, [ 'status' ] ) ).toBe( grid );
	} );

	it( 'shrinks fitted columns to their content and splits the rest between the others', () => {
		const view = { type: 'table', titleField: 'name', fields: [ 'status', 'price', 'count', 'unlocks' ] };
		expect( evenColumns( view, [ 'status', 'count' ] ).layout.styles ).toEqual( {
			status: { width: '1%' },
			price: { width: '33.33%' },
			count: { width: '1%' },
			unlocks: { width: '33.33%' },
		} );
		expect( titleColumnWidth( view, [ 'status', 'count' ] ) ).toBe( '33.33%' );
	} );

	it( 'never gives the title less than another column when columns are fitted', () => {
		const view = { type: 'table', titleField: 'name', fields: [ 'status', 'price', 'count', 'unlocks', 'rules', 'effective' ] };
		const fit = [ 'status', 'count' ];
		expect( titleColumnWidth( view, fit ) ).toBe( '30%' );
		expect( evenColumns( view, fit ).layout.styles.price ).toEqual( { width: '17.5%' } );
	} );

	it( 'ignores a fitted column the viewer has switched off', () => {
		const view = { type: 'table', titleField: 'name', fields: [ 'price', 'unlocks' ] };
		expect( evenColumns( view, [ 'status' ] ).layout.styles ).toEqual( {
			price: { width: '33.33%' },
			unlocks: { width: '33.33%' },
		} );
	} );

	it( 'leaves the title its natural width when every other column is fitted', () => {
		const view = { type: 'table', titleField: 'name', fields: [ 'status', 'count' ] };
		expect( evenColumns( view, [ 'status', 'count' ] ).layout.styles ).toEqual( {
			status: { width: '1%' },
			count: { width: '1%' },
		} );
		expect( titleColumnWidth( view, [ 'status', 'count' ] ) ).toBeUndefined();
	} );
} );

describe( 'columnShareWidth', () => {
	it( 'gives each other column its share of the container', () => {
		expect( columnShareWidth( { type: 'table', titleField: 'name', fields: [ 'status', 'plan', 'date', 'author' ] } ) ).toBe( '17.5cqi' );
	} );

	it( 'splits the whole container when the title is hidden', () => {
		expect( columnShareWidth( { type: 'table', titleField: 'name', showTitle: false, fields: [ 'status', 'plan' ] } ) ).toBe( '50cqi' );
	} );

	it( 'has no width without other columns, or for a grid', () => {
		expect( columnShareWidth( { type: 'table', titleField: 'name', fields: [ 'name' ] } ) ).toBeUndefined();
		expect( columnShareWidth( { type: 'grid', titleField: 'name', fields: [ 'status' ] } ) ).toBeUndefined();
	} );
} );

describe( 'withoutColumnStyles', () => {
	it( 'drops the widths and keeps the rest of the layout', () => {
		const view = withoutColumnStyles( { type: 'table', layout: { density: 'compact', styles: { name: { width: '30%' } } } } );
		expect( view.layout ).toEqual( { density: 'compact' } );
	} );

	it( 'returns a view without widths untouched', () => {
		const view = { type: 'table', layout: {} };
		expect( withoutColumnStyles( view ) ).toBe( view );
	} );
} );
