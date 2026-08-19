/**
 * Internal dependencies
 */
import { isGateMetered } from './utils';

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

describe( 'isGateMetered with a shared site meter', () => {
	const siteMeter = { anonymous_count: 3, registered_count: 0, period: 'month' };

	it( 'reads the allowance off the site meter, not a stale count left on the gate', () => {
		const gate = buildGate( { registration: { active: true, metering: { enabled: true, count: 0, period: 'month' } } } );

		expect( isGateMetered( gate, siteMeter ) ).toBe( true );
	} );

	it( 'is false when the site meter grants the audience 0 free views', () => {
		const gate = buildGate( { custom_access: { active: true, metering: { enabled: true, count: 9, period: 'month' } } } );

		expect( isGateMetered( gate, siteMeter ) ).toBe( false );
	} );

	it( 'keeps reading the gate when it opted out of the site meter', () => {
		const gate = buildGate( {
			custom_access: { active: true, metering: { enabled: true, count: 9, period: 'month', scope: 'gate' } },
		} );

		expect( isGateMetered( gate, siteMeter ) ).toBe( true );
	} );
} );
