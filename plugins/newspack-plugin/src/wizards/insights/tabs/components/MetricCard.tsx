/**
 * MetricCard (NPPD-1616, extended for NPPD-1604 and NPPD-1649).
 *
 * Scorecard atom: label (top) → value + optional delta (vertically
 * centered hero region) → description (pinned to the bottom).
 *
 * `lowerIsBetter` flips the green/red delta tone for metrics where a
 * decrease is desirable (refund rate, churned subscriber count).
 *
 * `pending` (NPPD-1604) renders the value normally but suppresses the
 * comparison delta even when `previousValue` is supplied.
 *
 * `overlay` / `error` / `notConfigured` (NPPD-1649) render a single shared
 * graceful-failure treatment (see MetricNote) in place of the value. All are
 * additive — every existing call site leaves them undefined, unchanged.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, Tooltip, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { info } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Card } from '../../../../../packages/components/src';
import {
	formatCurrency,
	formatCount,
	formatDecimal,
	formatDuration,
	formatNumber,
	formatPercent,
	formatDelta,
	deltaTone,
	type FormattedCurrency,
} from './format';
import MetricNote from './MetricNote';

export type MetricFormat = 'number' | 'currency' | 'percent' | 'decimal' | 'duration';

export interface MetricCardOverlay {
	type: string;
	/**
	 * Custom-dimension parameter names (GA4 `custom_dimension_missing` overlays).
	 * Absent for dimension-less overlays such as GAM's `data_unavailable`.
	 */
	dimensions?: string[];
}

/**
 * Count-fallback inputs for zero scorecards (NPPD-1694). When a rate or
 * currency card would otherwise render a bare `0%` / `$0.00`, these drive a
 * count-based hero that conveys the real signal instead (`0 of 17`,
 * `0 conversions`, or the `—` null glyph with an explanatory secondary line).
 *
 * Keyed on two counts, regardless of card `format`:
 *   - `denominator` — the "opportunity" count (paywall / regwall impressions)
 *   - `numerator`   — the conversions count (for currency cards, the conversions companion)
 *
 * The decision is driven by these counts, NOT by `value`: a real $0 alongside N
 * conversions still reads as data, while 0 conversions out of N impressions reads
 * as an honest zero. `currencyRole` distinguishes the two currency behaviors
 * (ticket: a total card shows `0 conversions`; an average card shows `—` with a
 * "No conversions…" secondary). It is ignored for `format='percent'`.
 */
export interface MetricCardZeroFallback {
	numerator?: number;
	denominator?: number;
	currencyRole?: 'total' | 'average';
	/** Plural noun for the "No … in this timeframe" line, e.g. "paywall impressions". */
	attemptsLabel: string;
	/** Plural noun for the conversions line, e.g. "conversions". */
	conversionsLabel?: string;
}

