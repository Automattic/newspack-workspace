/**
 * Small helper that maps a `PromptsScalarMetric` payload + section
 * copy into the MetricCard props used by every Tab 5 scorecard.
 *
 * Centralises the `placeholder_type` → `MetricFormat` mapping so the
 * section components stay declarative. Mirrors the Gates tab-local
 * `scalarToCard.ts`; Tab 5 is the first tab to use the `decimal`
 * placeholder type (Card 1.3 — Avg Prompts per Reader).
 */

import { __ } from '@wordpress/i18n';

import type { PromptsScalarMetric } from '../../api/prompts';
import type { MetricFormat } from '../components/MetricCard';

const formatFor = ( m: PromptsScalarMetric ): MetricFormat => {
	switch ( m.placeholder_type ) {
		case 'rate':
			return 'percent';
		case 'currency':
			return 'currency';
		case 'decimal':
			return 'decimal';
		case 'count':
		default:
			return 'number';
	}
};

export interface ScalarCardProps {
	label: string;
	description: string;
	current: PromptsScalarMetric;
	previous?: PromptsScalarMetric | null;
	/**
	 * Block-scoped nudge shown when this metric isn't capable (NPPD-1720). Routed
	 * to MetricCard's em-dash treatment only when the envelope reports
	 * `has_capability === false`; ignored otherwise.
	 */
	notCapableMessage?: string;
	/**
	 * Intent-grouped "no inputs this window" copy (NPPD-1704). Routed to
	 * MetricCard's em-dash treatment when the envelope reports a capable metric
	 * (`has_capability === true`) whose math couldn't complete this window
	 * (`state === 'populated' && computable === false`, e.g. a zero denominator).
	 * Ignored otherwise. Sits one slot below `notCapableMessage` in precedence.
	 */
	notComputableMessage?: string;
}

export const scalarToMetricCardProps = ( props: ScalarCardProps ) => {
	const { label, description, current, previous, notCapableMessage, notComputableMessage } = props;
	// A failed query renders MetricCard's shared error treatment rather than a
	// misleading zero. The raw message stays server-side; the card shows generic
	// copy keyed off the `error` prop.
	if ( current.state === 'error' ) {
		return { label, description, error: current.error_message ?? __( 'Data temporarily unavailable.', 'newspack-plugin' ) };
	}
	// Schema drift (a hub row present but missing required column(s)) surfaces the
	// shared "some data could not be loaded" note instead of a misleading zero,
	// mirroring the Conversion and Gates tabs. Beats all non-error routing.
	if ( current.state === 'populated' && current.data_missing ) {
		return { label, description, dataMissing: true };
	}
	// Structural "not capable" (NPPD-1720): no active prompt carries the block
	// this metric measures, so there's nothing to compute regardless of window.
	// Beats the normal value path; a generic fallback guarantees the em-dash
	// treatment shows even if a call site forgot its per-metric copy.
	if ( current.has_capability === false ) {
		return {
			label,
			description,
			notCapableMessage: notCapableMessage ?? __( 'Not measurable for your active prompts.', 'newspack-plugin' ),
		};
	}
	// "Not computable this window" (NPPD-1704): the metric is capable (its block
	// exists) but the math couldn't complete — typically SAFE_DIVIDE NULL from a
	// zero denominator, i.e. no in-intent prompts viewed this window. Gated on
	// `has_capability === true` so only the 13 conversion-tied scalars participate;
	// exposure/engagement scalars carry no capability flag and a non-computable
	// zero there falls through to the normal/zeroFallback path untouched. Sits
	// below not-capable (which already returned above): "add the block" beats
	// "wait for data". A generic fallback guarantees the em-dash even if a call
	// site forgot its per-metric copy.
	if ( current.has_capability === true && current.state === 'populated' && current.computable === false ) {
		return {
			label,
			description,
			notComputableMessage: notComputableMessage ?? __( 'Not enough data to calculate.', 'newspack-plugin' ),
		};
	}
	return {
		label,
		description,
		value: current.value,
		format: formatFor( current ),
		// Suppress the period-over-period delta unless BOTH windows are real
		// computed values. A non-computable current (e.g. an empty window's zero)
		// must not show a delta against a real prior value (that would read as a
		// misleading "↓ 100%").
		previousValue: current.computable && previous && previous.state !== 'error' && previous.computable ? previous.value : null,
	};
};
