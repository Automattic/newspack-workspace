/**
 * Tests for the content-rule token field's value handling.
 *
 * A rule's stored IDs arrive as whatever the writer saved (the editor saves
 * strings, PHP callers such as the migration CLI save integers), and the lookup
 * that names them can lag or miss. Neither may hide or drop a restriction.
 */

/**
 * Internal dependencies.
 */
import { mergeTokenSelection, selectedTokenLabels } from './content-rule-tokens';

const items = [
	{ value: '12155', label: '12155: Event Calendar (Page)' },
	{ value: '54329', label: '54329: This Weekend (Newsletter)' },
	{ value: '777', label: '777: Some suggestion (Post)' },
];

describe( 'selectedTokenLabels', () => {
	it( 'shows a token for an ID stored as an integer, not only as a string', () => {
		expect( selectedTokenLabels( [ 12155, '54329' ], items ) ).toEqual( [ '12155: Event Calendar (Page)', '54329: This Weekend (Newsletter)' ] );
	} );
} );

describe( 'mergeTokenSelection', () => {
	it( 'keeps an ID the lookup could not name when the user edits other tokens', () => {
		// 999 is saved on the gate but absent from items (private post, lookup
		// not loaded yet, or a transient error). Removing the calendar token
		// must not silently drop 999 as well.
		const next = mergeTokenSelection( [ 12155, 999, '54329' ], items, [ '54329: This Weekend (Newsletter)' ] );
		expect( next ).toEqual( [ '999', '54329' ] );
	} );

	it( 'removes a displayed token the user deleted and appends a newly picked one', () => {
		const next = mergeTokenSelection( [ '12155', '54329' ], items, [ '12155: Event Calendar (Page)', { value: '777: Some suggestion (Post)' } ] );
		expect( next ).toEqual( [ '12155', '777' ] );
	} );
} );
