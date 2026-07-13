/**
 * ContentSection (App tab, Tab 10 — NPPD-1882).
 *
 * What readers actually read in the app: top sections and top authors, ranked
 * by screen views (`KGSection` / `KGAuthor`). Each card renders its "not
 * configured" state where those dims aren't registered.
 *
 * Multi-property apps (one app serving several publications) get a per-publication
 * selector: reading events are ~99% collection-tagged, so the tables can be scoped
 * cleanly to one publication via the `collection × section/author` matrices. Shown
 * only when 2+ collections exist; single-property apps see the app-wide tables.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { AppMetrics } from '../../api/app';
import { Grid } from '../../../../../packages/components/src';
import ChartCard from '../components/ChartCard';
import MetricTable from '../components/MetricTable';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import { collectionValues, titleCase, topByCollection } from './collections';

export interface ContentSectionProps {
	metrics: AppMetrics;
}

const ALL = 'all';

const ContentSection = ( { metrics }: ContentSectionProps ) => {
	const [ filter, setFilter ] = useState< string >( ALL );

	// Publications present in the content matrices. Only a multi-property app
	// (2+ collections) gets the selector; otherwise the app-wide tables render.
	const collections = collectionValues( metrics.sections_by_collection, metrics.authors_by_collection );
	const hasSelector = collections.length >= 2;
	// Guard against a stale selection after the data (timeframe/property) changes.
	const active = hasSelector && collections.includes( filter ) ? filter : ALL;

	const sections = active === ALL ? metrics.top_sections : topByCollection( metrics.sections_by_collection, active, 'section', 'views' );
	const authors = active === ALL ? metrics.top_authors : topByCollection( metrics.authors_by_collection, active, 'author', 'views' );

	const selector = hasSelector ? (
		<SelectControl
			__nextHasNoMarginBottom
			className="newspack-insights__app-collection-select"
			label={ __( 'Publication', 'newspack-plugin' ) }
			hideLabelFromVision
			value={ active }
			options={ [
				{ label: __( 'All publications', 'newspack-plugin' ), value: ALL },
				...collections.map( collection => ( { label: titleCase( collection ), value: collection } ) ),
			] }
			onChange={ setFilter }
		/>
	) : undefined;

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-app-content-heading">
			<SectionHeading
				id="newspack-insights-app-content-heading"
				title={ __( 'Content', 'newspack-plugin' ) }
				description={ __( 'The sections and authors readers engage with most in the app.', 'newspack-plugin' ) }
				actions={ selector }
			/>
			<Grid columns={ 2 } gutter={ 16 } noMargin>
				<ChartCard
					title={ __( 'Top sections', 'newspack-plugin' ) }
					caption={ __( 'Screen views by section', 'newspack-plugin' ) }
					payload={ sections }
				>
					<MetricTable
						payload={ sections }
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
					payload={ authors }
				>
					<MetricTable
						payload={ authors }
						columns={ [
							{ key: 'author', label: __( 'Author', 'newspack-plugin' ) },
							{ key: 'views', label: __( 'Views', 'newspack-plugin' ), format: 'number', align: 'right' },
						] }
						emptyMessage={ __( 'No author data for this timeframe.', 'newspack-plugin' ) }
					/>
				</ChartCard>
			</Grid>
		</Section>
	);
};

export default ContentSection;
