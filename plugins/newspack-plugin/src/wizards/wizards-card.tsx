/**
 * Wizards Card component.
 */

/**
 * Internal dependencies.
 */
import { Card } from '../../packages/components/src';

/**
 * Wizards Card component.
 *
 * @param props           Component props.
 * @param props.children  Component children.
 * @param props.className Component classNames.
 *
 * @return Component.
 */
function WizardsCard( { children, ...props }: { children: React.ReactNode; className?: string } ) {
	return <Card { ...props }>{ children }</Card>;
}

export default WizardsCard;
