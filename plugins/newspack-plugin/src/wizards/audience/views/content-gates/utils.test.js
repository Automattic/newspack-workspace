/**
 * Internal dependencies
 */
import { getMeteringCount, getMeteringDescription, hasOwnMeter, hasSharedMeteredPath, isGateMetered, sharesTheSiteMeter } from './utils';

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
