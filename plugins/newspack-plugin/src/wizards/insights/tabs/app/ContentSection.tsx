/**
 * ContentSection (App tab, Tab 10 — NPPD-1882, Tier-2a).
 *
 * What readers actually read in the app: top sections and top authors, ranked
 * by screen views. Sourced from the Pugpig `KGSection` / `KGAuthor` custom
 * dimensions, so each card renders its "not configured" state on properties
 * where those dimensions aren't registered yet (auto-registration is Tier-2b).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AppMetrics } from '../../api/app';
import ChartCard from '../components/ChartCard';
import MetricTable from '../components/MetricTable';
import SectionHeading from '../components/SectionHeading';

export interface ContentSectionProps {
	metrics: AppMetrics;
}

const ContentSection = ( { metrics }: ContentSectionProps ) => (
	<section className="newspack-insights__section" aria-labelledby="newspack-insights-app-content-heading">
		<SectionHeading
			id="newspack-insights-app-content-heading"
			title={ __( 'Content', 'newspack-plugin' ) }
			description={ __( 'The sections and authors readers engage with most in the app.', 'newspack-plugin' ) }
		/>
		<div className="newspack-insights__chart-grid newspack-insights__chart-grid--cols-2">
			<ChartCard
				title={ __( 'Top sections', 'newspack-plugin' ) }
				caption={ __( 'Screen views by section', 'newspack-plugin' ) }
				payload={ metrics.top_sections }
			>
				<MetricTable
					payload={ metrics.top_sections }
					columns={ [
						{ key: 'section', label: __( 'Section', 'newspack-plugin' ) },
						{ key: 'views', label: __( 'Views', 'newspack-plugin' ), format: 'number', align: 'right' },
					] }
					emptyMessage={ __( 'No section data for this timeframe.', 'newspack-plugin' ) }
				/>
			</ChartCard>
			<ChartCard
				title={ __( 'Top authors', 'newspack-plugin' ) }
				caption={ __( 'Screen views by author', 'newspack-plugin' ) }
				payload={ metrics.top_authors }
			>
				<MetricTable
					payload={ metrics.top_authors }
					columns={ [
						{ key: 'author', label: __( 'Author', 'newspack-plugin' ) },
						{ key: 'views', label: __( 'Views', 'newspack-plugin' ), format: 'number', align: 'right' },
					] }
					emptyMessage={ __( 'No author data for this timeframe.', 'newspack-plugin' ) }
				/>
			</ChartCard>
		</div>
	</section>
);

export default ContentSection;
