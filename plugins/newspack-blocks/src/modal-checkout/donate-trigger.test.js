/**
 * Tests for the donate URL trigger resolution helpers.
 */

import { resolveDonationTrigger, validateDonationTriggerParams, TIERS_BASED_READY_EVENT, TIERS_BASED_READY_ATTRIBUTE } from './donate-trigger';

/**
 * Build a tiers-based Donate block markup string, mirroring
 * Newspack_Blocks_Donate_Renderer_Tiers_Based::render output.
 *
 * @param {Object}   options             Options.
 * @param {string[]} options.frequencies Enabled frequency slugs. Tab buttons render only when more than one.
 * @param {string}   options.defaultFreq The block's default (initially selected) frequency.
 * @param {Object}   options.amounts     Map of frequency slug => array of tier amounts.
 * @param {boolean}  options.ready       Whether the tiersBased view script marked the block initialized.
 * @return {string} HTML.
 */
const tieredBlock = ( {
	frequencies = [ 'month', 'year' ],
	defaultFreq = 'month',
	amounts = { month: [ 7, 15, 30 ], year: [ 84, 180, 360 ] },
	ready = true,
} = {} ) => {
	const readyAttr = ready ? ` ${ TIERS_BASED_READY_ATTRIBUTE }` : '';
	const tabs =
		frequencies.length > 1
			? `<div class="wpbnbd__tiers__selection">${ frequencies
					.map( slug => `<button type="button" data-frequency-slug="${ slug }" class="wpbnbd__button">${ slug }</button>` )
					.join( '' ) }</div>`
			: '';
	const tiers = amounts[ defaultFreq ]
		.map( ( amount, index ) => {
			const spans = frequencies
				.map(
					slug =>
						`<span data-frequency-slug="${ slug }" data-amount="${ amounts[ slug ][ index ] }" data-tier-index="${ index }">$${ amounts[ slug ][ index ] }</span>`
				)
				.join( '' );
			return `<div class="wpbnbd__tiers__tier"><div class="wpbnbd__tiers__amount"><span>${ spans }</span></div><button type="submit" name="donation_value_${ defaultFreq }" value="${ amount }" data-tier-index="${ index }">Donate</button></div>`;
		} )
		.join( '' );
	return `<div class="wpbnbd wpbnbd--platform-wc wpbnbd--tiers-based"${ readyAttr }><form data-is-init-form><input type="hidden" name="donation_frequency" value="${ defaultFreq }">${ tabs }<div class="wpbnbd__tiers__options">${ tiers }</div></form></div>`;
};

/**
 * Build a frequency-based Donate block markup string, mirroring
 * Newspack_Blocks_Donate_Renderer_Frequency_Based::render output.
 *
 * @param {Object}   options             Options.
 * @param {string[]} options.frequencies Enabled frequency slugs.
 * @param {Object}   options.amounts     Map of frequency slug => array of amounts.
 * @param {boolean}  options.untiered    Render the untiered variant instead of amount radios.
 * @return {string} HTML.
 */
const frequencyBlock = ( {
	frequencies = [ 'once', 'month', 'year' ],
	amounts = { once: [ 7, 15, 30 ], month: [ 7, 15, 30 ], year: [ 84, 180, 360 ] },
	untiered = false,
} = {} ) => {
	const panels = frequencies
		.map( slug => {
			const freqRadio = `<input type="radio" name="donation_frequency" value="${ slug }">`;
			if ( untiered ) {
				return `${ freqRadio }<input type="number" name="donation_value_${ slug }_untiered" value="${ amounts[ slug ][ 0 ] }">`;
			}
			const amountRadios = amounts[ slug ]
				.map( amount => `<input type="radio" name="donation_value_${ slug }" value="${ amount }">` )
				.join( '' );
			return `${ freqRadio }${ amountRadios }<input type="radio" name="donation_value_${ slug }" value="other"><input type="number" name="donation_value_${ slug }_other">`;
		} )
		.join( '' );
	return `<div class="wpbnbd wpbnbd--platform-wc wpbnbd--frequency-based"><form>${ panels }<button type="submit">Donate</button></form></div>`;
};

