/**
 * WordPress dependencies.
 */
import { forwardRef } from '@wordpress/element';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useStatCardContext } from './context';
import type { StatCardSecondaryProps } from './types';

const Secondary = forwardRef< HTMLDivElement, StatCardSecondaryProps >( function Secondary( { className, children, ...props }, ref ) {
	useStatCardContext();

	return (
		<div ref={ ref } className={ classnames( 'newspack-stat-card__secondary', className ) } { ...props }>
			{ children }
		</div>
	);
} );

export default Secondary;
