/**
 * Engagement › Content engagement (NPPD-1649, Section 2).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { InsightsWindow } from '../../../api/audience';
import MetricTable from '../../components/MetricTable';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';
import { SHOW_COMPLETION_METRICS } from '../constants';
import { Grid } from '../../../../../../packages/components/src';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const ARTICLE_COL = { key: 'page_title', label: __( 'Article', 'newspack-plugin' ) };

const ContentEngagementSection = ( { current }: SectionProps ) => (
	<Section className="newspack-insights__section" aria-labelledby="newspack-insights-engagement-content">
		<SectionHeading
			id="newspack-insights-engagement-content"
			title={ __( 'Content engagement', 'newspack-plugin' ) }
			description={ __( 'What holds reader attention.', 'newspack-plugin' ) }
		/>
		{ /* 2-col grid. With completion shown: Most-Engaged + Completion share row 1
		     and Top Authors wraps to row 2 (one column, ~50%, left-aligned). With
		     completion hidden (SHOW_COMPLETION_METRICS=false) the two remaining
		     tables fill row 1 as a clean 2-up — no full-width stretch. */ }
		<Grid columns={ 2 } gutter={ 16 } noMargin>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Most-engaged articles', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.most_read_articles }
					emptyMessage={ __( 'No article engagement data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						ARTICLE_COL,
						{ key: 'unique_readers', label: __( 'Engaged readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'avg_engagement_seconds', label: __( 'Avg time', 'newspack-plugin' ), format: 'duration', align: 'right' },
					] }
				/>
			</div>
			{ /* Completion is GA4-scroll-derived; hidden until scroll data flows. See ../constants. */ }
			{ SHOW_COMPLETION_METRICS && (
				<div>
					<h3 className="newspack-insights__chart-card-title">{ __( 'Articles by completion rate', 'newspack-plugin' ) }</h3>
					<MetricTable
						payload={ current.articles_by_completion_rate }
						emptyMessage={ __( 'No scroll-completion data in this timeframe.', 'newspack-plugin' ) }
						columns={ [
							ARTICLE_COL,
							{ key: 'readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
							{ key: 'completion_rate', label: __( 'Read to end', 'newspack-plugin' ), format: 'percent', align: 'right' },
						] }
					/>
				</div>
			) }
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top authors by avg engagement time', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_authors_by_avg_engagement_time }
					emptyMessage={ __( 'No author engagement data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'author', label: __( 'Author', 'newspack-plugin' ) },
						{ key: 'unique_readers', label: __( 'Engaged readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'avg_engagement_seconds', label: __( 'Avg time', 'newspack-plugin' ), format: 'duration', align: 'right' },
					] }
				/>
			</div>
		</Grid>
	</Section>
);

export default ContentEngagementSection;
