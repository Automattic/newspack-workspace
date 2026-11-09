import { getView } from './selectors';

describe( 'getView', () => {
	it( 'shows the story name as the title and the description under it', () => {
		const view = getView( { view: {} } );
		expect( view.titleField ).toBe( 'name' );
		expect( view.descriptionField ).toBe( 'description' );
	} );

	it( 'drops the title and description from the columns of a saved view', () => {
		const view = getView( { view: { fields: [ 'name', 'budgets', 'description', 'status' ] } } );
		expect( view.fields ).toEqual( [ 'budgets', 'status' ] );
	} );

	it( 'keeps a column for the description when a saved view has no description field', () => {
		const view = getView( { view: { descriptionField: undefined, fields: [ 'name', 'description' ] } } );
		expect( view.fields ).toEqual( [ 'description' ] );
	} );
} );
