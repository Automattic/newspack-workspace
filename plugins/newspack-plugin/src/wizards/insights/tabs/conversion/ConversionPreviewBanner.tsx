/**
 * ConversionPreviewBanner (NPPD-1609, Phase 1 only).
 *
 * Top-of-tab banner rendered above Section 1 during the placeholder phase,
 * signaling that the tab's structure is final but real data is pending.
 * Removed entirely when Phase 2 lands.
 *
 * A thin wrapper over the shared InfoCallout: session-only dismissal
 * (`persist={ false }`, reappears on reload per the spec) plus a light-blue
 * `--preview` variant class to distinguish it from the gray freshness note
 * below it.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import InfoCallout from '../components/InfoCallout';

const ConversionPreviewBanner = () => (
	<InfoCallout
		heading={ __( 'This tab is live in preview mode.', 'newspack-plugin' ) }
		dismissible
		persist={ false }
		className="newspack-insights__info-callout--preview"
	>
		<p>
			{ __(
				'Real-time metrics will populate once BigQuery integration is complete. The structure, sections, and visualizations are final.',
				'newspack-plugin'
			) }
		</p>
	</InfoCallout>
);

export default ConversionPreviewBanner;
