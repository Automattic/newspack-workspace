/**
 * WhereConversionsComeFromSection (NPPD-1609, Section 3).
 *
 * Three side-by-side source-mix PieCharts (registrations, subscribers,
 * donors), each split gate / prompt / direct. Scaffold renders header +
 * caption + an empty placeholder; the PieChart viz is wired in the
 * following commit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';

export interface WhereConversionsComeFromSectionProps {
	current: ConversionWindow;
}

const WhereConversionsComeFromSection = ( { current }: WhereConversionsComeFromSectionProps ) => {
	const pending = current.source_mix_registrations.pending;
	return (
		<section
			className="newspack-insights__section newspack-insights__section--source-mix"
			aria-labelledby="newspack-insights-conversion-source-mix-heading"
		>
			<h2 id="newspack-insights-conversion-source-mix-heading" className="newspack-insights__section-heading">
				{ __( 'Where conversions come from', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __(
					'Source attribution for new conversions in the window. Gate, prompt, or direct (standalone form) — which surfaces drive your registrations, subscriptions, and donations?',
					'newspack-plugin'
				) }
			</p>
			<div className="newspack-insights__viz-placeholder" data-pending={ pending } />
		</section>
	);
};

export default WhereConversionsComeFromSection;
