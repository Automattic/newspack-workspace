/**
 * The gate summary is where a publisher reads back what a gate grants, so the
 * metering line has to name the right allowance and say what running out of it
 * leads to. Where a gate offers registration the allowance ends at the registration
 * wall; where it does not, the paid path governs signed-out readers too and the two
 * audiences draw on different site meter counts.
 */

/**
 * External dependencies
 */
import { render } from '@testing-library/react';

jest.mock( './edit/content-rule-control', () => ( { __esModule: true, default: () => null } ) );

const SPLIT_METER = { anonymous_count: 3, registered_count: 5, period: 'month' };

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

const summarise = ( gate, key, { isNewsletter = false, siteMeter = SPLIT_METER } = {} ) => {
	const { getGateSummarySections } = require( './gate-summary' );
	const section = getGateSummarySections( gate, isNewsletter, siteMeter ).find( entry => entry.key === key );
	const { container } = render( <>{ section.content }</> );
	return container.textContent;
};

const meteredPath = ( overrides = {} ) => ( {
	active: true,
	metering: { enabled: true, count: 0, period: 'month', scope: 'site', ...overrides },
} );

describe( 'gate summary metering copy', () => {
	beforeEach( () => {
		// Read at module load; must exist before the module is required.
		window.newspackAudienceContentGates = { available_access_rules: {} };
	} );

	it( 'names both allowances when the paid path also governs signed-out readers', () => {
		const gate = buildGate( { custom_access: meteredPath() } );

		expect( summarise( gate, 'custom_access' ) ).toContain(
			'3 free views per month for signed-out readers, 5 for signed-in, before content is restricted (site meter)'
		);
	} );

	it( 'quotes one allowance when both audiences get the same number', () => {
		const gate = buildGate( { custom_access: meteredPath() } );
		const siteMeter = { anonymous_count: 5, registered_count: 5, period: 'month' };

		expect( summarise( gate, 'custom_access', { siteMeter } ) ).toContain( '5 free views per month before content is restricted (site meter)' );
	} );

	it( 'leaves the allowance unqualified where readers meet a registration wall first', () => {
		const gate = buildGate( { registration: meteredPath(), custom_access: meteredPath() } );
		const text = summarise( gate, 'custom_access' );

		expect( text ).toContain( '5 free views per month (site meter)' );
		expect( text ).not.toContain( 'before content is restricted' );
		expect( text ).not.toContain( 'signed-out readers' );
	} );

	it( 'never qualifies the registered access allowance, which always precedes a wall', () => {
		const gate = buildGate( { registration: meteredPath() } );
		const text = summarise( gate, 'registration' );

		expect( text ).toContain( '3 free views per month (site meter)' );
		expect( text ).not.toContain( 'before content is restricted' );
	} );

	it( 'treats a premium newsletter gate as having no registration wall', () => {
		const gate = buildGate( { registration: meteredPath(), custom_access: meteredPath() } );

		expect( summarise( gate, 'custom_access', { isNewsletter: true } ) ).toContain( 'before content is restricted' );
	} );

	it( 'quotes the single stored count for a gate keeping its own allowance', () => {
		const gate = buildGate( { custom_access: meteredPath( { count: 1, period: 'week', scope: 'gate' } ) } );

		expect( summarise( gate, 'custom_access' ) ).toContain( '1 free view per week before content is restricted (this gate only)' );
	} );
} );

describe( 'gate summary sign-up and visibility', () => {
	beforeEach( () => {
		window.newspackAudienceContentGates = { available_access_rules: {} };
	} );

	const paidGate = () => buildGate( { custom_access: { active: true } } );

	const signUpAndVisibilityText = ( gate, { isNewsletter = true, siteAutoSignup = false } = {} ) => {
		const { getGateSummarySections } = require( './gate-summary' );
		const section = getGateSummarySections( gate, isNewsletter, SPLIT_METER, {}, siteAutoSignup ).find(
			entry => entry.key === 'sign_up_and_visibility'
		);
		return section ? render( <>{ section.content }</> ).container.textContent : null;
	};

	it( 'names the site setting a premium newsletter inherits, and that its lists are hidden', () => {
		const gate = { ...paidGate(), auto_signup: 'default', hide_lists: true };

		const text = signUpAndVisibilityText( gate, { siteAutoSignup: false } );

		expect( text ).toContain( 'Auto-signup: Default (off)' );
		expect( text ).toContain( 'Hidden from readers: Yes' );
	} );

	it( 'names the chosen mode when the gate overrides the site setting', () => {
		const gate = { ...paidGate(), auto_signup: 'on', hide_lists: false };

		const text = signUpAndVisibilityText( gate, { siteAutoSignup: false } );

		expect( text ).toContain( 'Auto-signup: On' );
		expect( text ).toContain( 'Hidden from readers: No' );
	} );

	it( 'treats a gate stored without the settings as using the site setting and showing its lists', () => {
		const text = signUpAndVisibilityText( paidGate(), { siteAutoSignup: true } );

		expect( text ).toContain( 'Auto-signup: Default (on)' );
		expect( text ).toContain( 'Hidden from readers: No' );
	} );

	it( 'shows N/A while paid access is off, as the editor has no sign-up step then', () => {
		const gate = { ...buildGate(), auto_signup: 'on', hide_lists: true };

		expect( signUpAndVisibilityText( gate ) ).toBe( 'N/A' );
	} );

	it( 'leaves the section out of a content gate', () => {
		const gate = { ...paidGate(), auto_signup: 'on', hide_lists: true };

		expect( signUpAndVisibilityText( gate, { isNewsletter: false } ) ).toBeNull();
	} );
} );
