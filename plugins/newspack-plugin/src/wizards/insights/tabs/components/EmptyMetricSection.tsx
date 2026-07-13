/**
 * EmptyMetricSection (NPPD-1694).
 *
 * Drop-in replacement for a scorecard section when the section would otherwise
 * render as a row of zeros. Keeps the section's identity (title + caption, via
 * the shared `SectionHeading`) and swaps the metric grid for a single
 * callout-styled, NON-dismissible note explaining why there's no data.
 *
 * The callout chrome comes from the `@wordpress/components` `Notice` (info
 * status, `isDismissible={ false }`) — the same primitive `InfoCallout` is built
 * on, so empty states read as a deliberate part of the design system rather than
 * a 404. We render the `Notice` directly rather than reuse `InfoCallout` because
 * an empty state has no separate bold heading (its identity lives in the
 * `SectionHeading` above) and never persists a dismissal.
 *
 * Distinct from `SectionEmpty`, which is the plain "no rows yet" paragraph for
 * collection metrics (funnel / distribution / tables). `EmptyMetricSection` is
 * the callout treatment for *scorecard* sections.
 */

/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';

/**
 * Internal dependencies
 */
import Section from './Section';
import SectionHeading from './SectionHeading';
import { formatNumber } from './format';

export type EmptyMetricSectionState = 'no_opportunity' | 'no_conversions' | 'configuration_missing';

export interface EmptyMetricSectionProps {
	/** Matches the normal-data section header. */
	title: string;
	/** Matches the normal-data section caption. */
	caption?: string;
	/** Which empty-state this is — surfaced as a data attribute for styling/QA. */
	state: EmptyMetricSectionState;
	/** Empty-state copy; sections pass their own. May contain the literal `{N}` placeholder. */
	body: string;
	/** Optional integer for `{N}` interpolation in `body`. */
	signalCount?: number;
}

/**
 * Slugify the title into a stable id for `aria-labelledby` / the SectionHeading.
 * Titles here are short fixed strings, so collisions aren't a concern in practice.
 */
const headingId = ( title: string ): string =>
	'newspack-insights-empty-' +
	title
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );

/**
 * Interpolate `{N}` with the formatted signal count. Per spec: only substitute
 * when a `signalCount` is actually provided AND the body contains `{N}`;
 * otherwise render the body verbatim (we deliberately do NOT strip a stray
 * `{N}` so a missing prop stays visible in QA rather than silently vanishing).
 * `formatNumber` (the app's shared formatter) keeps large counts readable and
 * consistent with every other count in the dashboard (1234567 → "1,234,567").
 */
const interpolate = ( body: string, signalCount?: number ): string =>
	typeof signalCount === 'number' && body.includes( '{N}' ) ? body.split( '{N}' ).join( formatNumber( signalCount ) ) : body;

const EmptyMetricSection = ( { title, caption, state, body, signalCount }: EmptyMetricSectionProps ) => {
	const id = headingId( title );
	return (
		<Section className="newspack-insights__section newspack-insights__empty-metric-section" aria-labelledby={ id } data-empty-state={ state }>
			<SectionHeading id={ id } title={ title } description={ caption } />
			<Notice status="info" isDismissible={ false } className="newspack-insights__empty-metric-section-callout">
				<p>{ interpolate( body, signalCount ) }</p>
			</Notice>
		</Section>
	);
};

export default EmptyMetricSection;