const render = html => {
	document.body.innerHTML = html;
	return document.body;
};

afterEach( () => {
	document.body.innerHTML = '';
} );

describe( 'validateDonationTriggerParams', () => {
	it( 'accepts a tiered layout with a known frequency and integer amount', () => {
		expect( validateDonationTriggerParams( 'tiered', 'month', '15' ) ).toBe( true );
	} );

	it( 'accepts a decimal amount', () => {
		expect( validateDonationTriggerParams( 'untiered', 'year', '15.50' ) ).toBe( true );
	} );

	it( 'accepts amount "other" for the frequency layout only', () => {
		expect( validateDonationTriggerParams( 'frequency', 'once', 'other' ) ).toBe( true );
		expect( validateDonationTriggerParams( 'tiered', 'once', 'other' ) ).toBe( false );
		expect( validateDonationTriggerParams( 'untiered', 'once', 'other' ) ).toBe( false );
	} );

	it( 'rejects an unknown layout', () => {
		expect( validateDonationTriggerParams( 'banana', 'month', '15' ) ).toBe( false );
	} );

	it( 'rejects an unknown frequency', () => {
		expect( validateDonationTriggerParams( 'tiered', 'weekly', '15' ) ).toBe( false );
	} );

	it( 'rejects selector-breaking values', () => {
		expect( validateDonationTriggerParams( 'tiered', 'month"]', '15' ) ).toBe( false );
		expect( validateDonationTriggerParams( 'tiered', 'month', '15"][name="x' ) ).toBe( false );
	} );
} );

describe( 'resolveDonationTrigger — tiered layout', () => {
	it( 'resolves a non-default frequency to the tab button and tier index', () => {
		const root = render( tieredBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '180' } );
		expect( resolution.status ).toBe( 'tiered' );
		expect( resolution.form ).toBe( root.querySelector( 'form' ) );
		expect( resolution.frequencyButton ).toBe( root.querySelector( 'button[data-frequency-slug="year"]' ) );
		expect( resolution.tierIndex ).toBe( 1 );
	} );

	it( 'resolves a single-frequency block without a tab button when the frequency matches', () => {
		const root = render( tieredBlock( { frequencies: [ 'month' ], amounts: { month: [ 7, 15, 30 ] } } ) );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'month', amount: '15' } );
		expect( resolution.status ).toBe( 'tiered' );
		expect( resolution.frequencyButton ).toBeNull();
		expect( resolution.tierIndex ).toBe( 1 );
	} );

	it( 'does not match a single-frequency block for a different frequency', () => {
		const root = render( tieredBlock( { frequencies: [ 'month' ], amounts: { month: [ 7, 15, 30 ] } } ) );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '15' } );
		expect( resolution.status ).toBe( 'no-match' );
	} );

	it( 'reports a matching block whose view script has not initialized as not ready', () => {
		const root = render( tieredBlock( { ready: false } ) );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '180' } );
		expect( resolution.status ).toBe( 'not-ready' );
		expect( resolution.form ).toBe( root.querySelector( 'form' ) );
	} );

	it( 'prefers a later initialized block over an earlier one that never initialized', () => {
		const root = render( tieredBlock( { ready: false } ) + tieredBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '180' } );
		expect( resolution.status ).toBe( 'tiered' );
		expect( resolution.form ).toBe( root.querySelectorAll( 'form' )[ 1 ] );
	} );

	it( 'reports not-ready, carrying the first such form, only when no initialized block matches', () => {
		const root = render( tieredBlock( { ready: false } ) + tieredBlock( { ready: false } ) );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '180' } );
		expect( resolution.status ).toBe( 'not-ready' );
		expect( resolution.form ).toBe( root.querySelectorAll( 'form' )[ 0 ] );
	} );

	it( 'resolves after the tiers view marks the block ready, matching the ready-event contract', () => {
		const root = render( tieredBlock( { ready: false } ) );
		expect( resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '180' } ).status ).toBe( 'not-ready' );
		// What the tiers-based view does when it initializes (see view.ts):
		// stamp the container, then announce readiness so waiting triggers retry.
		expect( TIERS_BASED_READY_EVENT ).toBe( 'newspack-tiers-based-ready' );
		expect( TIERS_BASED_READY_ATTRIBUTE ).toBe( 'data-tiers-based-ready' );
		root.querySelector( '.wpbnbd--tiers-based' ).setAttribute( TIERS_BASED_READY_ATTRIBUTE, '' );
		const retried = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '180' } );
		expect( retried.status ).toBe( 'tiered' );
	} );

	it( 'returns no-match for an amount no tier offers, without mutating the form', () => {
		const root = render( tieredBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '999' } );
		expect( resolution.status ).toBe( 'no-match' );
		expect( root.querySelector( 'input[name="donation_frequency"]' ).value ).toBe( 'month' );
		expect( root.querySelector( 'button[type="submit"]' ).getAttribute( 'name' ) ).toBe( 'donation_value_month' );
	} );

	it( 'picks the first matching form in DOM order, skipping non-matching forms', () => {
		const root = render( tieredBlock( { frequencies: [ 'month' ], amounts: { month: [ 7, 15, 30 ] } } ) + tieredBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'year', amount: '180' } );
		expect( resolution.status ).toBe( 'tiered' );
		expect( resolution.form ).toBe( root.querySelectorAll( 'form' )[ 1 ] );
	} );

	it( 'never acts on a frequency-based form', () => {
		const root = render( frequencyBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'month', amount: '15' } );
		expect( resolution.status ).toBe( 'no-match' );
	} );

	it( 'rejects invalid parameters before touching the DOM', () => {
		const root = render( tieredBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'tiered', frequency: 'month"]', amount: '15' } );
		expect( resolution.status ).toBe( 'invalid-params' );
	} );
} );

