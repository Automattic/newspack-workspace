/**
 * SectionEmpty.
 *
 * Empty-state paragraph rendered when a section has no data to show. Shared by
 * SectionState (gates/prompts), MetricTable, and a few bespoke sections that
 * compute their own emptiness. Centralizes the `.newspack-insights__section-empty`
 * class so callers don't repeat the markup.
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

export interface SectionEmptyProps {
	/** Message rendered inside the empty-state paragraph. */
	children: ReactNode;
}

const SectionEmpty = ( { children }: SectionEmptyProps ) => <p className="newspack-insights__section-empty">{ children }</p>;

export default SectionEmpty;
