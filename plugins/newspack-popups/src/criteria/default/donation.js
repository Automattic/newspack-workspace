import { setMatchingFunction } from '../utils';

/**
 * Query param carrying the reader's donor status, substituted per-recipient by
 * the ESP from the configured donor merge field (e.g. `?np_seg_donor=true`).
 */
const DONOR_PARAM = 'np_seg_donor';

/**
 * Session-storage key used to remember that the reader arrived from a newsletter
 * email carrying a positive donor status during the current browsing session.
 */
const FROM_EMAIL_DONOR_KEY = 'newspack-popups-donor-from-email';

/**
 * Whether a donor merge-field value counts as a positive donor indicator.
 *
 * Mirrors Newspack_Popups_Segmentation::is_donor_merge_field_value() in PHP —
 * keep the falsy list in sync.
 *
 * @param {string} value The merge-tag value from the inbound link.
 * @return {boolean} Whether the value indicates the reader is a donor.
 */
const isDonorValue = value => ! [ 'no', 'none', 'false', '0', '' ].includes( String( value ).toLowerCase() );

/**
 * Whether a query-param value still contains unsubstituted ESP merge-tag syntax
 * — i.e. the sending service failed to replace it with the actual per-recipient
 * value. Such a value must be ignored, or every recipient of a misconfigured
 * send would be flagged as a donor from the raw template string.
 *
 * Covers three of the four ESPs supported by Newspack Newsletters:
 *   Mailchimp        *|FIELD|*
 *   Constant Contact [[FIELD]]
 *   ActiveCampaign   %FIELD%
 *
 * Campaign Monitor uses [FIELD], but bare square brackets are too common in
 * query values to guard against reliably.
 *
 * @param {string} value The decoded query-param value.
 * @return {boolean} True when the value contains raw merge-tag syntax.
 */
const isUnsubstitutedMergeTag = value =>
	/^\*\|[^|]+\|\*$/.test( value ) || // Mailchimp
	/^\[\[[^\]]+\]\]$/.test( value ) || // Constant Contact
	/^%[^%]+%$/.test( value ); // ActiveCampaign

/**
 * Whether the reader arrived from a newsletter email flagged as a donor.
 *
 * A reader clicking a link in a newsletter lands on a URL carrying
 * `np_seg_donor`, whose value the ESP substitutes per recipient from the
 * publisher's donor merge field. The URL param is authoritative on the landing
 * page, so it is honored even when the arrival cannot be persisted. When a
 * positive donor value is detected, the arrival is also remembered for the rest
 * of the browsing session via sessionStorage so the reader keeps matching donor
 * segments as they navigate to clean URLs.
 *
 * This is segmentation-only and transient — it is never written to the persisted
 * reader data store, so it cannot grant content access, does not affect
 * analytics or ad targeting, and never persists across sessions. Mirrors the
 * `isFromEmail()` pattern in newsletter.js.
 *
 * @return {boolean} True if the reader arrived this session as a flagged donor.
 */
export function isDonorFromEmail() {
	const value = new URLSearchParams( window.location.search ).get( DONOR_PARAM );
	// The URL param is authoritative on the landing page, even if nothing can be
	// persisted. An unsubstituted merge tag means the send was misconfigured, so
	// it is ignored rather than treated as a donor value.
	if ( value !== null && ! isUnsubstitutedMergeTag( value ) && isDonorValue( value ) ) {
		// Remember the arrival for the rest of the session so the reader keeps
		// matching after navigating to clean URLs. A write failure (e.g. private
		// mode) only costs the cross-navigation memory, not this detection.
		try {
			window.sessionStorage.setItem( FROM_EMAIL_DONOR_KEY, '1' );
		} catch ( e ) {
			// sessionStorage unavailable; the URL signal still stands.
		}
		return true;
	}
	// No positive param on this page — fall back to whatever was remembered earlier.
	try {
		return window.sessionStorage.getItem( FROM_EMAIL_DONOR_KEY ) === '1';
	} catch ( e ) {
		// sessionStorage unavailable. Fail closed.
		return false;
	}
}

/**
 * Matching function for the 'donation' criteria.
 *
 * @param {Object} config    The segment criteria config.
 * @param {Object} ras       The reader activation object.
 * @param {Object} ras.store The reader data library store.
 * @return {boolean} Whether the criteria matches.
 */
export function matchDonation( config, { store } ) {
	switch ( config.value ) {
		case 'donors':
			return store.get( 'is_donor' ) || isDonorFromEmail();
		case 'non-donors':
			return ! ( store.get( 'is_donor' ) || isDonorFromEmail() );
		case 'formers-donors':
			return store.get( 'is_former_donor' );
	}
}

setMatchingFunction( 'donation', matchDonation );
