/**
 * Engagement › Overall engagement quality (NPPD-1649, Section 1).
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/audience';
import Scorecard from '../../components/Scorecard';
import SectionHeading from '../../components/SectionHeading';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
	lastUpdated?: ReactNode;
}

const QualitySection = ( { current, previous, lastUpdated }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-engagement-quality">
		<SectionHeading
			id="newspack-insights-engagement-quality"
			title={ __( 'Overall engagement quality', 'newspack-plugin' ) }
			description={ __( 'How deeply readers engage.', 'newspack-plugin' ) }
			actions={ lastUpdated }
		/>
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
				label={ __( 'Completion Rate', 'newspack-plugin' ) }
				description={
					// "% of page views read to the end" is literal copy; the "%" is a percent sign, not a format placeholder.
					// eslint-disable-next-line @wordpress/i18n-translator-comments
					__( '% of page views read to the end', 'newspack-plugin' )
				}
				current={ current.article_completion_rate }
				previous={ previous?.article_completion_rate }
			/>
		</div>
	</section>
);

export default QualitySection;
