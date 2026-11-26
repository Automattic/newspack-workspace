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
import type { StatCardNoteProps } from './types';

const Note = forwardRef< HTMLDivElement, StatCardNoteProps >( function Note( { className, children, ...props }, ref ) {
	useStatCardContext();

	return (
		<div ref={ ref } className={ classnames( 'newspack-stat-card__note', className ) } { ...props }>
			{ children }
		</div>
	);
} );

export default Note;
