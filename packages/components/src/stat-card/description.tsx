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
import type { StatCardDescriptionProps } from './types';

const Description = forwardRef< HTMLParagraphElement, StatCardDescriptionProps >( function Description( { className, children, ...props }, ref ) {
	useStatCardContext();

	return (
		<p ref={ ref } className={ classnames( 'newspack-stat-card__description', className ) } { ...props }>
			{ children }
		</p>
	);
} );

export default Description;
