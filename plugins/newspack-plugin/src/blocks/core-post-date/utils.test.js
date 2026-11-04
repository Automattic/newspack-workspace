/**
 * Internal dependencies
 */
import { isModifiedDate, getLabelParts } from './utils';

describe( 'isModifiedDate', () => {
	it( 'detects the modified date binding', () => {
		expect( isModifiedDate( { metadata: { bindings: { datetime: { source: 'core/post-data', args: { field: 'modified' } } } } } ) ).toBe( true );
	} );

	it( 'detects the legacy displayType attribute', () => {
		expect( isModifiedDate( { displayType: 'modified' } ) ).toBe( true );
	} );

	it( 'detects the modified date class', () => {
		expect( isModifiedDate( { className: 'is-style-foo wp-block-post-date__modified-date' } ) ).toBe( true );
	} );

	it( 'ignores publish dates', () => {
		expect( isModifiedDate( {} ) ).toBe( false );
		expect( isModifiedDate( { metadata: { bindings: { datetime: { source: 'core/post-data', args: { field: 'date' } } } } } ) ).toBe( false );
	} );
} );

describe( 'getLabelParts', () => {
	it( 'splits text before the date', () => {
		expect( getLabelParts( 'Updated %s' ) ).toEqual( { before: 'Updated ', after: '' } );
	} );

	it( 'splits text after the date', () => {
		expect( getLabelParts( '%s aktualisiert' ) ).toEqual( { before: '', after: ' aktualisiert' } );
	} );
} );
