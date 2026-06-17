/**
 * ReaderLifecycleSection (NPPD-1609, Section 1).
 *
 * The marquee view: a single full-width five-stage funnel from anonymous
 * reader to supporter. Rendering is gated on the metric's `state` envelope
 * (Phase 2): populated → funnel; empty → empty treatment; error → error note.
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ConversionWindow } from '../../api/conversion';
import SectionHeading from '../components/SectionHeading';
import Funnel from '../components/Funnel';
import SectionState from './SectionState';

export interface ReaderLifecycleSectionProps {
	current: ConversionWindow;
	lastUpdated?: ReactNode;
}

const ReaderLifecycleSection = ( { current, lastUpdated }: ReaderLifecycleSectionProps ) => (
	<section
		className="newspack-insights__section newspack-insights__section--reader-lifecycle"
		aria-labelledby="newspack-insights-conversion-lifecycle-heading"
	>
		<SectionHeading
			id="newspack-insights-conversion-lifecycle-heading"
			title={ __( 'The reader lifecycle', 'newspack-plugin' ) }
			description={ __(
				'The marquee view. How readers progress from first-time visitor through engagement, registration, and newsletter signup to becoming a supporter.',
				'newspack-plugin'
			) }
			actions={ lastUpdated }
		/>
		<SectionState
			state={ current.reader_lifecycle_funnel.state }
			emptyMessage={ __( 'No funnel data yet. The funnel will populate once readers begin moving through your site.', 'newspack-plugin' ) }
		>
			<Funnel stages={ current.reader_lifecycle_funnel.stages } />
		</SectionState>
	</section>
);

export default ReaderLifecycleSection;
