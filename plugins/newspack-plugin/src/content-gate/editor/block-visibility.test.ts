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
// Named stubs rather than an empty module: the picker tests below identify which
// control the value branch returned by the element's type.
jest.mock( '@wordpress/components', () => ( {
	FormTokenField: function FormTokenField() {
		return null;
	},
	TextControl: function TextControl() {
		return null;
	},
} ) );
jest.mock( '@wordpress/i18n', () => ( {
	__: ( s: string ) => s,
	sprintf: ( s: string, ...args: unknown[] ) => s.replace( /%s/, String( args[ 0 ] ) ),
} ) );
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
const { AccessRuleValueControl } = require( './block-visibility' );
const { FormTokenField, TextControl } = require( '@wordpress/components' );

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

/**
 * The picker branches on the rule's `has_options` declaration, never on how many
 * options happen to be loaded. Branching on the loaded list is what let a gate on
 * a site with no institutions published render a free-text box, so an editor
 * could type a name into a rule whose value must be an array of institution IDs —
 * and the resulting value granted access to everyone (NPPD-2143). The wizard's
 * picker has the same branch and its own test.
 */
describe( 'block-visibility access rule value control', () => {
	const renderControl = ( slug: string, config: any, value: any ) => AccessRuleValueControl( { slug, config, value, onChange: () => {} } );
	const childrenOf = ( element: any ): any[] =>
		( Array.isArray( element.props.children ) ? element.props.children : [ element.props.children ] ).filter( Boolean );
	// The rule's control is returned inside a fragment that also carries the caution for a
	// stale option list, so the control itself is one level in.
	const controlOf = ( element: any ) => childrenOf( element )[ 0 ];
	// The picker is wrapped in the component that renders the caution about its stored
	// value and ties the two together for a screen reader, so the field is its child and
	// the caution is the prop it was handed.
	const pickerOf = ( element: any ) => childrenOf( controlOf( element ) )[ 0 ].props.children;
	const valueNoticeOf = ( element: any ) => childrenOf( controlOf( element ) )[ 0 ].props.notice;

	it( 'gives an options-backed rule the picker even when its option list is empty', () => {
		const control = renderControl( 'institution', { name: 'Institutional access', has_options: true, options: [] }, [] );

		expect( pickerOf( control ).type ).toBe( FormTokenField );
	} );

	it( 'gives a rule that declares no options source the free-text box', () => {
		const control = renderControl( 'email_domain', { name: 'Whitelisted email domain', has_options: false, options: [] }, '' );

		expect( controlOf( control ).type ).toBe( TextControl );
	} );

	it( 'takes the picker out of play when the rule needs a value it cannot offer', () => {
		// The seeded default is an empty array, so an interactive picker with no
		// options offers exactly one expressible answer, and it is the one that leaves
		// the rule describing nobody.
		const control = renderControl( 'institution', { name: 'Institutional access', has_options: true, requires_value: true, options: [] }, [] );

		expect( pickerOf( control ).props.disabled ).toBe( true );
	} );

	it( 'leaves the picker usable for a rule that still constrains when empty', () => {
		// `subscription` naming no product requires any active subscription, so an
		// empty value is a configuration a publisher chooses — not the gate opening.
		const control = renderControl( 'subscription', { name: 'Active subscription', has_options: true, options: [] }, [] );

		expect( pickerOf( control ).props.disabled ).toBe( false );
	} );

	it( 'names a stored value the picker cannot show', () => {
		// A legacy string denies every reader, but holds no token — so without the
		// notice the control reads as an empty selection, and an editor who leaves
		// it that way saves `[]` over a value that was failing closed.
		const control = renderControl(
			'institution',
			{
				name: 'Institutional access',
				has_options: true,
				requires_value: true,
				options: [ { value: 1, label: 'Springfield University' } ],
			},
			'Shelbyville University'
		);

		expect( valueNoticeOf( control ) ).toContain( 'Shelbyville University' );
		expect( valueNoticeOf( control ) ).toContain( 'grants no access' );
	} );
} );
