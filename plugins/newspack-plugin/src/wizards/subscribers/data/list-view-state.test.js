/**
 * Internal dependencies
 */
import { getSavedListView, resetSavedListView, saveListView } from './list-view-state';

describe( 'the saved subscriber list view', () => {
	beforeEach( () => {
		resetSavedListView();
	} );

	it( 'returns the last view saved, until reset', () => {
		const view = { type: 'table', page: 3, search: 'reader' };
		saveListView( { type: 'table', page: 2 } );
		saveListView( view );

		expect( getSavedListView() ).toEqual( view );

		resetSavedListView();

		expect( getSavedListView() ).toBeNull();
	} );
} );
