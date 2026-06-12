/**
 * SectionHeading.
 *
 * Thin Insights-flavored wrapper around the Newspack `SectionHeader` design-system
 * component. Insights tabs stack many sections per page and rely on
 * `.newspack-insights__section { gap: 16px }` to own vertical rhythm, so we
 * pass `noMargin` to trim SectionHeader's default 64px/32px margins. The `id`
 * prop is forwarded so the parent `<section aria-labelledby>` keeps pointing
 * to the correct element.
 */

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
	description?: React.ReactNode;
}

const SectionHeading = ( { id, title, description }: SectionHeadingProps ) => (
	<SectionHeader id={ id } heading={ 2 } title={ title } description={ description } noMargin className="newspack-insights__section-heading" />
);

export default SectionHeading;
