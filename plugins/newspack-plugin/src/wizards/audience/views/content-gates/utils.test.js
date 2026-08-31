/**
 * Internal dependencies
 */
import {
	getGateStatusBadgeIntent,
	getMeteringCount,
	getMeteringDescription,
	hasOwnMeter,
	hasSharedMeteredPath,
	isGateMetered,
	isMalformedAccessRuleValue,
	isUnconstrainedAccessRuleValue,
	sharesTheSiteMeter,
} from './utils';

/**
 * `isGateMetered` decides whether the wizard offers metering-dependent features (the
 * Metered Countdown card). It has to agree with `Newspack\Metering::is_gate_metered()`,
 * which is what decides whether those features actually render on the frontend - a gate
 * that meters 0 free views gates every reader immediately, so there is nothing to count
 * down (NPPD-2056).
 *
 * Since NPPD-2191 the count can come from the shared site meter instead of the gate,
 * so the per-gate cases below pin `scope: 'gate'` and the shared cases pass a site
 * meter in.
 */
const buildGate = ( { registration = {}, custom_access: customAccess = {} } = {} ) => ( {
	id: 1,
	registration: {
		active: false,
		metering: { enabled: false, count: 0, period: 'month', scope: 'gate' },
		...registration,
	},
	custom_access: {
		active: false,
		metering: { enabled: false, count: 0, period: 'month', scope: 'gate' },
		...customAccess,
	},
} );

describe( 'isGateMetered', () => {
	it( 'is true when an active section meters a positive number of views', () => {
		const gate = buildGate( { registration: { active: true, metering: { enabled: true, count: 3, period: 'month', scope: 'gate' } } } );

		expect( isGateMetered( gate ) ).toBe( true );
	} );

	it( 'is false when metering is on but grants 0 free views', () => {
		const gate = buildGate( { registration: { active: true, metering: { enabled: true, count: 0, period: 'month', scope: 'gate' } } } );

		expect( isGateMetered( gate ) ).toBe( false );
	} );

	it( 'is false when the section holding the metering settings is inactive', () => {
		const gate = buildGate( { custom_access: { active: false, metering: { enabled: true, count: 3, period: 'month', scope: 'gate' } } } );

		expect( isGateMetered( gate ) ).toBe( false );
	} );

	it( 'judges each audience on its own settings rather than combining them', () => {
		// Anonymous readers keep a leftover count with metering switched off; registered
		// readers meter, but with no free views to give. Neither section meters, so
		// neither may borrow the other's half of the answer.
		const gate = buildGate( {
			registration: { active: true, metering: { enabled: false, count: 3, period: 'month', scope: 'gate' } },
			custom_access: { active: true, metering: { enabled: true, count: 0, period: 'month', scope: 'gate' } },
		} );

		expect( isGateMetered( gate ) ).toBe( false );
	} );
} );

describe( 'getGateStatusBadgeIntent', () => {
	it( 'reads an inactive gate as a draft rather than a settled off state', () => {
		// A gate that is not published is an unsaved draft post, so it takes `draft`
		// rather than the `none` a deliberate "off" would use.
		expect( getGateStatusBadgeIntent( 'draft' ) ).toBe( 'draft' );
		expect( getGateStatusBadgeIntent( 'pending' ) ).toBe( 'draft' );
	} );

	it( 'reads a published gate as live', () => {
		expect( getGateStatusBadgeIntent( 'publish' ) ).toBe( 'stable' );
	} );
} );

/**
 * A stored value in the wrong shape for its rule denies every reader, because
 * `Newspack\Access_Rules::evaluate_rule()` fails closed on it. Both the editor and
 * the gate summary label such a value; neither may render it as a live condition
 * or as an empty selection (NPPD-2143).
 */
