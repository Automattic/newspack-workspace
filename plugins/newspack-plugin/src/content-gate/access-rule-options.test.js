/**
 * Internal dependencies
 */
import {
	findAccessRuleOption,
	formatAccessRuleOptionLabel,
	formatMissingAccessRuleOptionLabel,
	getAccessRuleOptionTokens,
	getAccessRuleTokenFieldMessages,
	getMissingOptionLabel,
	hasUnlistedAccessRuleValues,
	isAccessRuleOptionInput,
	resolveAccessRuleOptionTokens,
} from './access-rule-options';

/**
 * Three products sharing a display name, as sites end up with when a name like "Annual"
 * is reused across legacy and current product tiers, plus one whose own name ends in
 * something shaped like the token's ID suffix.
 */
const OPTIONS = [
	{ value: 188250, label: 'Annual' },
	{ value: 200014, label: 'Annual' },
	{ value: 205482, label: 'Annual' },
	{ value: 300000, label: 'Monthly' },
	{ value: 400000, label: 'Legacy (#12)' },
];

const MISSING_PRODUCT = getMissingOptionLabel( 'subscription' );

/**
 * The rule these options belong to. "Active subscription" matches more than its option
 * list holds — a variation ID, for one — which is what admits an ID the list cannot
 * describe.
 */
const SUBSCRIPTION = { slug: 'subscription' };

describe( 'formatAccessRuleOptionLabel', () => {
	it( 'appends the option ID as secondary data after the name', () => {
		expect( formatAccessRuleOptionLabel( { value: 188250, label: 'Annual' } ) ).toBe( 'Annual (#188250)' );
	} );

	it( 'decodes HTML entities in the name', () => {
		expect( formatAccessRuleOptionLabel( { value: 42, label: 'Founder&#8217;s Club &amp; Friends' } ) ).toBe( 'Founder’s Club & Friends (#42)' );
	} );
} );

describe( 'getMissingOptionLabel', () => {
	// "not listed" rather than "deleted": an option list holds parent products and
	// published institutions, while evaluation also resolves variation IDs, so a value
	// missing from the list is often still granting access.
	it( 'names the right kind of thing per rule', () => {
		expect( getMissingOptionLabel( 'subscription' ) ).toBe( '(product not listed)' );
		expect( getMissingOptionLabel( 'institution' ) ).toBe( '(institution not listed)' );
		expect( getMissingOptionLabel( 'gate' ) ).toBe( '(gate not listed)' );
	} );

	it( 'falls back to slug-agnostic wording, since rules can be registered by anyone', () => {
		expect( getMissingOptionLabel( 'something-else' ) ).toBe( '(not listed)' );
	} );
} );

describe( 'findAccessRuleOption', () => {
	it( 'matches whether the stored value is a string or a number', () => {
		expect( findAccessRuleOption( OPTIONS, '300000' ) ).toEqual( { value: 300000, label: 'Monthly' } );
		expect( findAccessRuleOption( OPTIONS, 300000 ) ).toEqual( { value: 300000, label: 'Monthly' } );
	} );

	it( 'returns undefined when nothing matches', () => {
		expect( findAccessRuleOption( OPTIONS, 999999 ) ).toBeUndefined();
	} );
} );

describe( 'getAccessRuleOptionTokens', () => {
	it( 'renders one distinct token per selected option, including same-named ones', () => {
		expect( getAccessRuleOptionTokens( OPTIONS, [ 188250, 205482 ], MISSING_PRODUCT ) ).toEqual( [ 'Annual (#188250)', 'Annual (#205482)' ] );
	} );

	it( 'preserves the stored order rather than the option list order', () => {
		expect( getAccessRuleOptionTokens( OPTIONS, [ 300000, 188250 ], MISSING_PRODUCT ) ).toEqual( [ 'Monthly (#300000)', 'Annual (#188250)' ] );
	} );

	it( 'shows a value whose option is gone instead of hiding it', () => {
		// A product that was deleted or stopped being a subscription still gates readers,
		// so the publisher has to be able to see that the rule holds it.
		expect( getAccessRuleOptionTokens( OPTIONS, [ 188250, 999999 ], MISSING_PRODUCT ) ).toEqual( [
			'Annual (#188250)',
			'(product not listed) (#999999)',
		] );
	} );

	it( 'returns nothing for a non-array value', () => {
		expect( getAccessRuleOptionTokens( OPTIONS, 'not-an-array', MISSING_PRODUCT ) ).toEqual( [] );
	} );
} );

