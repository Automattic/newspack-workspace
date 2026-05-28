/**
 * Wizards Section component.
 */

/**
 * Internal dependencies.
 */
import { SectionHeader } from '../../packages/components/src';

/**
 * Section component.
 *
 * @param props                Component props.
 * @param props.title          Section title.
 * @param props.description    Section description.
 * @param props.children       Section children.
 * @param props.scrollToAnchor Scroll to anchor.
 * @param props.className      Optional class name.
 *
 * @return Component.
 */
export default function WizardSection( {
	title,
	description,
	children = null,
	scrollToAnchor = null,
	className,
}: {
	title?: string;
	description?: string | React.ReactNode;
	children: React.ReactNode;
	scrollToAnchor?: string | null;
	className?: string;
} ) {
	const classNames = `newspack-wizard__section${ className ? ` ${ className }` : '' }`;
	return (
		<div className={ classNames }>
			{ title && <SectionHeader id={ scrollToAnchor } heading={ 3 } title={ title } description={ description } /> }
			{ children }
		</div>
	);
}
