/**
 * CohortRetentionFreshnessCallout (NPPD-1609).
 *
 * Small info callout below the Phase 1 preview banner and above Section 1,
 * explaining the pre-warm-and-cache pattern so publishers interpret the
 * Section 5 cohort-retention curves correctly (weekly snapshot, not
 * real-time) before they read them.
 *
 * A thin wrapper over the shared InfoCallout with session-only dismissal
 * (`persist={ false }`) per the spec ("one-time display per session").
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import InfoCallout from '../components/InfoCallout';

const CohortRetentionFreshnessCallout = () => (
	<InfoCallout heading={ __( 'About cohort retention freshness', 'newspack-plugin' ) } dismissible persist={ false }>
		<p>
			{ __(
				'Cohort retention metrics are pre-computed and refreshed weekly. The values on this page reflect the most recent weekly snapshot, not real-time data. This is intentional — the queries that produce cohort curves are too expensive to run on every page load, and weekly granularity is the appropriate cadence for retention analysis.',
				'newspack-plugin'
			) }
		</p>
		<p>
			{ __(
				'Other metrics on this tab (funnels, source mix, conversion rates, time-to-convert) update with each page view within the selected window.',
				'newspack-plugin'
			) }
		</p>
	</InfoCallout>
);

export default CohortRetentionFreshnessCallout;
