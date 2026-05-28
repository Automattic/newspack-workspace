/**
 * Settings Wizard: Action Card component.
 */

/**
 * Internal dependencies
 */
import { ActionCard } from '../../packages/components/src';

const WizardsActionCard = ( { description, error, isChecked, notificationLevel = 'error', children, ...props }: WizardActionCardProps ) => {
	let checkbox: 'checked' | 'unchecked' | undefined;
	if ( typeof isChecked !== 'undefined' ) {
		checkbox = isChecked ? 'checked' : 'unchecked';
	}
	return (
		<ActionCard
			{ ...{
				description,
				checkbox,
				notification: error,
				notificationLevel,
				...props,
			} }
		>
			{ children }
		</ActionCard>
	);
};

export default WizardsActionCard;