describe( 'isMalformedAccessRuleValue', () => {
	const optionsBacked = { name: 'Institutional access', has_options: true };
	const freeText = { name: 'Whitelisted email domain', has_options: false };

	it( 'reads free text on an options-backed rule as malformed', () => {
		expect( isMalformedAccessRuleValue( optionsBacked, 'Springfield University' ) ).toBe( true );
		expect( isMalformedAccessRuleValue( optionsBacked, [ 12 ] ) ).toBe( false );
	} );

	it( 'reads a list on a free-text rule as malformed', () => {
		expect( isMalformedAccessRuleValue( freeText, [ 'example.com' ] ) ).toBe( true );
		expect( isMalformedAccessRuleValue( freeText, 'example.com' ) ).toBe( false );
	} );

	it( 'reads an unset value as not configured rather than malformed', () => {
		// An unset value is not a shape the rule can't use: `Institution::evaluate()`
		// reads it as "not configured" and grants every reader, so calling it
		// malformed would tell the operator the rule denies everyone at the moment
		// it grants everyone.
		expect( isMalformedAccessRuleValue( optionsBacked, '' ) ).toBe( false );
		expect( isMalformedAccessRuleValue( optionsBacked, null ) ).toBe( false );
	} );

	it( 'leaves boolean rules alone, which carry no value to judge', () => {
		expect( isMalformedAccessRuleValue( { name: 'Has donated', is_boolean: true, has_options: false }, true ) ).toBe( false );
	} );
} );

describe( 'isGateMetered with a shared site meter', () => {
	const siteMeter = { anonymous_count: 3, registered_count: 0, period: 'month' };

	it( 'reads the allowance off the site meter, not a stale count left on the gate', () => {
		const gate = buildGate( { registration: { active: true, metering: { enabled: true, count: 0, period: 'month' } } } );

		expect( isGateMetered( gate, siteMeter ) ).toBe( true );
	} );

	it( 'is false when the site meter grants the audience 0 free views', () => {
		const gate = buildGate( {
			registration: { active: true, metering: { enabled: false, count: 0, period: 'month' } },
			custom_access: { active: true, metering: { enabled: true, count: 9, period: 'month' } },
		} );

		expect( isGateMetered( gate, siteMeter ) ).toBe( false );
	} );

	it( 'meters signed-out readers through the paywall when there is no registration wall', () => {
		const gate = buildGate( { custom_access: { active: true, metering: { enabled: true, count: 9, period: 'month' } } } );

		expect( isGateMetered( gate, siteMeter ) ).toBe( true );
	} );

	it( 'keeps reading the gate when it opted out of the site meter', () => {
		const gate = buildGate( {
			custom_access: { active: true, metering: { enabled: true, count: 9, period: 'month', scope: 'gate' } },
		} );

		expect( isGateMetered( gate, siteMeter ) ).toBe( true );
	} );
} );

describe( 'getMeteringCount', () => {
	it( 'reads the site meter for a path that shares the allowance', () => {
		expect( getMeteringCount( { enabled: true, count: 9, period: 'month', scope: 'site' }, 3 ) ).toBe( 3 );
	} );

	it( 'reads the gate for a path keeping its own allowance', () => {
		expect( getMeteringCount( { enabled: true, count: 9, period: 'month', scope: 'gate' }, 3 ) ).toBe( 9 );
	} );

	it( 'grants nothing where the shared allowance is unknown, rather than guessing one', () => {
		expect( getMeteringCount( { enabled: true, count: 9, period: 'month', scope: 'site' } ) ).toBe( 0 );
	} );
} );

/**
 * The state the ticket is about: a rule that imposes no constraint lets every
 * reader through, so the wizard has to say so rather than render a blank
 * condition (NPPD-2143).
 */
describe( 'isUnconstrainedAccessRuleValue', () => {
	const institution = { name: 'Institutional access', has_options: true, empty_grants_access: true };
	const emailDomain = { name: 'Whitelisted email domain', has_options: false, empty_grants_access: true };
	const subscription = { name: 'Active subscription', has_options: true };

	it( 'reads the empty value of a rule that grants everyone when empty', () => {
		expect( isUnconstrainedAccessRuleValue( institution, [] ) ).toBe( true );
		expect( isUnconstrainedAccessRuleValue( institution, '' ) ).toBe( true );
		expect( isUnconstrainedAccessRuleValue( emailDomain, '' ) ).toBe( true );
	} );

	it( 'leaves a populated rule alone', () => {
		expect( isUnconstrainedAccessRuleValue( institution, [ 12 ] ) ).toBe( false );
		expect( isUnconstrainedAccessRuleValue( emailDomain, 'example.com' ) ).toBe( false );
	} );

	it( 'is not about emptiness alone: a rule that still constrains when empty is a configuration', () => {
		// `subscription` naming no product requires any active subscription.
		expect( isUnconstrainedAccessRuleValue( subscription, [] ) ).toBe( false );
	} );
} );

