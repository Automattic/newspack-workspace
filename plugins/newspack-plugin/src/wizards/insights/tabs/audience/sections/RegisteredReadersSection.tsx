/**
 * Audience › Registered readers (NPPD-1733).
 *
 * The one Audience section sourced from the local wp_users table rather than
 * GA4. It is the foundational audience population in the funnel
 * (visitors → registered readers → subscribers → donors) and the canonical count
 * behind the GA4 "known reader" segment shown in Composition below — the section
 * description names that relationship so the two numbers don't read as competing.
 *
 * Two cards, both via MetricCard directly (not Scorecard) so the wp_users
 * query-error edge state can use the NPPD-1698 notComputable em-dash + line:
 *   - Total registered readers — all-time snapshot, no period delta. A real 0
 *     (new publisher) renders as an honest 0, not an empty state.
 *   - New registered readers — accounts created in the timeframe, with a period
 *     delta vs the previous equal-length timeframe. The delta is suppressed when
 *     none were created (a "↓100%" against a real prior would misread an honest
 *     zero), mirroring Subscribers' "New subscribers" card.
 *
 * Rendered in both AudienceTab branches: alongside the GA4 sections normally, and
 * directly under the connect banner when GA4 isn't connected (this count doesn't
 * need GA4).
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Grid } from '../../../../../../packages/components/src';
import type { MetricPayload, RegisteredReaders } from '../../../api/audience';
import MetricCard from '../../components/MetricCard';
import Section from '../../components/Section';
import SectionHeading from '../../components/SectionHeading';

export interface RegisteredReadersSectionProps {
	registeredReaders: RegisteredReaders;
	/** Whether the comparison toggle is on — gates the "new" card's period delta. */
	showComparison: boolean;
}

/** A count payload is usable when it didn't fail server-side and carries a number. */
const readCount = ( payload: MetricPayload | null | undefined ): number | null =>
	payload && payload.computable !== false && typeof payload.value === 'number' ? payload.value : null;

const UNAVAILABLE_MESSAGE = __( 'Registered reader count is unavailable right now.', 'newspack-plugin' );

const RegisteredReadersSection = ( { registeredReaders, showComparison }: RegisteredReadersSectionProps ) => {
	const { total, new: newReaders } = registeredReaders;

	const totalCount = readCount( total );
	const newCount = readCount( newReaders.current );

	// Suppress the period delta when no accounts were created this timeframe: a
	// "↓100%" against a real prior count would misread an honest zero.
	const previousNew = showComparison && newCount !== 0 ? readCount( newReaders.previous ) : null;

	return (
		<Section className="newspack-insights__section" aria-labelledby="newspack-insights-audience-registered-readers">
			<SectionHeading
				id="newspack-insights-audience-registered-readers"
				title={ __( 'Registered readers', 'newspack-plugin' ) }
				description={ __( 'People who registered on your site — the known-reader population behind the segments below.', 'newspack-plugin' ) }
			/>
			<Grid columns={ 4 } gutter={ 16 } noMargin>
				<MetricCard
					label={ __( 'Total registered readers', 'newspack-plugin' ) }
					value={ totalCount ?? 0 }
					format="number"
					// Window-independent snapshot: never a period delta.
					notComputableMessage={ totalCount === null ? UNAVAILABLE_MESSAGE : undefined }
					description={ __( 'All-time registrations on your site', 'newspack-plugin' ) }
				/>
				<MetricCard
					label={ __( 'New registered readers', 'newspack-plugin' ) }
					value={ newCount ?? 0 }
					format="number"
					previousValue={ previousNew }
					notComputableMessage={ newCount === null ? UNAVAILABLE_MESSAGE : undefined }
					description={ __( 'Registrations created in this timeframe', 'newspack-plugin' ) }
				/>
			</Grid>
		</Section>
	);
};

export default RegisteredReadersSection;
