/**
 * SectionHeading.
 *
 * Thin Insights-flavored wrapper around the Newspack `SectionHeader` design-system
 * component. Insights tabs stack many sections per page and rely on
 * `.newspack-insights__section { gap: 16px }` to own vertical rhythm, so we
 * use `noMargin` and a per-section SCSS override (see sections.scss) to trim
 * SectionHeader's default 64px/32px margins. The `id` prop is forwarded so the
 * parent `<section aria-labelledby>` keeps pointing to the correct element.
 */

/**
 * WordPress dependencies
 */
// (none)

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
	/** Additional class appended to the SectionHeader root. */
	className?: string;
}

const SectionHeading = ( { id, title, description, className }: SectionHeadingProps ) => (
	<SectionHeader
		id={ id }
		heading={ 2 }
		title={ title }
		description={ description }
		noMargin
		className={ className ? `newspack-insights__section-heading ${ className }` : 'newspack-insights__section-heading' }
	/>
);

export default SectionHeading;
