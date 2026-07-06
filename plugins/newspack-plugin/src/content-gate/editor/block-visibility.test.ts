/**
 * Tests for block-visibility attribute registration filter.
 */

/**
 * Shape of a single block attribute schema entry, as registered via the
 * `blocks.registerBlockType` filter (mirrors `@wordpress/blocks` attribute definitions).
 */
type RegisteredAttributeSchema = {
	type: string;
	default?: unknown;
	items?: { type: string };
};

/**
 * Capture callbacks registered via addFilter, keyed by namespace.
 */
const registeredFilters: Record< string, ( settings: BlockSettings, name: string ) => BlockSettings > = {};

jest.mock( '@wordpress/hooks', () => ( {
	addFilter: jest.fn( ( _hook: string, namespace: string, callback: ( settings: BlockSettings, name: string ) => BlockSettings ) => {
		registeredFilters[ namespace ] = callback;
	} ),
} ) );

jest.mock( '@wordpress/compose', () => ( {
	createHigherOrderComponent: jest.fn( ( fn: unknown ) => fn ),
} ) );
jest.mock( '@wordpress/block-editor', () => ( { InspectorControls: () => null } ) );
jest.mock( '@wordpress/components', () => ( {} ) );
jest.mock( '@wordpress/i18n', () => ( { __: ( s: string ) => s } ) );
jest.mock( '@wordpress/element', () => ( {
	useState: jest.fn( ( v: unknown ) => [ v, jest.fn() ] ),
	useEffect: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// Importing the module triggers the addFilter side effects.
require( './block-visibility' );

const attributeFilter = registeredFilters[ 'newspack-plugin/block-visibility/attributes' ];

describe( 'block-visibility attribute registration', () => {
	it( 'adds attributes to core/group', () => {
		const result = attributeFilter( { attributes: {}, name: 'core/group' }, 'core/group' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlVisibility' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlRules' );
	} );

	it( 'adds attributes to core/stack', () => {
		const result = attributeFilter( { attributes: {}, name: 'core/stack' }, 'core/stack' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlVisibility' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlRules' );
	} );

	it( 'adds attributes to core/row', () => {
		const result = attributeFilter( { attributes: {}, name: 'core/row' }, 'core/row' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlVisibility' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlRules' );
	} );

	it( 'does not modify non-target blocks', () => {
		const settings = { attributes: { align: { type: 'string' } }, name: 'core/paragraph' };
		const result = attributeFilter( settings, 'core/paragraph' );
		expect( result ).toBe( settings );
	} );

	it( 'newspackAccessControlVisibility defaults to visible', () => {
		const result = attributeFilter( { attributes: {}, name: 'core/group' }, 'core/group' );
		const attributes = result.attributes as Record< string, RegisteredAttributeSchema >;
		expect( attributes.newspackAccessControlVisibility.default ).toBe( 'visible' );
	} );

	it( 'newspackAccessControlRules defaults to empty object', () => {
		const result = attributeFilter( { attributes: {}, name: 'core/group' }, 'core/group' );
		const attributes = result.attributes as Record< string, RegisteredAttributeSchema >;
		expect( attributes.newspackAccessControlRules.default ).toEqual( {} );
	} );

	it( 'preserves existing attributes on target blocks', () => {
		const result = attributeFilter( { attributes: { align: { type: 'string' } }, name: 'core/group' }, 'core/group' );
		expect( result.attributes ).toHaveProperty( 'align' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlVisibility' );
	} );
} );
