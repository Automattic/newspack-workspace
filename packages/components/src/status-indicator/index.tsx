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
import './style.scss';

const StatusIndicator = forwardRef< HTMLDivElement, StatusIndicatorProps >( function StatusIndicator( { icon, className, children, ...props }, ref ) {
	return (
		<Stack ref={ ref } direction="row" align="center" gap="sm" className={ classnames( 'newspack-status-indicator', className ) } { ...props }>
			<Icon className="newspack-status-indicator__icon" icon={ icon } size={ 24 } />
			<span>{ children }</span>
		</Stack>
	);
} );

export default StatusIndicator;
