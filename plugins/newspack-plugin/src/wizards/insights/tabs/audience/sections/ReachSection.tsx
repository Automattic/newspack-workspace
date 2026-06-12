/**
 * Audience › Reach (NPPD-1649, Section 1).
 *
 * How many readers and sessions you reached in this timeframe.
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

const ReachSection = ( { current, previous, lastUpdated }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-reach">
		<SectionHeading
			id="newspack-insights-audience-reach"
			title={ __( 'Reach', 'newspack-plugin' ) }
			description={ __( 'Your reach this period.', 'newspack-plugin' ) }
			actions={ lastUpdated }
		/>
		<div className="newspack-insights__metric-grid newspack-insights__metric-grid--cols-4">
			<Scorecard
				label={ __( 'Active Readers', 'newspack-plugin' ) }
				description={ __( 'How many people read you', 'newspack-plugin' ) }
				current={ current.active_readers }
				previous={ previous?.active_readers }
			/>
			<Scorecard
				label={ __( 'Pageviews', 'newspack-plugin' ) }
				description={ __( 'Total page views', 'newspack-plugin' ) }
				current={ current.pageviews }
				previous={ previous?.pageviews }
			/>
			<Scorecard
				label={ __( 'Avg Sessions per Reader', 'newspack-plugin' ) }
				description={ __( 'How often readers come back', 'newspack-plugin' ) }
				current={ current.avg_sessions_per_reader }
				previous={ previous?.avg_sessions_per_reader }
			/>
			<Scorecard
				label={ __( 'Newsletter Signups', 'newspack-plugin' ) }
				description={ __( 'New subscribers this period', 'newspack-plugin' ) }
				current={ current.newsletter_signups }
				previous={ previous?.newsletter_signups }
			/>
		</div>
	</section>
);

export default ReachSection;
