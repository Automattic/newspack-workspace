/**
 * Shared donor helpers for the Subscribers wizard.
 *
 * The kind of donor a reader is (One-time, Recurring, Former) is decided on the
 * server and arrives on each row as `donor.kind`; nothing here re-derives it. The
 * rule and every place that re-encodes it are documented in
 * Subscribers_Donors::kind(). The one client-side reading of it, the Donor field's
 * `none` value for a non-donor, has to match the `none` option the endpoint's
 * `donor` param accepts.
 */

/**
 * WordPress dependencies.
 */
import { __, _x } from '@wordpress/i18n';

export const DONOR_LABELS = {
	'one-time': __( 'One-time donor', 'newspack-plugin' ),
	recurring: __( 'Recurring donor', 'newspack-plugin' ),
	former: __( 'Former donor', 'newspack-plugin' ),
};

// The Donor filter's options, in the order the endpoint lists its `donor` values.
// `none` is the one option that excludes rather than includes: readers who are
// no kind of donor.
export const DONOR_FILTER_ELEMENTS = [
	{ value: 'one-time', label: _x( 'One-time', 'kind of donor', 'newspack-plugin' ) },
	{ value: 'recurring', label: _x( 'Recurring', 'kind of donor', 'newspack-plugin' ) },
	{ value: 'former', label: _x( 'Former', 'kind of donor', 'newspack-plugin' ) },
	{ value: 'none', label: __( 'Not a donor', 'newspack-plugin' ) },
];

// The badge sits beside the subscription statuses, which already use stable, low,
// medium and high, so a donor badge takes none of those: a shared colour would
// read as a status. Of the intents left, a reader who still gives (One-time or
// Recurring) is informational. Former is none, the intent the Badge guidelines
// give inactive and expired states; draft means work in progress.
/** @type {Record< string, import('../../../packages/components/src/types').BadgeIntent >} */
export const DONOR_BADGE_INTENT = {
	'one-time': 'informational',
	recurring: 'informational',
	former: 'none',
};

// The kind the endpoint sent for a row, or '' for a reader who isn't a donor, or
// on a site that sends no donor data at all.
export const donorKind = item => item?.donor?.kind || '';

// Whether this site tracks donors. Read when called rather than at import, so it
// reflects the server's config global as it stands at render.
export const donorsEnabled = () => !! window?.newspackSubscribers?.donorsEnabled;
