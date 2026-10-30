/**
 * WordPress dependencies.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import type { DrawerBodyProps } from './types';

// Core tab-stops its own container once it overflows; the drawer scrolls here.
const Body = ( { children }: DrawerBodyProps ) => {
	const [ node, setNode ] = useState< HTMLElement | null >( null );
	const [ isScrollable, setIsScrollable ] = useState( false );

	useEffect( () => {
		if ( ! node ) {
			return;
		}
		const measure = () => setIsScrollable( node.scrollHeight > node.clientHeight );
		measure();
		if ( ! window.ResizeObserver ) {
			return;
		}
		const resize = new ResizeObserver( measure );
		const observeChildren = () => {
			resize.disconnect();
			resize.observe( node );
			// Border box: a section's padding rides on an inline custom property.
			Array.from( node.children ).forEach( child => resize.observe( child, { box: 'border-box' } ) );
			measure();
		};
		observeChildren();
		const mutation = new MutationObserver( observeChildren );
		mutation.observe( node, { childList: true } );
		return () => {
			resize.disconnect();
			mutation.disconnect();
		};
	}, [ node ] );

	return (
		<Stack
			ref={ setNode }
			direction="column"
			className="newspack-drawer__body"
			// A bare div is role=generic, where ARIA prohibits an author name.
			role={ isScrollable ? 'group' : undefined }
			tabIndex={ isScrollable ? 0 : undefined }
			aria-label={ isScrollable ? __( 'Scrollable section', 'newspack-plugin' ) : undefined }
		>
			{ children }
		</Stack>
	);
};

export default Body;
