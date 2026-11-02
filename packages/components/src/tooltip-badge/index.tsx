/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { Badge, Tooltip } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import type { BadgeIntent } from '../types';

type TooltipBadgeProps = {
	label: string;
	tooltip: string;
	intent?: BadgeIntent;
};

/**
 * A badge whose tooltip explains it. The tooltip serves mouse and keyboard users;
 * screen readers get the same text as the trigger's description, which keeps it
 * out of the accessible name of a heading the badge sits in.
 */
const TooltipBadge = ( { label, tooltip, intent = 'none' }: TooltipBadgeProps ) => {
	const descriptionId = useInstanceId( TooltipBadge, 'newspack-tooltip-badge' );
	return (
		<>
			<Tooltip.Root>
				<Tooltip.Trigger render={ <span tabIndex={ 0 } aria-describedby={ descriptionId } /> }>
					<Badge intent={ intent }>{ label }</Badge>
				</Tooltip.Trigger>
				<Tooltip.Popup>{ tooltip }</Tooltip.Popup>
			</Tooltip.Root>
			<span id={ descriptionId } hidden>
				{ tooltip }
			</span>
		</>
	);
};

export default TooltipBadge;
