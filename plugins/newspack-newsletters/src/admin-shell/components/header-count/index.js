/**
 * Live item count rendered next to the page title in the
 * newspack-plugin admin-header breadcrumbs. The heading belongs to the
 * admin-header's own React app, so the count portals into a container
 * appended to it, re-attached if that app re-renders. Renders nothing
 * in standalone mode (no breadcrumbs heading to attach to).
 */

import { createPortal, useEffect, useState } from '@wordpress/element';

const CONTAINER_CLASS = 'newspack-newsletters-header-count';

/**
 * @param {Object} props
 * @param {number} props.count Total items matching the current filters.
 */
export default function HeaderCount( { count } ) {
	const [ target, setTarget ] = useState( null );

	useEffect( () => {
		const wrapper = document.getElementById( 'newspack-wizards-admin-header' );
		if ( ! wrapper ) {
			return undefined;
		}
		const ensureTarget = () => {
			const heading = wrapper.querySelector( 'h1.newspack-breadcrumbs__current' );
			if ( ! heading ) {
				setTarget( null );
				return;
			}
			if ( heading.querySelector( `.${ CONTAINER_CLASS }` ) ) {
				return;
			}
			const container = document.createElement( 'span' );
			container.className = CONTAINER_CLASS;
			heading.appendChild( container );
			setTarget( container );
		};
		ensureTarget();
		const observer = new MutationObserver( ensureTarget );
		observer.observe( wrapper, { childList: true, subtree: true } );
		return () => {
			observer.disconnect();
			wrapper.querySelector( `.${ CONTAINER_CLASS }` )?.remove();
		};
	}, [] );

	// Hidden at zero: an empty list says so itself, and the pre-load count is 0.
	if ( ! target || typeof count !== 'number' || count === 0 ) {
		return null;
	}

	return createPortal( ` (${ count.toLocaleString() })`, target );
}
