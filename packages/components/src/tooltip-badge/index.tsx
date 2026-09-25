/**
 * WordPress dependencies
 */
import { Badge, Tooltip, VisuallyHidden } from '@wordpress/ui';

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
 * its text is also inside the badge, visually hidden, so screen readers get it
 * without opening the tooltip.
 */
const TooltipBadge = ( { label, tooltip, intent = 'none' }: TooltipBadgeProps ) => (
	<Tooltip.Root>
		<Tooltip.Trigger render={ <Badge intent={ intent } tabIndex={ 0 } /> }>
			{ label }
			<VisuallyHidden>{ `: ${ tooltip }` }</VisuallyHidden>
		</Tooltip.Trigger>
		<Tooltip.Popup>{ tooltip }</Tooltip.Popup>
	</Tooltip.Root>
);

export default TooltipBadge;
