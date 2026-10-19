/**
 * External dependencies
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

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
		esp__DONATION: { name: 'Donation total', has_options: false, is_range: true, empty_grants_access: true, requires_value: true },
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

describe( 'gate summary, institutions on Registered access', () => {
	const INSTITUTION = { value: 7, label: 'Example University' };
	const SECOND = { value: 8, label: 'State Library' };
	const renderRegistration = registration => {
		const sections = getGateSummarySections(
			{
				content_rules: [],
				registration: { metering: { enabled: false }, ...registration },
				custom_access: { active: false, access_rules: [], metering: { enabled: false } },
			},
			false,
			undefined,
			{ institution: [ INSTITUTION, SECOND ] }
		);
		const { container } = render( <div>{ sections.find( s => 'registration' === s.key ).content }</div> );
		return { sections, container };
	};
	const institutionRules = [ [ { slug: 'institution', value: [ 7 ] } ] ];

	it( 'lists them as a labeled line inside Registered Access, not as a section of their own', () => {
		const { sections } = renderRegistration( { active: true, access_rules: institutionRules } );

		expect( sections.map( s => s.key ) ).toEqual( [ 'content_rules', 'registration', 'custom_access' ] );
		expect( screen.getByText( 'Institutional access:' ) ).toBeInTheDocument();
		expect( screen.getByText( formatAccessRuleOptionLabel( INSTITUTION ), { exact: false } ) ).toBeInTheDocument();
	} );

	it( 'separates several institutions instead of running their names together', () => {
		const { container } = renderRegistration( { active: true, access_rules: [ [ { slug: 'institution', value: [ 7, 8 ] } ] ] } );

		expect( container.textContent ).not.toContain( formatAccessRuleOptionLabel( INSTITUTION ) + formatAccessRuleOptionLabel( SECOND ) );
		expect( screen.getByText( formatAccessRuleOptionLabel( SECOND ), { exact: false } ) ).toBeInTheDocument();
	} );

	it.each( [
		[ 'registered access is off', { active: false, access_rules: institutionRules } ],
		[ 'no institution is named', { active: true, access_rules: [] } ],
		[ 'the registration object lacks the key', { active: true } ],
	] )( 'leaves the line out when %s', ( _, registration ) => {
		renderRegistration( registration );

		expect( screen.queryByText( 'Institutional access:' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'gate summary, Paid access', () => {
	it( 'identifies same-named products by ID on every rule, including one-time purchase', () => {
		// Both lists come from the same catalogue, so a summary that names one and not
		// the other reads as `Annual (#188250) or Annual (#205482)` above `Annual or Annual`.
		renderPaidAccess(
			gateWith(
				{ slug: 'subscription', value: [ 188250, 205482 ] },
				{ slug: 'one_time_purchase', value: { product_ids: [ 188250, 205482 ], duration_value: 0, duration_unit: 'forever' } }
			)
		);

		expect( screen.getByText( 'Annual (#188250) or Annual (#205482)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Annual (#188250) or Annual (#205482) (forever)' ) ).toBeInTheDocument();
	} );

	it( 'joins the values with "or", since any one of them grants access', () => {
		// A comma-joined list reads as "needs all of these", which no rule requires.
		renderPaidAccess( gateWith( { slug: 'subscription', value: [ 188250, 205482, 999999 ] } ) );

		expect( screen.getByText( 'Annual (#188250) or Annual (#205482) or (product not listed) (#999999)' ) ).toBeInTheDocument();
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

describe( 'gate summary, range rules', () => {
	it( 'reads the bounds back in words', () => {
		// One group per rule, since the summary keys each line by group and slug.
		const gate = gateWith();
		gate.custom_access.access_rules = [ { min: 50, max: 100 }, { min: 50 }, { max: 10 } ].map( value => [ { slug: 'esp__DONATION', value } ] );
		renderPaidAccess( gate );

		expect( screen.getByText( '50 to 100' ) ).toBeInTheDocument();
		expect( screen.getByText( 'At least 50' ) ).toBeInTheDocument();
		expect( screen.getByText( 'At most 10' ) ).toBeInTheDocument();
	} );

	it( 'says an unset range still turns away readers holding no number', () => {
		renderPaidAccess( gateWith( { slug: 'esp__DONATION', value: {} } ) );

		expect( screen.getByText( 'Not set (grants access to every reader with a number)' ) ).toBeInTheDocument();
	} );

	it( 'flags bounds the rule cannot compare, as the save panel shows them before the save refuses', () => {
		const gate = gateWith();
		gate.custom_access.access_rules = [
			{ min: 100, max: 50 },
			{ min: '50O', max: 100 },
		].map( value => [ { slug: 'esp__DONATION', value } ] );
		renderPaidAccess( gate );

		expect( screen.getByText( '100 to 50 (grants no access)' ) ).toBeInTheDocument();
		expect( screen.getByText( '50O to 100 (grants no access)' ) ).toBeInTheDocument();
	} );

	it( 'flags text saved before the min/max control, which the rule denies on', () => {
		renderPaidAccess( gateWith( { slug: 'esp__DONATION', value: '50' } ) );

		expect( screen.getByText( '50 (invalid value, grants no access)' ) ).toBeInTheDocument();
	} );
} );

describe( 'gate summary, long rule values', () => {
	// jsdom has no layout, so text is modeled as wrapping every perLine characters.
	let perLine = 60;
	const LINE_HEIGHT = 20;
	const INSTITUTIONS = Array.from( { length: 80 }, ( _unused, index ) => ( { value: index + 1, label: `Institution ${ index + 1 }` } ) );
	const linesFor = characters => Math.max( 1, Math.ceil( characters / perLine ) );
	const charactersBefore = ( root, node, offset ) => {
		let total = 0;
		const walker = document.createTreeWalker( root, NodeFilter.SHOW_TEXT );
		while ( walker.nextNode() ) {
			if ( walker.currentNode === node ) {
				return total + offset;
			}
			total += walker.currentNode.textContent.length;
		}
		return total;
	};
	const realGetComputedStyle = window.getComputedStyle;
	const realRangeRect = Range.prototype.getBoundingClientRect;

	let resizeCallbacks = [];
	const resizeTo = width => act( () => resizeCallbacks.forEach( callback => callback( [ { contentRect: { width } } ] ) ) );

	beforeEach( () => {
		perLine = 60;
		resizeCallbacks = [];
		window.ResizeObserver = class {
			constructor( callback ) {
				resizeCallbacks.push( callback );
			}
			observe() {}
			disconnect() {}
		};
		jest.spyOn( window, 'getComputedStyle' ).mockImplementation(
			element =>
				new Proxy( realGetComputedStyle( element ), {
					get: ( target, prop ) => {
						if ( 'lineHeight' === prop ) {
							return `${ LINE_HEIGHT }px`;
						}
						return typeof target[ prop ] === 'function' ? target[ prop ].bind( target ) : target[ prop ];
					},
				} )
		);
		jest.spyOn( HTMLElement.prototype, 'getBoundingClientRect' ).mockImplementation( function () {
			const height = linesFor( this.textContent.length ) * LINE_HEIGHT;
			return { top: 0, bottom: height, height };
		} );
		Range.prototype.getBoundingClientRect = function () {
			const paragraph = this.endContainer.parentElement.closest( 'p' );
			const height = linesFor( charactersBefore( paragraph, this.endContainer, this.endOffset ) ) * LINE_HEIGHT;
			return { top: 0, bottom: height, height };
		};
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		Range.prototype.getBoundingClientRect = realRangeRect;
		delete window.ResizeObserver;
	} );

	const renderLongRule = () =>
		renderPaidAccess( gateWith( { slug: 'institution', value: INSTITUTIONS.map( ( { value } ) => value ) } ), { institution: INSTITUTIONS } );

	it( 'fits a long list into two lines with a count of the rest', () => {
		renderLongRule();

		const toggle = screen.getByRole( 'button' );
		const shown = toggle.closest( 'p' ).textContent.match( /Institution \d+/g ).length;
		expect( toggle ).toHaveTextContent( `+ ${ INSTITUTIONS.length - shown } more` );
		expect( linesFor( toggle.closest( 'p' ).textContent.length ) ).toBeLessThanOrEqual( 2 );
		const withOneMore = `Institutional access: ${ INSTITUTIONS.slice( 0, shown + 1 )
			.map( formatAccessRuleOptionLabel )
			.join( ' or ' ) } + ${ INSTITUTIONS.length - shown - 1 } more`;
		expect( linesFor( withOneMore.length ) ).toBeGreaterThan( 2 );
	} );

	it( 'keeps keyboard focus on the toggle through Show less', () => {
		renderLongRule();
		const toggle = screen.getByRole( 'button' );
		toggle.focus();

		fireEvent.click( toggle );
		expect( toggle ).toHaveTextContent( 'Show less' );
		expect( toggle.ownerDocument.activeElement ).toBe( toggle );

		fireEvent.click( toggle );
		expect( toggle ).toHaveTextContent( /more$/ );
		expect( toggle.ownerDocument.activeElement ).toBe( toggle );
	} );

	it( 'keeps keyboard focus on the toggle when the card is resized while the list is expanded', () => {
		renderLongRule();
		const toggle = screen.getByRole( 'button' );
		toggle.focus();
		fireEvent.click( toggle );

		perLine = 50;
		resizeTo( 500 );
		fireEvent.click( toggle );

		expect( toggle ).toHaveTextContent( /more$/ );
		expect( toggle.ownerDocument.activeElement ).toBe( toggle );
		expect( linesFor( toggle.closest( 'p' ).textContent.length ) ).toBeLessThanOrEqual( 2 );
	} );

	it( 'shows every value and keeps focus in the list when a resize leaves nothing to hide', () => {
		renderLongRule();
		const toggle = screen.getByRole( 'button' );
		const paragraph = toggle.closest( 'p' );
		toggle.focus();
		fireEvent.click( toggle );

		perLine = 100000;
		resizeTo( 5000 );
		fireEvent.click( toggle );

		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
		expect( paragraph.textContent.match( /Institution \d+/g ) ).toHaveLength( INSTITUTIONS.length );
		expect( paragraph.contains( paragraph.ownerDocument.activeElement ) ).toBe( true );
	} );
} );
