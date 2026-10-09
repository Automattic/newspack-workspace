/**
 * WordPress dependencies.
 */
import { select } from '@wordpress/data';

/**
 * Internal dependencies.
 */
import { WIZARD_STORE_NAMESPACE } from './';

describe( 'wizard store', () => {
	it( 'registers itself when the module is imported', () => {
		expect( select( WIZARD_STORE_NAMESPACE ) ).toBeDefined();
	} );
} );
