/**
 * Engagement › Overall engagement quality (NPPD-1649, Section 1).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/audience';
import Scorecard from '../../components/Scorecard';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const QualitySection = ( { current, previous }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-engagement-quality">
		<h2 id="newspack-insights-engagement-quality" className="newspack-insights__section-heading">
			{ __( 'Overall engagement quality', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'How deeply readers engage.', 'newspack-plugin' ) }</p>
		<div className="newspack-insights__metric-grid">
			<Scorecard
				label={ __( 'Avg Pages per Session', 'newspack-plugin' ) }
				description={ __( 'Pages viewed per visit', 'newspack-plugin' ) }
				current={ current.avg_pages_per_session }
				previous={ previous?.avg_pages_per_session }
			/>
			<Scorecard
				label={ __( 'Avg Engaged Session Duration', 'newspack-plugin' ) }
				description={ __( 'Time spent per visit', 'newspack-plugin' ) }
				current={ current.avg_engaged_session_duration }
				previous={ previous?.avg_engaged_session_duration }
			/>
			<Scorecard
				label={ __( 'Bounce Rate', 'newspack-plugin' ) }
				description={ __( '% bounced', 'newspack-plugin' ) }
				current={ current.bounce_rate }
				previous={ previous?.bounce_rate }
				lowerIsBetter
			/>
			<Scorecard
				label={ __( 'Article Completion Rate', 'newspack-plugin' ) }
				description={
					// "% finished reading" is literal copy; the "%" is a percent sign, not a format placeholder.
					// eslint-disable-next-line @wordpress/i18n-translator-comments
					__( '% finished reading', 'newspack-plugin' )
				}
				current={ current.article_completion_rate }
				previous={ previous?.article_completion_rate }
			/>
		</div>
	</section>
);

export default QualitySection;
