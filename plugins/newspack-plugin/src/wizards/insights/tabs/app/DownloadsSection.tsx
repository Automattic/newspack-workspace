/**
 * DownloadsSection (App tab, Tab 10 — NPPD-1882).
 *
 * Edition-download activity — the heartbeat of a Pugpig app: downloads started
 * vs completed, and the completion rate. Event-based (`BoltDownload*`), so it
 * works on any Pugpig app property. (`BoltEditionOpened` was dropped — it was
 * never confirmed in live data and read as a misleading 0.)
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

export interface DownloadsSectionProps {
	metrics: AppMetrics;
	previous?: AppMetrics | null;
}

const DownloadsSection = ( { metrics, previous }: DownloadsSectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-app-downloads-heading">
		<SectionHeading
			id="newspack-insights-app-downloads-heading"
			title={ __( 'Downloads', 'newspack-plugin' ) }
			description={ __( 'How readers download your stories to read offline.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-3">
			<Scorecard
				label={ __( 'Downloads started', 'newspack-plugin' ) }
				description={ __( 'Downloads readers began', 'newspack-plugin' ) }
				current={ metrics.downloads_started }
				previous={ previous?.downloads_started }
			/>
			<Scorecard
				label={ __( 'Downloads completed', 'newspack-plugin' ) }
				description={ __( 'Downloads that finished successfully', 'newspack-plugin' ) }
				current={ metrics.downloads_completed }
				previous={ previous?.downloads_completed }
			/>
			<Scorecard
				label={ __( 'Download completion rate', 'newspack-plugin' ) }
				description={ __( 'Share of started downloads that completed', 'newspack-plugin' ) }
				current={ metrics.download_completion_rate }
				previous={ previous?.download_completion_rate }
			/>
		</div>
	</section>
);

export default DownloadsSection;
