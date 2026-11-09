import { getStories, getView } from './selectors';

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

describe( 'getStories', () => {
	const fields = [ { slug: 'last_modified', type: 'datetime', is_sortable: true } ];
	const stories = {
		1: { id: 1, last_modified: 100 },
		2: { id: 2, last_modified: 300 },
		3: { id: 3, last_modified: 200 },
	};

	it( 'sorts by the default view, most recently modified first, before any view is saved', () => {
		const state = { search: { stories: [] }, stories, fields, view: {} };
		expect( getStories( state ).map( story => story.id ) ).toEqual( [ 2, 3, 1 ] );
	} );

	it( 'follows a saved sort', () => {
		const state = { search: { stories: [] }, stories, fields, view: { sort: { field: 'last_modified', direction: 'asc' } } };
		expect( getStories( state ).map( story => story.id ) ).toEqual( [ 1, 3, 2 ] );
	} );
} );
