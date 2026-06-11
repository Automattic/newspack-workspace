/**
 * Audience › Content performance (NPPD-1649, Section 6).
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

const ContentPerformanceSection = ( { current }: SectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-content">
		<h2 id="newspack-insights-audience-content" className="newspack-insights__section-heading">
			{ __( 'Content performance', 'newspack-plugin' ) }
		</h2>
		<p className="newspack-insights__section-caption">{ __( "What's getting read.", 'newspack-plugin' ) }</p>
		<div className="newspack-insights__table-grid">
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Pages', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_pages }
					emptyMessage={ __( 'No page data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'page_title', label: __( 'Page', 'newspack-plugin' ) },
						{ key: 'unique_readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'pageviews', label: __( 'Pageviews', 'newspack-plugin' ), format: 'number', align: 'right' },
					] }
				/>
			</div>
			<div>
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top Authors by Reader Count', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_authors_by_reader_count }
					emptyMessage={ __( 'No author data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'author', label: __( 'Author', 'newspack-plugin' ) },
						{ key: 'unique_readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'pageviews', label: __( 'Pageviews', 'newspack-plugin' ), format: 'number', align: 'right' },
					] }
				/>
			</div>
			{ /* Top Categories is hidden_in_v1 (needs BQ UNNEST); it skip-renders until the BQ catalog ships. */ }
			{ ! current.top_categories?.hidden_in_v1 && (
				<div>
					<h3 className="newspack-insights__chart-card-title">{ __( 'Top Categories', 'newspack-plugin' ) }</h3>
					<MetricTable
						payload={ current.top_categories }
						emptyMessage={ __( 'No category data in this timeframe.', 'newspack-plugin' ) }
						columns={ [
							{ key: 'category', label: __( 'Category', 'newspack-plugin' ) },
							{ key: 'unique_readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
							{ key: 'pageviews', label: __( 'Pageviews', 'newspack-plugin' ), format: 'number', align: 'right' },
						] }
					/>
				</div>
			) }
		</div>
	</section>
);

export default ContentPerformanceSection;
