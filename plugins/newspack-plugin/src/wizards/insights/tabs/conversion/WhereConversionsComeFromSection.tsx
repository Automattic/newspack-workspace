/**
 * WhereConversionsComeFromSection (NPPD-1609, Section 3).
 *
 * Three side-by-side source-mix PieCharts (registrations, subscribers,
 * donors), each split gate / prompt / direct with the window total at the
 * donut center. Phase 1 renders the empty state per pie.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionSourceMixData } from '../../api/conversion';
import SectionHeading from '../components/SectionHeading';
import { formatNumber } from '../components/format';
import { sourceLabel } from './labels';
import PieChart from './viz/PieChart';

export interface WhereConversionsComeFromSectionProps {
	current: {
		source_mix_registrations: ConversionSourceMixData;
		source_mix_subscribers: ConversionSourceMixData;
		source_mix_donors: ConversionSourceMixData;
	};
}

interface SourcePieProps {
	title: string;
	data: ConversionSourceMixData;
	emptyMessage: string;
}

const SourcePie = ( { title, data, emptyMessage }: SourcePieProps ) => (
	<div className="newspack-insights__conversion-pie-cell">
		<h3 className="newspack-insights__conversion-subheading">{ title }</h3>
		<PieChart
			segments={ data.slices.map( slice => ( { label: sourceLabel( slice.source ), value: slice.count } ) ) }
			centerLabel={ formatNumber( data.total ) }
			emptyMessage={ emptyMessage }
		/>
	</div>
);

const WhereConversionsComeFromSection = ( { current }: WhereConversionsComeFromSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--source-mix"
		aria-labelledby="newspack-insights-conversion-source-mix-heading"
	>
		<SectionHeading
			id="newspack-insights-conversion-source-mix-heading"
			title={ __( 'Where conversions come from', 'newspack-plugin' ) }
			description={ __(
				'Source attribution for new conversions in the window. Gate, prompt, or direct (standalone form) — which surfaces drive your registrations, subscriptions, and donations?',
				'newspack-plugin'
			) }
		/>
		<div className="newspack-insights__conversion-pie-row">
			<SourcePie
				title={ __( 'New registrations', 'newspack-plugin' ) }
				data={ current.source_mix_registrations }
				emptyMessage={ __( 'Source data will appear once registrations occur in this window.', 'newspack-plugin' ) }
			/>
			<SourcePie
				title={ __( 'New subscribers', 'newspack-plugin' ) }
				data={ current.source_mix_subscribers }
				emptyMessage={ __( 'Source data will appear once subscriptions occur in this window.', 'newspack-plugin' ) }
			/>
			<SourcePie
				title={ __( 'New donors', 'newspack-plugin' ) }
				data={ current.source_mix_donors }
				emptyMessage={ __( 'Source data will appear once donations occur in this window.', 'newspack-plugin' ) }
			/>
		</div>
	</section>
);

export default WhereConversionsComeFromSection;
