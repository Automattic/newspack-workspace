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

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const PAGE_COL = { key: 'page_title', label: __( 'Article', 'newspack-plugin' ) };

const ContentEngagementSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-engagement-content">
		<h2 id="newspack-insights-engagement-content" className="newspack-insights__section-heading">
			{ __( 'Content engagement', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( 'What holds reader attention.', 'newspack-plugin' ) }</p>
		{ /* 2-col grid: Most-Read + Completion Rate share row 1; Top Authors wraps
		     to row 2, occupying one column (~50%, left-aligned) so 10-row tables
		     have width and the third doesn't stretch full-width. */ }
		<div className="newspack-insights__table-grid newspack-insights__table-grid--cols-2">
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Most-Read Articles', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.most_read_articles }
					emptyMessage={ __( 'No article engagement data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						PAGE_COL,
						{ key: 'unique_readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'avg_engagement_seconds', label: __( 'Avg time', 'newspack-plugin' ), format: 'duration', align: 'right' },
					] }
				/>
			</div>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Articles by Completion Rate', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.articles_by_completion_rate }
					emptyMessage={ __( 'No scroll-completion data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						PAGE_COL,
						{ key: 'readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'completion_rate', label: __( 'Read to end', 'newspack-plugin' ), format: 'percent', align: 'right' },
					] }
				/>
			</div>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Authors by Avg Engagement Time', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_authors_by_avg_engagement_time }
					emptyMessage={ __( 'No author engagement data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'author', label: __( 'Author', 'newspack-plugin' ) },
						{ key: 'unique_readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'avg_engagement_seconds', label: __( 'Avg time', 'newspack-plugin' ), format: 'duration', align: 'right' },
					] }
				/>
			</div>
		</div>
	</section>
);

export default ContentEngagementSection;
