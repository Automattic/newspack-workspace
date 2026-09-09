/**
 * WordPress dependencies.
 */
import { useInstanceId } from '@wordpress/compose';
import { useLayoutEffect } from '@wordpress/element';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useDrawerContext } from './context';
import type { DrawerTitleProps } from './types';

const Title = ( { className, children }: DrawerTitleProps ) => {
	const { setTitle } = useDrawerContext();
	const id = useInstanceId( Title, 'newspack-drawer__title' ) as string;

	// Derived, not keyed on `children`: a JSX title would re-register every render.
	const text = typeof children === 'string' ? children : null;

	useLayoutEffect( () => {
		setTitle( { id, text } );
	}, [ id, text, setTitle ] );

	// Unmount only, scoped to this id: a text change must not re-order the stack,
	// nor a second title unname the one still rendered.
	useLayoutEffect( () => () => setTitle( null, id ), [ id, setTitle ] );

	return (
		<h2 className={ classnames( 'newspack-drawer__title', className ) } id={ id }>
			{ children }
		</h2>
	);
};

export default Title;
