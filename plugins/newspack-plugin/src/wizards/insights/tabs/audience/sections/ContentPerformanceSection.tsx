/**
 * Audience › Content performance (NPPD-1649, Section 6).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Card } from '../../../../../../packages/components/src';
import type { InsightsWindow } from '../../../api/audience';
import MetricTable from '../../components/MetricTable';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';

export interface SectionProps {
	current: InsightsWindow;
	previous: InsightsWindow | null;
}

const ContentPerformanceSection = ( { current }: SectionProps ) => (
	<Section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-content">
		<SectionHeading
			id="newspack-insights-audience-content"
			title={ __( 'Content Performance', 'newspack-plugin' ) }
			description={ __( "What's getting read.", 'newspack-plugin' ) }
		/>
		<VStack spacing={ 4 }>
			<Card __experimentalCoreCard className="newspack-insights__chart-card">
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top articles', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_pages }
					emptyMessage={ __( 'No article data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'page_title', label: __( 'Article', 'newspack-plugin' ) },
						{ key: 'unique_readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'pageviews', label: __( 'Pageviews', 'newspack-plugin' ), format: 'number', align: 'right' },
					] }
				/>
			</Card>
			<Card __experimentalCoreCard className="newspack-insights__chart-card">
				<h3 className="newspack-insights__chart-card-title">{ __( 'Top authors by reader count', 'newspack-plugin' ) }</h3>
				<MetricTable
					payload={ current.top_authors_by_reader_count }
					emptyMessage={ __( 'No author data in this timeframe.', 'newspack-plugin' ) }
					columns={ [
						{ key: 'author', label: __( 'Author', 'newspack-plugin' ) },
						{ key: 'unique_readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
						{ key: 'pageviews', label: __( 'Pageviews', 'newspack-plugin' ), format: 'number', align: 'right' },
					] }
				/>
			</Card>
			{ /* Top Categories is hidden_in_v1 (needs BQ UNNEST); it skip-renders until the BQ catalog ships. */ }
			{ ! current.top_categories?.hidden_in_v1 && (
				<Card __experimentalCoreCard className="newspack-insights__chart-card">
					<h3 className="newspack-insights__chart-card-title">{ __( 'Top categories', 'newspack-plugin' ) }</h3>
					<MetricTable
						payload={ current.top_categories }
						emptyMessage={ __( 'No category data in this timeframe.', 'newspack-plugin' ) }
						columns={ [
							{ key: 'category', label: __( 'Category', 'newspack-plugin' ) },
							{ key: 'unique_readers', label: __( 'Readers', 'newspack-plugin' ), format: 'number', align: 'right' },
							{ key: 'pageviews', label: __( 'Pageviews', 'newspack-plugin' ), format: 'number', align: 'right' },
						] }
					/>
				</Card>
			) }
		</VStack>
	</Section>
);

export default ContentPerformanceSection;
