/**
 * The gate summary is where a publisher reads back what a gate grants, so the
 * metering line has to say what running out of free views leads to. Where a gate
 * offers registration the allowance ends at the registration wall; where it does
 * not, it ends at the block. These pin that the wording follows the gate.
 */

/**
 * External dependencies
 */
import { render } from '@testing-library/react';

jest.mock( './edit/content-rule-control', () => ( { __esModule: true, default: () => null } ) );

const siteMeter = { anonymous_count: 3, registered_count: 5, period: 'month' };

const buildGate = ( { registration, custom_access: customAccess } = {} ) => ( {
	id: 1,
	title: 'Gate',
	status: 'publish',
	content_rules: [],
	registration: {
		active: false,
		require_verification: false,
		metering: { enabled: false, count: 0, period: 'month', scope: 'site' },
		...registration,
	},
	custom_access: {
		active: false,
		access_rules: [],
		metering: { enabled: false, count: 0, period: 'month', scope: 'site' },
		...customAccess,
	},
} );

const summarise = ( gate, key, isNewsletter = false ) => {
	const { getGateSummarySections } = require( './gate-summary' );
	const section = getGateSummarySections( gate, isNewsletter, siteMeter ).find( entry => entry.key === key );
	const { container } = render( <>{ section.content }</> );
	return container.textContent;
};

describe( 'gate summary metering copy', () => {
	beforeEach( () => {
		// Read at module load; must exist before the module is required.
		window.newspackAudienceContentGates = { available_access_rules: {} };
	} );

	it( 'says what the allowance leads to on a gate with no registration wall', () => {
		const gate = buildGate( { custom_access: { active: true, metering: { enabled: true, count: 0, period: 'month', scope: 'site' } } } );

		expect( summarise( gate, 'custom_access' ) ).toContain( '5 free views per month before content is restricted (site meter)' );
	} );

	it( 'leaves the allowance unqualified where readers meet a registration wall first', () => {
		const gate = buildGate( {
			registration: { active: true, metering: { enabled: true, count: 0, period: 'month', scope: 'site' } },
			custom_access: { active: true, metering: { enabled: true, count: 0, period: 'month', scope: 'site' } },
		} );
		const text = summarise( gate, 'custom_access' );

		expect( text ).toContain( '5 free views per month (site meter)' );
		expect( text ).not.toContain( 'before content is restricted' );
	} );

	it( 'never qualifies the registered access allowance, which always precedes a wall', () => {
		const gate = buildGate( { registration: { active: true, metering: { enabled: true, count: 0, period: 'month', scope: 'site' } } } );
		const text = summarise( gate, 'registration' );

		expect( text ).toContain( '3 free views per month (site meter)' );
		expect( text ).not.toContain( 'before content is restricted' );
	} );

	it( 'treats a premium newsletter gate as having no registration wall', () => {
		const gate = buildGate( {
			registration: { active: true, metering: { enabled: true, count: 0, period: 'month', scope: 'site' } },
			custom_access: { active: true, metering: { enabled: true, count: 0, period: 'month', scope: 'site' } },
		} );

		expect( summarise( gate, 'custom_access', true ) ).toContain( 'before content is restricted' );
	} );

	it( 'keeps the singular form for a gate allowing one free view', () => {
		const gate = buildGate( { custom_access: { active: true, metering: { enabled: true, count: 1, period: 'week', scope: 'gate' } } } );

		expect( summarise( gate, 'custom_access' ) ).toContain( '1 free view per week before content is restricted (this gate only)' );
	} );
} );
