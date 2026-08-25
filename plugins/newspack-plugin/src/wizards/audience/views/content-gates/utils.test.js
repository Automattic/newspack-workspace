/**
 * Internal dependencies
 */
import { getGateStatusBadgeIntent, isGateMetered, isMalformedAccessRuleValue } from './utils';

/**
 * `isGateMetered` decides whether the wizard offers metering-dependent features (the
 * Metered Countdown card). It has to agree with `Newspack\Metering::is_gate_metered()`,
 * which is what decides whether those features actually render on the frontend - a gate
 * that meters 0 free views gates every reader immediately, so there is nothing to count
 * down (NPPD-2056).
 */
const buildGate = ( { registration = {}, custom_access: customAccess = {} } = {} ) => ( {
	id: 1,
	registration: {
		active: false,
		metering: { enabled: false, count: 0, period: 'month' },
		...registration,
	},
	custom_access: {
		active: false,
		metering: { enabled: false, count: 0, period: 'month' },
		...customAccess,
	},
} );

describe( 'isGateMetered', () => {
	it( 'is true when an active section meters a positive number of views', () => {
		const gate = buildGate( { registration: { active: true, metering: { enabled: true, count: 3, period: 'month' } } } );

		expect( isGateMetered( gate ) ).toBe( true );
	} );

	it( 'is false when metering is on but grants 0 free views', () => {
		const gate = buildGate( { registration: { active: true, metering: { enabled: true, count: 0, period: 'month' } } } );

		expect( isGateMetered( gate ) ).toBe( false );
	} );

	it( 'is false when the section holding the metering settings is inactive', () => {
		const gate = buildGate( { custom_access: { active: false, metering: { enabled: true, count: 3, period: 'month' } } } );

		expect( isGateMetered( gate ) ).toBe( false );
	} );

	it( 'judges each audience on its own settings rather than combining them', () => {
		// Anonymous readers keep a leftover count with metering switched off; registered
		// readers meter, but with no free views to give. Neither section meters, so
		// neither may borrow the other's half of the answer.
		const gate = buildGate( {
			registration: { active: true, metering: { enabled: false, count: 3, period: 'month' } },
			custom_access: { active: true, metering: { enabled: true, count: 0, period: 'month' } },
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

	it( 'leaves boolean rules alone, which carry no value to judge', () => {
		expect( isMalformedAccessRuleValue( { name: 'Has donated', is_boolean: true, has_options: false }, true ) ).toBe( false );
	} );
} );
