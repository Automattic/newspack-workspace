/**
 * Internal dependencies
 */
import { getGateStatusBadgeIntent, isGateMetered } from './utils';

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
