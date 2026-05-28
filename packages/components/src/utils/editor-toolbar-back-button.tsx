/**
 * A utility to add a back button to the editor toolbar.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Tooltip } from '@wordpress/components';
import { subscribe } from '@wordpress/data';
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { arrowUpLeft } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './style.scss';

const WRAPPER_ID = 'newspack-editor-toolbar-wrapper';

const ToolbarButton = ( { href }: { href: string } ) => (
	<Tooltip text={ __( 'Go back', 'newspack-plugin' ) }>
		<Button icon={ arrowUpLeft } label={ __( 'Go back', 'newspack-plugin' ) } href={ href } />
	</Tooltip>
);

export const addToolbarBackButton = ( href: string = '' ) => {
	const unsubscribe = subscribe( () => {
		domReady( () => {
			if ( document.getElementById( WRAPPER_ID ) ) {
				return;
			}
			const toolbar = document.querySelector( '.editor-header__toolbar' );
			if ( ! toolbar ) {
				return;
			}

			const wrapper = document.createElement( 'div' );
			wrapper.id = WRAPPER_ID;
			toolbar.prepend( wrapper );

			const root = createRoot( wrapper );
			root.render( <ToolbarButton href={ href } /> );
			unsubscribe();
		} );
	} );
};
