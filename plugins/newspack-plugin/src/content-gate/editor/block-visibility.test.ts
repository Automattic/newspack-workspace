/**
 * Tests for the block-visibility attribute registration filter and the
 * Inspector panel injection.
 */

/**
 * Capture callbacks registered via addFilter, keyed by namespace.
 */
const registeredFilters: Record< string, any > = {};

/**
 * The post type the editor reports, swapped per test.
 */
let mockPostType: string | undefined;

jest.mock( '@wordpress/hooks', () => ( {
	addFilter: jest.fn( ( _hook: string, namespace: string, callback: ( settings: any, name: string ) => any ) => {
		registeredFilters[ namespace ] = callback;
	} ),
} ) );

jest.mock( '@wordpress/compose', () => ( {
	createHigherOrderComponent: jest.fn( ( fn: any ) => fn ),
} ) );
jest.mock( '@wordpress/block-editor', () => ( { InspectorControls: () => null } ) );
jest.mock( '@wordpress/components', () => ( {} ) );
jest.mock( '@wordpress/i18n', () => ( { __: ( s: string ) => s } ) );
jest.mock( '@wordpress/element', () => ( {
	useState: jest.fn( ( v: any ) => [ v, jest.fn() ] ),
	useEffect: jest.fn(),
} ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/data', () => ( {
	useSelect: ( mapSelect: ( select: ( store: string ) => unknown ) => unknown ) =>
		mapSelect( () => ( { getCurrentPostType: () => mockPostType } ) ),
} ) );

// Importing the module triggers the addFilter side effects.
require( './block-visibility' );

const attributeFilter = registeredFilters[ 'newspack-plugin/block-visibility/attributes' ];
const inspectorFilter = registeredFilters[ 'newspack-plugin/block-visibility/inspector' ];

describe( 'block-visibility attribute registration', () => {
	it( 'adds attributes to core/group', () => {
		const result = attributeFilter( { attributes: {} }, 'core/group' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlVisibility' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlRules' );
	} );

	it( 'adds attributes to core/stack', () => {
		const result = attributeFilter( { attributes: {} }, 'core/stack' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlVisibility' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlRules' );
	} );

	it( 'adds attributes to core/row', () => {
		const result = attributeFilter( { attributes: {} }, 'core/row' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlVisibility' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlRules' );
	} );

	it( 'does not modify non-target blocks', () => {
		const settings = { attributes: { align: { type: 'string' } } };
		const result = attributeFilter( settings, 'core/paragraph' );
		expect( result ).toBe( settings );
	} );

	it( 'newspackAccessControlVisibility defaults to visible', () => {
		const result = attributeFilter( { attributes: {} }, 'core/group' );
		expect( result.attributes.newspackAccessControlVisibility.default ).toBe( 'visible' );
	} );

	it( 'newspackAccessControlRules defaults to empty object', () => {
		const result = attributeFilter( { attributes: {} }, 'core/group' );
		expect( result.attributes.newspackAccessControlRules.default ).toEqual( {} );
	} );

	it( 'preserves existing attributes on target blocks', () => {
		const result = attributeFilter( { attributes: { align: { type: 'string' } } }, 'core/group' );
		expect( result.attributes ).toHaveProperty( 'align' );
		expect( result.attributes ).toHaveProperty( 'newspackAccessControlVisibility' );
	} );
} );

describe( 'block-visibility inspector panel', () => {
	const BlockEdit = () => null;
	const render = ( name: string ) => inspectorFilter( BlockEdit )( { name } );

	// The panel is a sibling of <BlockEdit/> inside a fragment, so a bare
	// <BlockEdit/> element is the panel being withheld.
	const isPanelHidden = ( element: { type: unknown } ) => element.type === BlockEdit;

	it( 'adds the panel to a target block on a post', () => {
		mockPostType = 'post';
		expect( isPanelHidden( render( 'core/group' ) ) ).toBe( false );
	} );

	// Access rules are post context; a pattern is a design, not a post.
	it( 'withholds the panel while a pattern is being edited', () => {
		mockPostType = 'wp_block';
		expect( isPanelHidden( render( 'core/group' ) ) ).toBe( true );
	} );

	it( 'withholds the panel from non-target blocks', () => {
		mockPostType = 'post';
		expect( isPanelHidden( render( 'core/paragraph' ) ) ).toBe( true );
	} );

	// An editor that reports no post type is not a pattern editor.
	it( 'adds the panel when the post type is unknown', () => {
		mockPostType = undefined;
		expect( isPanelHidden( render( 'core/group' ) ) ).toBe( false );
	} );
} );
