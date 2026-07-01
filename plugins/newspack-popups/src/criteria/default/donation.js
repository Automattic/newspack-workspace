import { setMatchingFunction } from '../utils';
import { rememberSessionSignal } from './session-signal';

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
 * Covers all four ESPs supported by Newspack Newsletters:
 *   Mailchimp        *|FIELD|*
 *   Constant Contact [[FIELD]]
 *   ActiveCampaign   %FIELD%
 *   Campaign Monitor [FIELD]
 *
 * Rejecting the bare-bracket Campaign Monitor form would be risky for an
 * arbitrary query value, but `np_seg_donor` only ever carries a donor-status
 * value (e.g. `true`, `monthly`, `$50.00`) — never a `[…]`-wrapped string — so
 * matching it here is safe and keeps the inbound guard symmetric with every tag
 * get_merge_tag() can emit.
 *
 * @param {string} value The decoded query-param value.
 * @return {boolean} True when the value contains raw merge-tag syntax.
 */
const isUnsubstitutedMergeTag = value =>
	/^\*\|[^|]+\|\*$/.test( value ) || // Mailchimp
	/^\[\[[^\]]+\]\]$/.test( value ) || // Constant Contact
	/^%[^%]+%$/.test( value ) || // ActiveCampaign
	/^\[[^\][]+\]$/.test( value ); // Campaign Monitor

/**
 * Whether the reader arrived from a newsletter email flagged as a donor.
 *
 * A reader clicking a link in a newsletter lands on a URL carrying
 * `np_seg_donor`, whose value the ESP substitutes per recipient from the
 * publisher's donor merge field. A positive value is detected and remembered
 * for the rest of the browsing session so the reader keeps matching donor
 * segments as they navigate to clean URLs. An unsubstituted merge tag means the
 * send was misconfigured, so it is ignored rather than treated as a donor value.
 * See rememberSessionSignal() for the segmentation-only, transient guarantees.
 *
 * @return {boolean} True if the reader arrived this session as a flagged donor.
 */
export function isDonorFromEmail() {
	return rememberSessionSignal( {
		param: DONOR_PARAM,
		sessionKey: FROM_EMAIL_DONOR_KEY,
		isPositive: value => ! isUnsubstitutedMergeTag( value ) && isDonorValue( value ),
	} );
}

/**
 * Matching function for the 'donation' criteria.
 *
 * Readers arriving from a newsletter flagged as donors (`np_seg_donor`) match
 * `donors`; a falsy or absent signal leaves them matching `non-donors`, the same
 * as a reader who never clicked — the inbound flag only ever adds donors, there
 * is no separate "unknown" state.
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
