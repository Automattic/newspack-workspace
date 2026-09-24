/**
 * Chassis page header.
 *
 * Renders actions registered via `useHeaderActions`. Portals into the
 * newspack-plugin admin-header strip when present, falls back to an
 * inline row in standalone mode.
 */

import { Button } from '@wordpress/components';
import { createPortal, useEffect, useState } from '@wordpress/element';

import { useHeaderActionsValue } from './header-actions-context';

// Portal target: the stable actions container the newspack-plugin admin-header
// `<Page>` renders inside its header. Falls back to an inline row in standalone mode.
const NEWSPACK_HEADER_SELECTOR = '#newspack-wizards-admin-header-actions';

const variantFor = type => ( 'primary' === type ? 'primary' : 'secondary' );

function useNewspackHeader() {
	const [ target, setTarget ] = useState( () => document.querySelector( NEWSPACK_HEADER_SELECTOR ) );

	useEffect( () => {
		if ( target ) {
			return undefined;
		}

		const wrapper = document.getElementById( 'newspack-wizards-admin-header' );
		if ( ! wrapper ) {
			return undefined;
		}

		// Re-query synchronously — MutationObserver only fires on future mutations.
		const synchronous = wrapper.querySelector( '#newspack-wizards-admin-header-actions' );
		if ( synchronous ) {
			setTarget( synchronous );
			return undefined;
		}

		// Newspack admin-header's React app rewrites this subtree on mount.
		const observer = new MutationObserver( () => {
			const found = wrapper.querySelector( '#newspack-wizards-admin-header-actions' );
			if ( found ) {
				setTarget( found );
				observer.disconnect();
			}
		} );
		observer.observe( wrapper, { childList: true, subtree: true } );

		return () => observer.disconnect();
	}, [ target ] );

	return target;
}

function ActionButtons( { actions } ) {
	return (
		<>
			{ actions.map( ( action, index ) => (
				<Button
					key={ action.id || `${ action.label }-${ index }` }
					variant={ variantFor( action.type ) }
					icon={ action.icon }
					href={ action.href }
					onClick={ action.onClick }
				>
					{ action.label }
				</Button>
			) ) }
		</>
	);
}

export default function PageHeader() {
	const actions = useHeaderActionsValue();
	const newspackHeader = useNewspackHeader();

	if ( actions.length === 0 ) {
		return null;
	}

	if ( newspackHeader ) {
		return createPortal(
			<div className="newspack-newsletters-admin__header-actions--in-newspack-header">
				<ActionButtons actions={ actions } />
			</div>,
			newspackHeader
		);
	}

	// Standalone mode: render inline above the screen content.
	return (
		<div className="newspack-newsletters-admin__header-actions">
			<ActionButtons actions={ actions } />
		</div>
	);
}