/**
 * The two warnings on the Metering page are the only thing telling a publisher which
 * gates their edit governs. `hasOwnMeter` and `hasSharedMeteredPath` are deliberately
 * not complements: adoption pins per path, so one gate can satisfy both.
 */
describe( 'hasOwnMeter and hasSharedMeteredPath', () => {
	const siteMeter = { anonymous_count: 3, registered_count: 3, period: 'month' };
	const metered = ( scope, count = 5 ) => ( { active: true, metering: { enabled: true, count, period: 'month', scope } } );

	it( 'reports a gate with every path pinned as keeping its own allowance only', () => {
		const gate = buildGate( { registration: metered( 'gate' ), custom_access: metered( 'gate' ) } );

		expect( hasOwnMeter( gate ) ).toBe( true );
		expect( hasSharedMeteredPath( gate, siteMeter ) ).toBe( false );
	} );

	it( 'reports a gate with every path sharing as drawing on the allowance only', () => {
		const gate = buildGate( { registration: metered( 'site' ), custom_access: metered( 'site' ) } );

		expect( hasOwnMeter( gate ) ).toBe( false );
		expect( hasSharedMeteredPath( gate, siteMeter ) ).toBe( true );
	} );

	it( 'reports a gate with one path of each as both', () => {
		const gate = buildGate( { registration: metered( 'gate' ), custom_access: metered( 'site' ) } );

		expect( hasOwnMeter( gate ) ).toBe( true );
		expect( hasSharedMeteredPath( gate, siteMeter ) ).toBe( true );
	} );

	it( 'ignores a path that is inactive, whatever it left behind', () => {
		const gate = buildGate( {
			registration: { ...metered( 'gate' ), active: false },
			custom_access: { ...metered( 'site' ), active: false },
		} );

		expect( hasOwnMeter( gate ) ).toBe( false );
		expect( hasSharedMeteredPath( gate, siteMeter ) ).toBe( false );
	} );
} );

describe( 'sharesTheSiteMeter', () => {
	const metered = scope => ( { active: true, metering: { enabled: true, count: 5, period: 'month', scope } } );

	it( 'is true at an allowance of 0, where hasSharedMeteredPath is not', () => {
		const gate = buildGate( { custom_access: metered( 'site' ) } );
		const emptyMeter = { anonymous_count: 0, registered_count: 0, period: 'month' };

		expect( sharesTheSiteMeter( gate ) ).toBe( true );
		expect( hasSharedMeteredPath( gate, emptyMeter ) ).toBe( false );
	} );

	it( 'is false once every path is pinned to its own allowance', () => {
		const gate = buildGate( { registration: metered( 'gate' ), custom_access: metered( 'gate' ) } );

		expect( sharesTheSiteMeter( gate ) ).toBe( false );
	} );

	it( 'is false for a path that shares but does not meter', () => {
		const gate = buildGate( { custom_access: { active: true, metering: { enabled: false, count: 5, period: 'month', scope: 'site' } } } );

		expect( sharesTheSiteMeter( gate ) ).toBe( false );
	} );
} );

describe( 'getMeteringDescription', () => {
	it( 'describes the allowance in general terms until the wizard has loaded it', () => {
		expect( getMeteringDescription() ).toContain( 'before a gate applies' );
	} );

	it( 'names both counts and the reset period once it has', () => {
		const description = getMeteringDescription( { anonymous_count: 2, registered_count: 5, period: 'week' } );

		expect( description ).toContain( '2 for signed-out readers' );
		expect( description ).toContain( '5 for signed-in' );
		expect( description ).toContain( 'weekly' );
	} );
} );