describe( 'resolveAccessRuleOptionTokens', () => {
	it( 'resolves each token to exactly its own option, not every option sharing its name', () => {
		// This is the removal case: three "Annual" products were selected and one token
		// was removed. Matching by name alone re-selected all three.
		expect( resolveAccessRuleOptionTokens( [ 'Annual (#188250)', 'Annual (#205482)' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 188250, 205482 ] );
	} );

	it( 'reads the trailing ID, not one embedded in the name', () => {
		// The exact-label match has to be tried before the suffix pattern, or an option
		// whose name ends in "(#12)" would resolve to whatever product 12 happens to be.
		expect( resolveAccessRuleOptionTokens( [ 'Legacy (#12) (#400000)' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 400000 ] );
		expect( resolveAccessRuleOptionTokens( [ '400000' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 400000 ] );
	} );

	it( 'resolves a token typed as a bare product ID', () => {
		expect( resolveAccessRuleOptionTokens( [ '200014' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 200014 ] );
	} );

	it( 'accepts the object token shape FormTokenField can emit', () => {
		expect( resolveAccessRuleOptionTokens( [ { value: 'Monthly (#300000)' } ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 300000 ] );
	} );

	it( 'drops tokens that match no option', () => {
		expect( resolveAccessRuleOptionTokens( [ 'Annual', 'Annual (#188250)' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 188250 ] );
	} );

	it( 'keeps an already-stored value whose option is gone', () => {
		// Editing an unrelated rule must not quietly delete a product the option list can
		// no longer describe.
		const tokens = [ 'Annual (#188250)', formatMissingAccessRuleOptionLabel( 999999, MISSING_PRODUCT ) ];
		expect( resolveAccessRuleOptionTokens( tokens, OPTIONS, { slug: 'subscription', stored: [ 188250, 999999 ] } ) ).toEqual( [
			188250, 999999,
		] );
	} );

	it( 'accepts a bare ID no option describes, so a removed variation can be typed back', () => {
		// Evaluation resolves more than the option list holds: an "Active subscription"
		// rule matches a variation ID. Rejecting it would make the caution's advice
		// impossible to act on.
		expect( resolveAccessRuleOptionTokens( [ '999999' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 999999 ] );
	} );

	it( 'does not invent a value from a full token that was neither an option nor stored', () => {
		// Only a bare ID adds an unlisted value. A `<name> (#<id>)` token resolves from
		// the rule's own values, so pasting one from elsewhere adds nothing.
		const token = formatMissingAccessRuleOptionLabel( 999999, MISSING_PRODUCT );
		expect( resolveAccessRuleOptionTokens( [ token ], OPTIONS, { slug: 'subscription', stored: [ 188250 ] } ) ).toEqual( [] );
	} );

	it( 'refuses an ID no option describes for a rule whose list is the whole story', () => {
		// `available_gates` is the published gates and `has_active_gates()` tests for the
		// same status, so an ID outside the list matches no gate — and a block whose
		// gates all match nothing is shown to everyone. A gate the list does describe
		// still resolves from its ID, and one already stored is still preserved.
		const gates = [ { value: 12, label: 'Members only' } ];
		expect( resolveAccessRuleOptionTokens( [ '999999' ], gates, { slug: 'gate' } ) ).toEqual( [] );
		expect( resolveAccessRuleOptionTokens( [ '12' ], gates, { slug: 'gate' } ) ).toEqual( [ 12 ] );
		expect( resolveAccessRuleOptionTokens( [ '(gate not listed) (#99)' ], gates, { slug: 'gate', stored: [ 99 ] } ) ).toEqual( [ 99 ] );
	} );

	it( 'refuses a zero, which the server would drop from the saved list anyway', () => {
		// `sanitize_access_rule` filters falsy values out, so a `0` token would be
		// accepted here and gone on the next reload with nothing said.
		expect( resolveAccessRuleOptionTokens( [ '0' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [] );
	} );

	it( 'deduplicates tokens resolving to the same option', () => {
		expect( resolveAccessRuleOptionTokens( [ 'Annual (#188250)', '188250' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 188250 ] );
	} );

	it( 'preserves token order', () => {
		expect( resolveAccessRuleOptionTokens( [ 'Monthly (#300000)', 'Annual (#188250)' ], OPTIONS, SUBSCRIPTION ) ).toEqual( [ 300000, 188250 ] );
	} );
} );

describe( 'isAccessRuleOptionInput', () => {
	it( 'accepts a full token and any bare ID, and rejects free text', () => {
		expect( isAccessRuleOptionInput( 'Annual (#188250)', OPTIONS, 'subscription' ) ).toBe( true );
		expect( isAccessRuleOptionInput( '188250', OPTIONS, 'subscription' ) ).toBe( true );
		// The message says "type its ID", and the IDs the caution points publishers at
		// are precisely the ones no option describes.
		expect( isAccessRuleOptionInput( '999999', OPTIONS, 'subscription' ) ).toBe( true );
		expect( isAccessRuleOptionInput( 'Annual', OPTIONS, 'subscription' ) ).toBe( false );
	} );

	it( 'takes only listed IDs for a gate, so a typo cannot become a gate that matches nobody', () => {
		const gates = [ { value: 12, label: 'Members only' } ];
		expect( isAccessRuleOptionInput( '12', gates, 'gate' ) ).toBe( true );
		expect( isAccessRuleOptionInput( '999999', gates, 'gate' ) ).toBe( false );
	} );
} );

describe( 'hasUnlistedAccessRuleValues', () => {
	it( 'reports whether the rule holds a value no option describes', () => {
		expect( hasUnlistedAccessRuleValues( OPTIONS, [ 188250, 300000 ] ) ).toBe( false );
		// A subscription variation ID, for instance: not in the option list, still granting.
		expect( hasUnlistedAccessRuleValues( OPTIONS, [ 188250, 999999 ] ) ).toBe( true );
		expect( hasUnlistedAccessRuleValues( OPTIONS, 'not-an-array' ) ).toBe( false );
	} );
} );

describe( 'getAccessRuleTokenFieldMessages', () => {
	it( 'carries every key FormTokenField reads, since it takes the object whole', () => {
		// Omitting one drops that announcement rather than falling back to the default.
		expect( Object.keys( getAccessRuleTokenFieldMessages( 'subscription' ) ).sort() ).toEqual( [
			'__experimentalInvalid',
			'added',
			'remove',
			'removed',
		] );
	} );

	it( 'says what the field wants instead of the default "Invalid item"', () => {
		expect( getAccessRuleTokenFieldMessages( 'subscription' ).__experimentalInvalid ).toMatch( /product.+type its ID/ );
		// No "type its ID" for a gate: the list is every gate there is, so an ID outside
		// it is refused and offering it would be a promise the field does not keep.
		expect( getAccessRuleTokenFieldMessages( 'gate' ).__experimentalInvalid ).toBe( 'Not a selectable gate. Pick one from the list.' );
	} );
} );
