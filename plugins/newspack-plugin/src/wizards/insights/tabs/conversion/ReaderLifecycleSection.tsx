/**
 * ReaderLifecycleSection (NPPD-1609, Section 1).
 *
 * The marquee view: a single full-width five-stage funnel from anonymous
 * reader to supporter. Scaffold renders the header + caption + an empty
 * placeholder; the Funnel viz is wired in the following commit.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';

export interface ReaderLifecycleSectionProps {
	current: ConversionWindow;
}

const ReaderLifecycleSection = ( { current }: ReaderLifecycleSectionProps ) => {
	const data = current.reader_lifecycle_funnel;
	return (
		<section
			className="newspack-insights__section newspack-insights__section--reader-lifecycle"
			aria-labelledby="newspack-insights-conversion-lifecycle-heading"
		>
			<h2 id="newspack-insights-conversion-lifecycle-heading" className="newspack-insights__section-heading">
				{ __( 'The reader lifecycle', 'newspack-plugin' ) }
			</h2>
			<p className="newspack-insights__section-caption">
				{ __(
					'The marquee view. How readers progress from first-time visitor through engagement, registration, and newsletter signup to becoming a supporter.',
					'newspack-plugin'
				) }
			</p>
			<div className="newspack-insights__viz-placeholder" data-pending={ data.pending } />
		</section>
	);
};

export default ReaderLifecycleSection;
