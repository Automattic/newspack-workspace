/**
 * PerformanceByGateSection (NPPD-1604, Section 5; DataViews migration NPPD-1889).
 *
 * Full-width per-gate breakdown table, rendered through the shared read-only
 * DataViews table (`InsightsDataView`). Sortable on every column; default sort
 * is impressions descending per spec. Numeric columns open DESC, the gate-name
 * column opens ASC, and null cells (em-dash) always sort to the bottom — all
 * handled by the wrapper.
 *
 * Phase 1 renders the shared empty-state copy since `rows` is empty (the
 * read-only DataViews table shows its column headers only once there are rows).
 * Phase 2 (NPPD-1630) populates `performance_by_gate.rows` from BQ, at which
 * point the table and click-to-sort appear.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink } from '@wordpress/components';

/**
 * Internal dependencies
 */
import type { GatesPerformanceRow, GatesPerformanceTable } from '../../api/gates';
import InsightsDataView from '../components/InsightsDataView';
import type { InsightsColumn } from '../components/InsightsDataView';
import { getPostEditUrl } from '../components/adminLinks';
import { formatNumber, formatPercent } from '../components/format';
import Section from '../components/Section';
import SectionHeading from '../components/SectionHeading';
import { SECTION_ERROR_MESSAGE } from './SectionState';
import { Card } from '../../../../../packages/components/src';

export interface PerformanceByGateSectionProps {
	data: GatesPerformanceTable;
}

const NotApplicable = () => (
	<span className="newspack-insights__table-na" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
		—
	</span>
);

const renderPercent = ( v: number | null ) => ( v === null ? <NotApplicable /> : formatPercent( v ) );

// Counts that can be N/A (paywall conversions on a regwall-only gate / non-WC) render
// an em-dash, matching the rate columns; a real 0 still renders as "0".
const renderCount = ( v: number | null ) => ( v === null ? <NotApplicable /> : formatNumber( v ) );

const columns: InsightsColumn< GatesPerformanceRow >[] = [
	{
		key: 'gate_name',
		label: __( 'Gate name', 'newspack-plugin' ),
		render: row => <ExternalLink href={ getPostEditUrl( row.gate_post_id ) }>{ row.gate_name }</ExternalLink>,
		sortValue: row => row.gate_name,
	},
	{
		key: 'impressions',
		label: __( 'Impressions', 'newspack-plugin' ),
		numeric: true,
		render: row => formatNumber( row.impressions ),
		sortValue: row => row.impressions,
	},
	{
		key: 'unique_viewers',
		label: __( 'Unique viewers', 'newspack-plugin' ),
		numeric: true,
		render: row => formatNumber( row.unique_viewers ),
		sortValue: row => row.unique_viewers,
	},
	{
		key: 'registrations',
		label: __( 'Registrations', 'newspack-plugin' ),
		numeric: true,
		render: row => formatNumber( row.registrations ),
		sortValue: row => row.registrations,
	},
	{
		key: 'regwall_conversion_rate',
		label: __( 'Registered access gate conversion rate', 'newspack-plugin' ),
		numeric: true,
		render: row => renderPercent( row.regwall_conversion_rate ),
		sortValue: row => row.regwall_conversion_rate,
	},
	{
		key: 'paywall_conversions',
		label: __( 'Paid access gate conversions', 'newspack-plugin' ),
		numeric: true,
		render: row => renderCount( row.paywall_conversions ),
		sortValue: row => row.paywall_conversions,
	},
	{
		key: 'paywall_conversion_rate',
		label: __( 'Paid access gate conversion rate', 'newspack-plugin' ),
		numeric: true,
		render: row => renderPercent( row.paywall_conversion_rate ),
		sortValue: row => row.paywall_conversion_rate,
	},
];

const PerformanceByGateSection = ( { data }: PerformanceByGateSectionProps ) => {
	// A failed query surfaces the shared error copy in place of the neutral
	// "no data yet" empty state (both render through the wrapper's empty slot,
	// since Phase 1 rows are empty).
	const emptyMessage =
		data.state === 'error'
			? SECTION_ERROR_MESSAGE
			: __( 'No gate data yet. Performance metrics will appear once readers begin interacting with your gates.', 'newspack-plugin' );

	return (
		<Section
			className="newspack-insights__section newspack-insights__section--performance"
			aria-labelledby="newspack-insights-gates-performance-heading"
		>
			<SectionHeading
				id="newspack-insights-gates-performance-heading"
				title={ __( 'Performance by gate', 'newspack-plugin' ) }
				description={ __( 'Per-gate breakdown for the selected timeframe. Click any column to re-sort.', 'newspack-plugin' ) }
			/>
			<Card __experimentalCoreCard className="newspack-insights__chart-card">
				<InsightsDataView< GatesPerformanceRow >
					columns={ columns }
					rows={ data.state === 'populated' ? data.rows : [] }
					getRowKey={ row => String( row.gate_post_id ) }
					defaultSortKey="impressions"
					emptyMessage={ emptyMessage }
				/>
			</Card>
		</Section>
	);
};

export default PerformanceByGateSection;