export interface MetricCardProps {
	label: string;
	value?: number;
	format?: MetricFormat;
	/** Null is treated the same as undefined — no comparison delta is rendered. */
	previousValue?: number | null;
	description?: string;
	lowerIsBetter?: boolean;
	secondary?: string;
	pending?: boolean;
	/** Missing-custom-dimension state. */
	overlay?: MetricCardOverlay;
	/**
	 * Failure flag. Any truthy value (the orchestrator passes the error string)
	 * triggers the shared generic note ("Data temporarily unavailable."); the raw
	 * message is intentionally not surfaced to readers — it stays server-side.
	 */
	error?: string;
	/** Metric needs configuration (e.g. coverage area not set). */
	notConfigured?: boolean;
	/** A present hub row was missing required column(s) — renders the data-missing note. */
	dataMissing?: boolean;
	/**
	 * Hub snapshot cache miss being backfilled (NEWS-2603). Renders the shared
	 * "Still calculating — check back shortly." note in place of the value.
	 * Additive — every existing call site leaves it undefined, unchanged. Takes
	 * precedence over the generic non-computable message but yields to `error`.
	 */
	warming?: boolean;
	/**
	 * Native tooltip for the value (e.g. the full amount behind an abbreviated
	 * "$1.2M"). Overrides the title the currency formatter derives on its own.
	 */
	valueTitle?: string;
	/** Count-fallback for zero rate/currency scorecards (NPPD-1694). */
	zeroFallback?: MetricCardZeroFallback;
	/**
	 * Structural "not capable" copy (NPPD-1720). When set, the metric can't be
	 * measured at all because no active prompt contains the block it tracks; the
	 * card renders the em-dash hero with this string as the secondary line and a
	 * block-scoped nudge. Takes precedence over `zeroFallback` (structural beats
	 * window-bound). Absent for capable metrics and every non-Prompts caller.
	 */
	notCapableMessage?: string;
	/**
	 * "Not computable this window" copy (NPPD-1704). When set, the metric IS
	 * capable (the block exists) but the math couldn't complete for this window —
	 * typically a zero denominator (SAFE_DIVIDE NULL) because no in-intent prompts
	 * were viewed. Renders the same em-dash hero + secondary line as
	 * `notCapableMessage`, but sits one slot lower in precedence: `notCapable`
	 * wins (its "add the block" nudge is more actionable than "wait for data"),
	 * and this in turn beats `zeroFallback`. Originally a Prompts concept; also
	 * used by Subscribers (NPPD-1698) to render its rate-card good-zeros (no orders
	 * / no refunds / no failed payments) with the same em-dash + line treatment.
	 */
	notComputableMessage?: string;
}

// Currency and large counts are handled by the caller (they need both a display
// string and a full-value title from one call); every other format maps to a
// plain string here.
const formatValue = ( v: number, fmt: MetricFormat ): string => {
	switch ( fmt ) {
		case 'percent':
			return formatPercent( v );
		case 'decimal':
			return formatDecimal( v );
		case 'duration':
			return formatDuration( v );
		default:
			return formatNumber( v );
	}
};

// The two tiered formats: currency and count both yield a { display, title }
// pair (title = the full value when the display is abbreviated to "$1.2M" /
// "2.4M"). Other formats have no tier and return null so the caller falls back
// to formatValue.
const tieredFormat = ( v: number, fmt: MetricFormat ): FormattedCurrency | null => {
	if ( fmt === 'currency' ) {
		return formatCurrency( v );
	}
	if ( fmt === 'number' ) {
		return formatCount( v );
	}
	return null;
};

// Em-dash null glyph (U+2014). Matches the "Not applicable" treatment in the
// Performance by gate table (PerformanceByGateSection's `NotApplicable`): same
// glyph + same `aria-label`, so a null scorecard and a null table cell read as
// one design system. The table's span is unstyled (inherits cell context); the
// card renders it in the value region's own type scale.
const EM_DASH = '—';

