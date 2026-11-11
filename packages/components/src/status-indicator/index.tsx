/**
 * StatusIndicator
 */

/**
 * WordPress dependencies.
 */
import { Icon } from '@wordpress/components';
import { forwardRef } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import type { StatusIndicatorProps } from './types';
import { statusGlyph, statusTone } from './statuses';
import './style.scss';

const StatusIndicator = forwardRef< HTMLDivElement, StatusIndicatorProps >( function StatusIndicator(
	{ status, tone: toneOverride, icon, className, children, ...props },
	ref
) {
	const tone = status ? toneOverride ?? statusTone( status ) : undefined;
	return (
		<Stack
			ref={ ref }
			direction="row"
			align="center"
			gap="sm"
			className={ classnames( 'newspack-status-indicator', tone && `newspack-status-indicator--${ tone }`, className ) }
			{ ...props }
		>
			<Icon className="newspack-status-indicator__icon" icon={ status ? statusGlyph( status ) : icon } size={ 24 } />
			<span>{ children }</span>
		</Stack>
	);
} );

export { statusGlyph, statusTone, STATUS_NAMES } from './statuses';
export type { StatusName, StatusTone } from './statuses';

export default StatusIndicator;
