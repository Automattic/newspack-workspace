/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * The summary reads `available_access_rules` at module scope, so the globals have to be
 * in place before it is required.
 */
const ANNUAL_188250 = { value: 188250, label: 'Annual' };
const ANNUAL_205482 = { value: 205482, label: 'Annual' };

window.newspackAudienceContentGates = {
	available_access_rules: {
		subscription: { name: 'Active subscription', options: [ ANNUAL_188250, ANNUAL_205482 ] },
		one_time_purchase: {
			name: 'One-time purchase',
			options: [ ANNUAL_188250, ANNUAL_205482, { value: 42, label: 'Founder&#8217;s Club' } ],
		},
		institution: { name: 'Institutional access', has_options: true, requires_value: true, options: [] },
		email_domain: { name: 'Whitelisted email domain', has_options: false, empty_grants_access: true, requires_value: true },
	},
	available_content_rules: {},
};

/**
 * Internal dependencies
 */
const { getGateSummarySections } = require( './gate-summary' );
const { formatAccessRuleOptionLabel } = require( '../../../../content-gate/access-rule-options' );

const gateWith = ( ...rules ) => ( {
	content_rules: [],
	registration: { active: false, metering: { enabled: false } },
	custom_access: { active: true, access_rules: [ rules ], metering: { enabled: false } },
} );

const renderPaidAccess = ( gate, optionsBySlug ) => {
	const section = getGateSummarySections( gate, false, undefined, optionsBySlug ).find( s => 'custom_access' === s.key );
	render( <div>{ section.content }</div> );
};

describe( 'gate summary, Paid access', () => {
	it( 'identifies same-named products by ID on every rule, including one-time purchase', () => {
		// Both lists come from the same catalogue, so a summary that names one and not
		// the other reads as `Annual (#188250), Annual (#205482)` above `Annual, Annual`.
		renderPaidAccess(
			gateWith(
				{ slug: 'subscription', value: [ 188250, 205482 ] },
				{ slug: 'one_time_purchase', value: { product_ids: [ 188250, 205482 ], duration_value: 0, duration_unit: 'forever' } }
			)
		);

		expect( screen.getByText( 'Annual (#188250), Annual (#205482)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Annual (#188250), Annual (#205482) (forever)' ) ).toBeInTheDocument();
	} );

	it( 'formats a value the same way the pickers do', () => {
		// The three surfaces that show a rule's values have drifted before; pin the
		// summary to the helper the pickers build their tokens from.
		renderPaidAccess( gateWith( { slug: 'subscription', value: [ 188250 ] } ) );

		expect( screen.getByText( formatAccessRuleOptionLabel( ANNUAL_188250 ) ) ).toBeInTheDocument();
	} );

	it( 'decodes entities in a one-time product name', () => {
		renderPaidAccess( gateWith( { slug: 'one_time_purchase', value: { product_ids: [ 42 ], duration_value: 30, duration_unit: 'days' } } ) );

		expect( screen.getByText( 'Founder’s Club (#42) (30 days from purchase)' ) ).toBeInTheDocument();
	} );

	it( 'prefers the options it is handed over the ones localised with the page', () => {
		// Institutions are created and deleted in the same app, so a summary built from
		// the page-load snapshot would call one added a moment ago "not listed" — the
		// wording that tells a publisher a value may be safe to remove.
		renderPaidAccess( gateWith( { slug: 'subscription', value: [ 700001 ] } ), {
			subscription: [ { value: 700001, label: 'Added just now' } ],
		} );

		expect( screen.getByText( 'Added just now (#700001)' ) ).toBeInTheDocument();
	} );

	it( 'names a value no option describes rather than printing it bare', () => {
		renderPaidAccess(
			gateWith( { slug: 'one_time_purchase', value: { product_ids: [ 999999 ], duration_value: 0, duration_unit: 'forever' } } )
		);

		expect( screen.getByText( '(product not listed) (#999999) (forever)' ) ).toBeInTheDocument();
	} );

	it( 'says which way an unconfigured rule fails, per rule', () => {
		// A rule with no value renders as a blank condition, and the summary is the
		// only place a publisher sees the whole gate at once. The two rules that can
		// be left empty do opposite things, so one wording for both would be wrong
		// half the time.
		renderPaidAccess( gateWith( { slug: 'institution', value: [] }, { slug: 'email_domain', value: '' } ) );

		expect( screen.getByText( 'Not set (matches no reader)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not set (grants access to everyone)' ) ).toBeInTheDocument();
	} );
} );
