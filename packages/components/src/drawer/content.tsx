/**
 * WordPress dependencies.
 */
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { Children, isValidElement } from '@wordpress/element';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import type { DrawerContentProps } from './types';

// VStack drops non-elements, a lone string aside. A run shares one wrapper so
// `Edited by { name }` is one row, not three.
const asRows = ( children: React.ReactNode ) => {
	if ( 'string' === typeof children ) {
		return children;
	}
	const rows: React.ReactNode[] = [];
	let text: React.ReactNode[] = [];
	const flushText = () => {
		if ( text.some( part => '' !== String( part ).trim() ) ) {
			rows.push( <div key={ `text-${ rows.length }` }>{ text }</div> );
		}
		text = [];
	};
	Children.toArray( children ).forEach( child => {
		if ( isValidElement( child ) ) {
			flushText();
			rows.push( child );
		} else {
			text.push( child );
		}
	} );
	flushText();
	return rows;
};

const Content = ( { padding = 6, gap = 4, className, children }: DrawerContentProps ) => (
	<VStack
		className={ classnames( 'newspack-drawer__content', className ) }
		spacing={ gap }
		// A custom property, not inline padding, so the stylesheet's seam rules win.
		style={ { '--newspack-drawer-content-padding': `${ padding * 4 }px` } as React.CSSProperties }
	>
		{ asRows( children ) }
	</VStack>
);

export default Content;
