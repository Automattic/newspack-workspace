/**
 * StatusBadge
 */

/**
 * WordPress dependencies.
 */
import { Badge } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { statusTone } from '../status-indicator/statuses';
import type { StatusName, StatusTone } from '../status-indicator/statuses';
import type { BadgeIntent } from '../types';

const TONE_INTENTS: Record< StatusTone, BadgeIntent > = {
	success: 'stable',
	info: 'informational',
	warning: 'medium',
	error: 'high',
	neutral: 'draft',
};

/**
 * The badge color for a status, from the same tones `StatusIndicator` uses.
 *
 * @param status The status, from the shared vocabulary.
 * @param tone   Overrides the status's own tone.
 * @return The badge intent.
 */
export const statusBadgeIntent = ( status: StatusName, tone?: StatusTone ): BadgeIntent => {
	const resolved = tone ?? statusTone( status );
	return resolved ? TONE_INTENTS[ resolved ] : 'none';
};

type StatusBadgeProps = {
	status: StatusName;
	tone?: StatusTone;
	className?: string;
	children: string;
};

/**
 * A state (Active, Inactive, On hold, Failed) as a badge. Publishing statuses
 * (Published, Draft, Scheduled) use `StatusIndicator` instead. Takes the same
 * status names, so a field can move between the two without remapping.
 */
const StatusBadge = ( { status, tone, className, children }: StatusBadgeProps ) => (
	<Badge intent={ statusBadgeIntent( status, tone ) } className={ classnames( 'newspack-status-badge', className ) }>
		{ children }
	</Badge>
);

export default StatusBadge;
