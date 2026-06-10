/**
 * CohortRetentionFreshnessCallout (NPPD-1609).
 *
 * Small info callout below the Phase 1 preview banner and above Section 1,
 * explaining the pre-warm-and-cache pattern so publishers interpret the
 * Section 5 cohort-retention curves correctly (weekly snapshot, not
 * real-time) before they read them.
 *
 * Reuses the shared `__info-callout` visual treatment, but with session-only
 * dismissal per the spec ("one-time display per session") — a self-contained
 * `useState`, like the DirectVsInfluencedCallout, rather than the shared
 * InfoCallout whose dismissible mode persists in localStorage.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Icon, closeSmall, info } from '@wordpress/icons';

const CohortRetentionFreshnessCallout = () => {
	const [ visible, setVisible ] = useState( true );
	if ( ! visible ) {
		return null;
	}
	return (
		<div className="newspack-insights__info-callout" role="note">
			<Icon icon={ info } className="newspack-insights__info-callout-icon" />
			<div className="newspack-insights__info-callout-body">
				<p className="newspack-insights__info-callout-title">
					<strong>{ __( 'About cohort retention freshness', 'newspack-plugin' ) }</strong>
				</p>
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
			</div>
			<button
				type="button"
				className="newspack-insights__info-callout-dismiss"
				onClick={ () => setVisible( false ) }
				aria-label={ __( 'Dismiss', 'newspack-plugin' ) }
			>
				<Icon icon={ closeSmall } />
			</button>
		</div>
	);
};

export default CohortRetentionFreshnessCallout;
