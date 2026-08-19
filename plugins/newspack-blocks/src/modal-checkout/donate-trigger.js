/**
 * Resolve the form acted on by a modal checkout `donate` URL trigger.
 *
 * Resolution is side-effect free: no clicks, no input mutation. The caller
 * performs the clicks/submission described by the returned resolution.
 */

import { FREQUENCY_SLUGS } from '../blocks/donate/frequency-slugs';
import { TIERS_BASED_READY_ATTRIBUTE } from '../shared/js/tiers-based-ready';

/**
 * Layouts a donate URL trigger can request.
 *
 * @type {string[]}
 */
export const VALID_LAYOUTS = [ 'tiered', 'frequency', 'untiered' ];

/**
 * Donation frequency slugs a trigger can request.
 *
 * @type {string[]}
 */
export const VALID_FREQUENCIES = FREQUENCY_SLUGS;

/**
 * Validate donate URL trigger parameters.
 *
 * The values are interpolated into attribute selectors, so anything outside
 * the known closed sets (or a plain number) is rejected rather than escaped.
 * The `other` amount only exists in the frequency layout.
 *
 * @param {string} layout    The donation layout.
 * @param {string} frequency The donation frequency.
 * @param {string} amount    The donation amount.
 *
 * @return {boolean} Whether the parameters are usable.
 */
export function validateDonationTriggerParams( layout, frequency, amount ) {
	if ( ! VALID_LAYOUTS.includes( layout ) || ! VALID_FREQUENCIES.includes( frequency ) ) {
		return false;
	}
	if ( amount === 'other' ) {
		return layout === 'frequency';
	}
	return /^\d+(\.\d+)?$/.test( amount );
}

/**
 * Resolve which donate form a URL trigger should act on, and how.
 *
 * The first form in DOM order that can fully satisfy the trigger wins; forms
 * that cannot are skipped untouched. Tiered forms are matched against the
 * server-rendered tier markup (`data-frequency-slug`/`data-amount` spans), so
 * no tab needs to be clicked to find out whether the amount exists. A tiered
 * block whose view script has not initialized yet (no `data-tiers-based-ready`
 * on the container) cannot be acted on — clicking its tabs or tiers would do
 * nothing — so the scan continues past it; `not-ready` (carrying the first
 * such form) is returned only when no initialized form matched.
 *
 * @param {Document|HTMLElement} root             The DOM root to search.
 * @param {Object}               params           Trigger parameters.
 * @param {string}               params.layout    The donation layout.
 * @param {string}               params.frequency The donation frequency.
 * @param {string}               params.amount    The donation amount.
 *
 * @return {Object} Resolution object. `status` is one of `invalid-params`,
 *                  `no-match`, `not-ready`, `tiered`, `frequency` or
 *                  `untiered`. Tiered resolutions carry `form`, `tierIndex`
 *                  and `frequencyButton` (null when the block renders a single
 *                  frequency and no tab click is needed); frequency/untiered
 *                  resolutions carry `form`, `frequencyInput` and `amountInput`.
 */
export function resolveDonationTrigger( root, { layout, frequency, amount } ) {
	if ( ! validateDonationTriggerParams( layout, frequency, amount ) ) {
		return { status: 'invalid-params' };
	}
	let notReadyForm = null;
	for ( const form of root.querySelectorAll( '.wpbnbd.wpbnbd--platform-wc form' ) ) {
		const tiersContainer = form.closest( '.wpbnbd--tiers-based' );
		if ( layout === 'tiered' ) {
			if ( ! tiersContainer ) {
				continue;
			}
			const tierSpan = form.querySelector( `.wpbnbd__tiers__amount [data-frequency-slug="${ frequency }"][data-amount="${ amount }"]` );
			if ( ! tierSpan ) {
				continue;
			}
			const tierIndex = parseInt( tierSpan.getAttribute( 'data-tier-index' ) );
			if ( isNaN( tierIndex ) ) {
				continue;
			}
			// Blocks with a single enabled frequency render no tab buttons; the
			// hidden frequency input then decides whether this form matches.
			const frequencyButton = form.querySelector( `button[data-frequency-slug="${ frequency }"]` );
			if ( ! frequencyButton && ! form.querySelector( `input[name="donation_frequency"][value="${ frequency }"]` ) ) {
				continue;
			}
			if ( ! tiersContainer.hasAttribute( TIERS_BASED_READY_ATTRIBUTE ) ) {
				// Remember the first not-ready match but keep scanning: a later,
				// already-initialized block that satisfies the trigger beats waiting
				// on one that may never become ready (the view script bails without
				// stamping readiness on config or markup failures).
				if ( ! notReadyForm ) {
					notReadyForm = form;
				}
				continue;
			}
			return { status: 'tiered', form, frequencyButton, tierIndex };
		}
		if ( tiersContainer ) {
			continue;
		}
		const frequencyInput = form.querySelector( `input[name="donation_frequency"][value="${ frequency }"]` );
		if ( ! frequencyInput ) {
			continue;
		}
		const amountInput =
			layout === 'untiered'
				? form.querySelector( `input[name="donation_value_${ frequency }_untiered"]` )
				: form.querySelector( `input[name="donation_value_${ frequency }"][value="${ amount }"]` );
		if ( ! amountInput ) {
			continue;
		}
		return { status: layout, form, frequencyInput, amountInput };
	}
	if ( notReadyForm ) {
		return { status: 'not-ready', form: notReadyForm };
	}
	return { status: 'no-match' };
}
