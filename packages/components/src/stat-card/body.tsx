/**
 * WordPress dependencies.
 */
import { forwardRef } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useStatCardContext } from './context';
import type { StatCardBodyProps } from './types';

const Body = forwardRef< HTMLDivElement, StatCardBodyProps >( function Body( { className, children, ...props }, ref ) {
	useStatCardContext();

	return (
		<Stack ref={ ref } direction="column" gap="xs" className={ classnames( 'newspack-stat-card__body', className ) } { ...props }>
			{ children }
		</Stack>
	);
} );

export default Body;