describe( 'resolveDonationTrigger — frequency and untiered layouts', () => {
	it( 'resolves the frequency layout to its frequency and amount inputs', () => {
		const root = render( frequencyBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'frequency', frequency: 'year', amount: '180' } );
		expect( resolution.status ).toBe( 'frequency' );
		expect( resolution.frequencyInput ).toBe( root.querySelector( 'input[name="donation_frequency"][value="year"]' ) );
		expect( resolution.amountInput ).toBe( root.querySelector( 'input[name="donation_value_year"][value="180"]' ) );
	} );

	it( 'resolves amount "other" to the other radio', () => {
		const root = render( frequencyBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'frequency', frequency: 'once', amount: 'other' } );
		expect( resolution.status ).toBe( 'frequency' );
		expect( resolution.amountInput ).toBe( root.querySelector( 'input[name="donation_value_once"][value="other"]' ) );
	} );

	it( 'resolves the untiered layout to the untiered amount input', () => {
		const root = render( frequencyBlock( { untiered: true } ) );
		const resolution = resolveDonationTrigger( root, { layout: 'untiered', frequency: 'month', amount: '25' } );
		expect( resolution.status ).toBe( 'untiered' );
		expect( resolution.amountInput ).toBe( root.querySelector( 'input[name="donation_value_month_untiered"]' ) );
	} );

	it( 'never acts on a tiered form', () => {
		const root = render( tieredBlock() );
		const resolution = resolveDonationTrigger( root, { layout: 'frequency', frequency: 'month', amount: '15' } );
		expect( resolution.status ).toBe( 'no-match' );
	} );

	it( 'returns no-match when the page has no donate forms', () => {
		const root = render( '<p>No blocks here.</p>' );
		const resolution = resolveDonationTrigger( root, { layout: 'frequency', frequency: 'month', amount: '15' } );
		expect( resolution.status ).toBe( 'no-match' );
	} );
} );
