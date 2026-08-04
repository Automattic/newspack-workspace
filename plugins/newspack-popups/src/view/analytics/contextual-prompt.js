/**
 * GA4 analytics for the Contextual Prompt card.
 *
 * The card is body content, not a Campaigns prompt, so it can't ride the
 * prompt-keyed `np_prompt_interaction` event (there is no newspack_popup_id).
 * It emits its own `np_contextual_prompt_interaction` event instead, keyed by
 * the article post id, reusing the shared gtag wrapper.
 *
 * Params: action, action_type, contextual_prompt_post_id, contextual_prompt_placement,
 * button_text (click, plain-button mode), link_url + prompt_text. The first four plus
 * button_text are registered GA4 custom dimensions; prompt_text (high-cardinality) and
 * link_url (near-constant) are sent as params only, recoverable via the BigQuery export
 * rather than GA4-UI reporting. See GA4_Custom_Dimensions::get_dimensions().
 */

/**
 * Internal dependencies
 */
import { sendEvent } from '../utils/analytics';

const EVENT_NAME = 'np_contextual_prompt_interaction';
const SELECTOR = '[data-newspack-cp-post-id]';

// A prompt counts as "seen" once it is at least half visible for this long.
const SEEN_THRESHOLD = 0.5;
const SEEN_DURATION_MS = 250;

// GA4 silently truncates event parameter values past 100 chars; truncate the
// copy ourselves so the cut is clean rather than mid-word. prompt_text is a
// preview/label, not the full copy — GA is not a full-text store.
const MAX_PARAM_LENGTH = 100;
const clamp = value => ( value || '' ).slice( 0, MAX_PARAM_LENGTH );

// The ask copy is the block's first paragraph — the CTA is a button/link, never a <p>.
const promptTextOf = element => {
	const copy = element.querySelector( 'p' );
	return copy ? clamp( copy.innerText.trim() ) : '';
};

const payloadFor = ( element, action, extra = {} ) => ( {
	action,
	action_type: element.getAttribute( 'data-newspack-cp-cta' ) || '',
	contextual_prompt_post_id: element.getAttribute( 'data-newspack-cp-post-id' ) || '',
	contextual_prompt_placement: element.getAttribute( 'data-newspack-cp-placement' ) || '',
	prompt_text: promptTextOf( element ),
	...extra,
} );

/**
 * Fire `seen` once per prompt, when it has been sufficiently visible.
 *
 * @param {Element} element The prompt card wrapper.
 */
const trackSeen = element => {
	if ( 'undefined' === typeof IntersectionObserver ) {
		return;
	}
	let timer = null;
	const observer = new IntersectionObserver(
		entries => {
			entries.forEach( entry => {
				// The observer's initial callback fires at whatever the current ratio
				// is, threshold or not, so the ratio is checked rather than assumed.
				if ( entry.isIntersecting && entry.intersectionRatio >= SEEN_THRESHOLD ) {
					if ( timer ) {
						return;
					}
					timer = setTimeout( () => {
						timer = null;
						sendEvent( payloadFor( element, 'seen' ), EVENT_NAME );
						observer.disconnect();
					}, SEEN_DURATION_MS );
				} else if ( timer ) {
					clearTimeout( timer );
					timer = null;
				}
			} );
		},
		{ threshold: SEEN_THRESHOLD }
	);
	observer.observe( element );
};

/**
 * Fire `clicked` when the reader activates the CTA — a link (plain-button mode)
 * or the donate block's submit button (native mode).
 *
 * @param {Element} element The prompt card wrapper.
 */
const trackClicked = element => {
	element.addEventListener( 'click', event => {
		// The CTA activation only: the CTA button's link (plain-button mode) or a
		// submit control inside the donate block (native mode). Deliberately NOT
		// any link or button — inline links in the copy are editorial, and the
		// donate block's amount/frequency selectors are buttons too; counting
		// either would inflate clicks and skew comparison between the two modes.
		const link = event.target.closest( '.wp-block-buttons .wp-block-button__link[href]' );
		if ( ! link && ! event.target.closest( '.wpbnbd [type="submit"]' ) ) {
			return;
		}
		// button_text and link_url are meaningful only in plain-button mode (the
		// donate block has no single label or href). Read from the actual link,
		// like EngageLine did.
		const extra = link ? { button_text: clamp( ( link.innerText || '' ).trim() ), link_url: clamp( link.href || '' ) } : {};
		sendEvent( payloadFor( element, 'clicked', extra ), EVENT_NAME );
	} );
};

/**
 * Wire up seen/clicked tracking for every Contextual Prompt card on the page.
 */
export const handleContextualPromptAnalytics = () => {
	document.querySelectorAll( SELECTOR ).forEach( element => {
		trackSeen( element );
		trackClicked( element );
	} );
};
