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

/**
 * Internal dependencies
 */
import { formatCurrency, formatDecimal, formatDuration, formatNumber, formatPercent, formatDelta, deltaTone } from './format';
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
	/**
	 * Native tooltip for the value (e.g. the full amount behind an abbreviated
	 * "$1.2M"). Overrides the title the currency formatter derives on its own.
	 */
	valueTitle?: string;
}

// Currency is handled by the caller (it needs both display + title from one
// formatCurrency call); every other format maps to a plain string here.
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
		valueTitle,
	} = props;

	// Shared graceful-failure state (missing dimension / not configured / error).
	if ( overlay || error || notConfigured ) {
		return (
			<div className="newspack-insights__metric-card newspack-insights__metric-card--note">
				<div className="newspack-insights__metric-card-label">{ label }</div>
				<div className="newspack-insights__metric-card-body">
					<MetricNote overlay={ overlay } error={ !! error } notConfigured={ notConfigured } />
				</div>
				{ description && <div className="newspack-insights__metric-card-description">{ description }</div> }
			</div>
		);
	}

	const hasComparison = ! pending && typeof previousValue === 'number';
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

	// Currency formats once, yielding both the display string and (when
	// abbreviated, e.g. "$1.2M") the full-value title; other formats go through
	// formatValue.
	const currency = format === 'currency' ? formatCurrency( value ) : null;
	const valueText = currency ? currency.display : formatValue( value, format );
	// Explicit `valueTitle` wins; `||` (not `??`) so an empty string isn't treated
	// as an override and still falls back to the formatter-derived full value.
	const valueTooltip = valueTitle || currency?.title || undefined;

	return (
		<div className="newspack-insights__metric-card">
			<div className="newspack-insights__metric-card-label">{ label }</div>
			<div className="newspack-insights__metric-card-body">
				<div className="newspack-insights__metric-card-value">
					{ valueTooltip ? <span title={ valueTooltip }>{ valueText }</span> : valueText }
				</div>
				{ secondary && <div className="newspack-insights__metric-card-secondary">{ secondary }</div> }
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
			{ description && <div className="newspack-insights__metric-card-description">{ description }</div> }
		</div>
	);
};

export default MetricCard;
