/**
 * EditionsSection (App tab, Tab 10 — NPPD-1882).
 *
 * Edition activity — the heartbeat of a Pugpig app: downloads started vs
 * completed (and the completion rate), plus edition opens. Event-based, so it
 * works on any Pugpig app property. Fourth of the Tier-1 app metric sections.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AppMetrics } from '../../api/app';
import Scorecard from '../components/Scorecard';
import SectionHeading from '../components/SectionHeading';

export interface EditionsSectionProps {
	metrics: AppMetrics;
}

const EditionsSection = ( { metrics }: EditionsSectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-app-editions-heading">
		<SectionHeading
			id="newspack-insights-app-editions-heading"
			title={ __( 'Editions', 'newspack-plugin' ) }
			description={ __( 'How readers download and open your editions.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__metric-grid">
			<Scorecard
				label={ __( 'Downloads started', 'newspack-plugin' ) }
				description={ __( 'Edition downloads begun in this timeframe.', 'newspack-plugin' ) }
				current={ metrics.downloads_started }
			/>
			<Scorecard
				label={ __( 'Downloads completed', 'newspack-plugin' ) }
				description={ __( 'Edition downloads that finished successfully.', 'newspack-plugin' ) }
				current={ metrics.downloads_completed }
			/>
			<Scorecard
				label={ __( 'Download completion rate', 'newspack-plugin' ) }
				description={ __( 'Share of started edition downloads that completed.', 'newspack-plugin' ) }
				current={ metrics.download_completion_rate }
			/>
			<Scorecard
				label={ __( 'Edition opens', 'newspack-plugin' ) }
				description={ __( 'Times readers opened an edition in this timeframe.', 'newspack-plugin' ) }
				current={ metrics.edition_opens }
			/>
		</div>
	</section>
);

export default EditionsSection;
