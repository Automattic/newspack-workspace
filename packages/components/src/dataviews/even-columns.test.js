/**
 * Internal dependencies
 */
import { evenColumns, withoutColumnStyles } from './even-columns';

describe( 'evenColumns', () => {
	it( 'splits 70% evenly between the columns beside the title', () => {
		const view = evenColumns( { type: 'table', titleField: 'name', fields: [ 'status', 'plan' ] } );
		expect( view.layout.styles ).toEqual( {
			status: { width: '35%' },
			plan: { width: '35%' },
		} );
	} );

	it( 'follows the columns the viewer has switched on', () => {
		const view = evenColumns( { type: 'table', titleField: 'name', fields: [ 'status', 'plan', 'date', 'author' ] } );
		expect( view.layout.styles.status ).toEqual( { width: '17.5%' } );
		expect( Object.keys( view.layout.styles ) ).toEqual( [ 'status', 'plan', 'date', 'author' ] );
	} );

	it( 'splits every column evenly when the title is hidden', () => {
		const view = evenColumns( { type: 'table', titleField: 'name', showTitle: false, fields: [ 'status', 'plan' ] } );
		expect( view.layout.styles ).toEqual( { status: { width: '50%' }, plan: { width: '50%' } } );
	} );

	it( 'keeps the rest of the layout', () => {
		const view = evenColumns( { type: 'table', titleField: 'name', fields: [ 'status' ], layout: { density: 'compact' } } );
		expect( view.layout.density ).toBe( 'compact' );
	} );

	it( 'leaves a grid alone', () => {
		const grid = { type: 'grid', titleField: 'name', fields: [ 'status' ] };
		expect( evenColumns( grid ) ).toBe( grid );
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
