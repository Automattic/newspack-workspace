/**
 * SectionHeading.
 *
 * Thin Insights-flavored wrapper around the Newspack `SectionHeader` design-system
 * component. Insights tabs stack many sections per page and rely on
 * `.newspack-insights__section { gap: 16px }` to own vertical rhythm, so we
 * pass `noMargin` to trim SectionHeader's default 64px/32px margins. The `id`
 * prop is forwarded so the parent `<section aria-labelledby>` keeps pointing
 * to the correct element.
 *
 * When an `actions` node is passed (typically the section's LastUpdated
 * indicator) the heading is laid out in a flex container so the slot sits
 * flush-right next to the heading text. When omitted, the heading renders
 * standalone — no wrapper, no layout cost.
 */

/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { SectionHeader } from '../../../../../packages/components/src';

export interface SectionHeadingProps {
	/** HTML id wired to the parent `<section aria-labelledby>`. */
	id: string;
	/** Section title (h2). */
	title: string;
	/** Optional description / caption line. Accepts ReactNode for markup. */
	description?: ReactNode;
	/**
	 * Optional flex-end slot rendered alongside the heading text. Typically a
	 * `LastUpdated` indicator on the first section of each tab.
	 */
	actions?: ReactNode;
}

const SectionHeading = ( { id, title, description, actions }: SectionHeadingProps ) => {
	const header = (
		<SectionHeader id={ id } heading={ 2 } title={ title } description={ description } noMargin className="newspack-insights__section-heading" />
	);

	if ( ! actions ) {
		return header;
	}

	return (
		<div className="newspack-insights__section-header-container">
			<div className="newspack-insights__section-header-text">{ header }</div>
			{ actions }
		</div>
	);
};

export default SectionHeading;
