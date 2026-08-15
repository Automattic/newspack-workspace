/**
 * WordPress dependencies.
 */
import { useContext, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Button from '../button';
import { DrawerContext } from './context';
import type { DrawerActionProps } from './types';

const Action = ( { ariaLabel, closes = false, onClick, href, children, ...buttonProps }: DrawerActionProps ) => {
	// Not useDrawerContext: only `closes` requires the context.
	const context = useContext( DrawerContext );

	// `href=""` is still an anchor; a null from a JS caller means no link at all.
	const hasHref = 'string' === typeof href;

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV || ! hasHref ) {
			return;
		}
		if ( onClick || closes ) {
			// eslint-disable-next-line no-console
			console.warn(
				'Drawer: an action with `href` cannot also take `onClick` or `closes`, and they are ignored. ' +
					'A link that ran the close funnel would navigate away while the unsaved-changes confirmation was still opening.'
			);
		}
	}, [ hasHref, onClick, closes ] );

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV ) {
			return;
		}
		if ( typeof children === 'string' && ariaLabel && ! ariaLabel.toLowerCase().includes( children.toLowerCase() ) ) {
			// eslint-disable-next-line no-console
			console.warn(
				`Drawer: action ariaLabel "${ ariaLabel }" does not contain its visible label "${ children }". ` +
					'Voice control matches spoken commands against the accessible name (WCAG 2.5.3, Label in Name), ' +
					'so ariaLabel must extend the visible text rather than replace it.'
			);
		}
	}, [ ariaLabel, children ] );

	const handleClick = ( event?: React.MouseEvent< HTMLElement > ) => {
		onClick?.( event );
		if ( closes ) {
			if ( ! context ) {
				throw new Error( 'Drawer subcomponents must be rendered inside Drawer.Root.' );
			}
			context.requestClose();
		}
	};

	// Button swallows `href` whenever an onClick is present, so a link supplies none.
	const handlesClick = ! hasHref && ( !! onClick || closes );

	return (
		<Button aria-label={ ariaLabel } href={ hasHref ? href : undefined } onClick={ handlesClick ? handleClick : undefined } { ...buttonProps }>
			{ children }
		</Button>
	);
};

export default Action;