const MetricCard = ( props: MetricCardProps ) => {
	const {
		label,
		value = 0,
		format = 'number',
		previousValue,
		description,
		lowerIsBetter = false,
		secondary,
		pending = false,
		overlay,
		error,
		notConfigured,
		dataMissing,
		warming,
		valueTitle,
		zeroFallback,
		notCapableMessage,
		notComputableMessage,
	} = props;

	// --- Zero-state count fallback (NPPD-1694) -----------------------------
	// Resolve a count-based hero/secondary before the normal value+delta path.
	// Driven by the conversions (numerator) and opportunity (denominator) counts
	// on `zeroFallback`, never by `value`. `undefined === 0` is false, so a card
	// passing partial counts simply falls through to the normal render.
	let fallbackHero: string | null = null;
	let fallbackSecondary: string | null = null;
	if ( notCapableMessage ) {
		// Structural "not capable" (NPPD-1720): no active prompt carries the block
		// this metric measures. Em-dash hero + the block-scoped nudge as the
		// secondary line. Checked before `zeroFallback` so a structural gap (which
		// no date range can fill) wins over a window-bound zero count.
		fallbackHero = EM_DASH;
		fallbackSecondary = notCapableMessage;
	} else if ( notComputableMessage ) {
		// "Not computable this window" (NPPD-1704): the metric is capable (its block
		// exists) but the math couldn't complete — typically a zero denominator, so
		// there were no in-intent inputs in this timeframe. Same em-dash + secondary
		// treatment as not-capable, one slot lower: not-capable already short-
		// circuited above (its nudge is the more actionable of the two), and this
		// beats `zeroFallback`. A longer window or more traffic can fill this gap.
		fallbackHero = EM_DASH;
		fallbackSecondary = notComputableMessage;
	} else if ( zeroFallback && ( format === 'percent' || format === 'currency' ) ) {
		const { numerator, denominator, currencyRole, attemptsLabel, conversionsLabel } = zeroFallback;
		const conversionsNoun = conversionsLabel ?? __( 'conversions', 'newspack-plugin' );
		const noneInWindow = ( pluralNoun: string ) =>
			sprintf(
				/* translators: %s is a plural noun, e.g. "paywall impressions". */
				__( 'No %s in this timeframe', 'newspack-plugin' ),
				pluralNoun
			);
		if ( denominator === 0 ) {
			// Nothing happened at all → em-dash + "No <impressions> in this timeframe".
			fallbackHero = EM_DASH;
			fallbackSecondary = noneInWindow( attemptsLabel );
		} else if ( numerator === 0 && typeof denominator === 'number' && denominator > 0 ) {
			if ( format === 'percent' ) {
				// "0 of 17" — word "of", no "%" suffix and no "(0%)" parenthetical.
				fallbackHero = sprintf(
					/* translators: 1: numerator (always 0 here), 2: denominator. */
					__( '%1$s of %2$s', 'newspack-plugin' ),
					formatNumber( numerator ),
					formatNumber( denominator )
				);
			} else if ( currencyRole === 'total' ) {
				// Currency total → "0 conversions", symmetric with the rate card.
				fallbackHero = sprintf(
					/* translators: 1: conversions count (0 here), 2: plural "conversions". */
					__( '%1$s %2$s', 'newspack-plugin' ),
					formatNumber( numerator ),
					conversionsNoun
				);
			} else {
				// Currency average → em-dash + "No conversions in this timeframe".
				fallbackHero = EM_DASH;
				fallbackSecondary = noneInWindow( conversionsNoun );
			}
		}
	}

	// Shared graceful-failure state (missing dimension / not configured / error / data missing / warming).
	if ( overlay || error || notConfigured || dataMissing || warming ) {
		return (
			<Card __experimentalCoreCard className="newspack-insights__metric-card newspack-insights__metric-card--note">
				<HStack className="newspack-insights__metric-card-label" alignment="top" justify="space-between" spacing={ 2 }>
					<span>{ label }</span>
					{ ( fallbackSecondary ?? secondary ) && (
						<Tooltip delay={ 250 } hideOnClick={ false } placement="bottom-start" text={ fallbackSecondary ?? secondary }>
							<Button icon={ info } className="newspack-insights__metric-card-info-icon" variant="tertiary" />
						</Tooltip>
					) }
				</HStack>
				<div className="newspack-insights__metric-card-body">
					<MetricNote
						overlay={ overlay }
						error={ !! error }
						notConfigured={ notConfigured }
						dataMissing={ dataMissing }
						warming={ warming }
					/>
				</div>
				{ description && (
					<div className="newspack-insights__metric-card-description">
						<p>{ description }</p>
					</div>
				) }
			</Card>
		);
	}

	// Suppress the period-over-period delta whenever a fallback hero is shown — a
	// "↓ 100%" against a real prior value would misread an honest zero.
	const hasComparison = ! pending && ! fallbackHero && typeof previousValue === 'number';
	// `delta` is null only when there's no comparison or previous is 0 (no ratio).
	const delta = hasComparison ? formatDelta( value, previousValue as number ) : null;
	const tone = hasComparison ? deltaTone( value, previousValue as number, lowerIsBetter ) : 'neutral';
	// The glyph reflects the factual direction of change (↑ rose / ↓ fell), while
	// the tone color says whether that's good or bad (lowerIsBetter-aware). No
	// glyph for a zero delta — just "0%". Magnitude is shown unsigned since the
	// arrow carries the direction.
	const ratio = delta !== null ? ( value - ( previousValue as number ) ) / ( previousValue as number ) : 0;
	let arrow = '';
	let directionWord = '';
	if ( ratio > 0 ) {
		arrow = '↑';
		directionWord = __( 'up', 'newspack-plugin' );
	} else if ( ratio < 0 ) {
		arrow = '↓';
		directionWord = __( 'down', 'newspack-plugin' );
	}
	const magnitude = delta !== null ? formatPercent( Math.abs( ratio ) ) : null;
	const deltaA11y =
		magnitude !== null
			? sprintf(
					/* translators: 1: "up" or "down" (empty when unchanged), 2: percent change from previous timeframe. */
					__( '%1$s %2$s vs previous timeframe', 'newspack-plugin' ),
					directionWord,
					magnitude
			  ).trim()
			: null;

	// Currency and large counts format once, yielding both the display string and
	// (when abbreviated, e.g. "$1.2M" / "2.4M") the full-value title; other formats
	// go through formatValue. Counts share the currency millions tier so a 7+ digit
	// impressions total abbreviates instead of overflowing the card (NPPD-1684).
	const tiered = tieredFormat( value, format );
	const valueText = tiered ? tiered.display : formatValue( value, format );
	// Explicit `valueTitle` wins; `||` (not `??`) so an empty string isn't treated
	// as an override and still falls back to the formatter-derived full value.
	const valueTooltip = valueTitle || tiered?.title || undefined;

	// Hero content: a count-fallback string, the em-dash null glyph (matched to
	// the Performance by gate table's null cell — same glyph + aria-label), or
	// the normal formatted value (optionally with a full-value tooltip).
	let heroContent: React.ReactNode;
	if ( fallbackHero === EM_DASH ) {
		heroContent = (
			<span className="newspack-insights__metric-card-na" aria-label={ __( 'Not applicable', 'newspack-plugin' ) }>
				{ EM_DASH }
			</span>
		);
	} else if ( fallbackHero !== null ) {
		// A count-fallback phrase ("0 of 17", "0 conversions") — flagged so the
		// value region can render it smaller than the 44px number scale.
		heroContent = fallbackHero;
	} else if ( valueTooltip ) {
		heroContent = <span title={ valueTooltip }>{ valueText }</span>;
	} else {
		heroContent = valueText;
	}

	return (
		<Card __experimentalCoreCard className="newspack-insights__metric-card">
			<HStack className="newspack-insights__metric-card-label" alignment="top" justify="space-between" spacing={ 2 }>
				<span>{ label }</span>
				{ ( fallbackSecondary ?? secondary ) && (
					<Tooltip delay={ 250 } hideOnClick={ false } placement="bottom-start" text={ fallbackSecondary ?? secondary }>
						<Button icon={ info } className="newspack-insights__metric-card-info-icon" variant="tertiary" />
					</Tooltip>
				) }
			</HStack>
			<div className="newspack-insights__metric-card-body">
				<div
					className={
						fallbackHero !== null && fallbackHero !== EM_DASH
							? 'newspack-insights__metric-card-value newspack-insights__metric-card-value--count'
							: 'newspack-insights__metric-card-value'
					}
				>
					{ heroContent }
				</div>
				{ ( fallbackSecondary ?? secondary ) && (
					<div className="newspack-insights__metric-card-secondary">{ fallbackSecondary ?? secondary }</div>
				) }
				{ magnitude !== null && (
					<div
						className={ `newspack-insights__metric-card-delta newspack-insights__metric-card-delta--${ tone }` }
						aria-label={ deltaA11y ?? undefined }
					>
						{ arrow && (
							<span className="newspack-insights__metric-card-delta-arrow" aria-hidden="true">
								{ arrow }
							</span>
						) }
						{ magnitude }
					</div>
				) }
			</div>
			{ /* The not-capable / not-computable secondary line replaces the formula
			     description (NPPD-1720, NPPD-1704): the description explains how the
			     metric is computed, which is moot when there's nothing to compute, and
			     would just double the text block. */ }
			{ description && ! notCapableMessage && ! notComputableMessage && (
				<div className="newspack-insights__metric-card-description">
					<p>{ description }</p>
				</div>
			) }
		</Card>
	);
};

export default MetricCard;
