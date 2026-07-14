/**
 * DownloadsSection (App tab, Tab 10 — NPPD-1882).
 *
 * Edition-download activity — the heartbeat of a Pugpig app: downloads started
 * vs completed, and the completion rate. Event-based (`BoltDownload*`), so it
 * works on any Pugpig app property. (`BoltEditionOpened` was dropped — it was
 * never confirmed in live data and read as a misleading 0.)
 *
 * Multi-property apps (one app serving several publications) tag downloads with
 * a collection, so those get an extra "Downloads by publication" table. Downloads
 * carry no section/category (they're whole-edition), so this is the only
 * meaningful download breakdown. Single-property apps (one or no collection)
 * omit the table.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AppMetrics } from '../../api/app';
import type { MetricPayload } from '../components/metrics';
import { Grid } from '../../../../../packages/components/src';
import Scorecard from '../components/Scorecard';
import ChartCard from '../components/ChartCard';
import MetricTable from '../components/MetricTable';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import { titleCase } from './collections';

export interface DownloadsSectionProps {
	metrics: AppMetrics;
	previous?: AppMetrics | null;
}

const DownloadsSection = ( { metrics, previous }: DownloadsSectionProps ) => {
	// The per-publication table is only meaningful for a multi-property app —
	// i.e. when downloads split across 2+ collections. Title-case the raw values.
	const collection = metrics.downloads_by_collection;
	const collectionRows = collection?.computable && Array.isArray( collection.rows ) ? collection.rows : [];
	const byPublication: MetricPayload | null =
		collectionRows.length >= 2
			? { ...collection, rows: collectionRows.map( row => ( { ...row, collection: titleCase( String( row.collection ?? '' ) ) } ) ) }
			: null;

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-app-downloads-heading">
			<SectionHeading
				id="newspack-insights-app-downloads-heading"
				title={ __( 'Downloads', 'newspack-plugin' ) }
				description={ __( 'How readers download your stories to read offline.', 'newspack-plugin' ) }
			/>
			<Grid columns={ 3 } gutter={ 16 } noMargin>
				<Scorecard
					label={ __( 'Downloads Started', 'newspack-plugin' ) }
					description={ __( 'Downloads readers began', 'newspack-plugin' ) }
					current={ metrics.downloads_started }
					previous={ previous?.downloads_started }
				/>
				<Scorecard
					label={ __( 'Downloads Completed', 'newspack-plugin' ) }
					description={ __( 'Downloads that finished successfully', 'newspack-plugin' ) }
					current={ metrics.downloads_completed }
					previous={ previous?.downloads_completed }
				/>
				<Scorecard
					label={ __( 'Download Completion Rate', 'newspack-plugin' ) }
					description={ __( 'Share of started downloads that completed', 'newspack-plugin' ) }
					current={ metrics.download_completion_rate }
					previous={ previous?.download_completion_rate }
				/>
			</Grid>
			{ byPublication && (
				<div className="newspack-insights__chart-grid">
					<ChartCard
						title={ __( 'Downloads by Publication', 'newspack-plugin' ) }
						caption={ __( 'Completed downloads across the titles your app serves', 'newspack-plugin' ) }
						payload={ byPublication }
					>
						<MetricTable
							payload={ byPublication }
							columns={ [
								{ key: 'collection', label: __( 'Publication', 'newspack-plugin' ) },
								{ key: 'downloads', label: __( 'Downloads', 'newspack-plugin' ), format: 'number', align: 'right' },
							] }
							emptyMessage={ __( 'No download data for this timeframe.', 'newspack-plugin' ) }
						/>
					</ChartCard>
				</div>
			) }
		</Section>
	);
};

export default DownloadsSection;
