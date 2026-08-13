/**
 * WordPress dependencies.
 */
import { Children, isValidElement } from '@wordpress/element';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import { useStatCardContext } from './context';
import type { StatCardFooterProps } from './types';

// A run of text shares one wrapper, so `Applies to { count } products` is one
// sentence rather than three stacked paragraphs. Elements pass through, which
// is how an action lands under the description.
const asParts = ( children: React.ReactNode ) => {
	const parts: React.ReactNode[] = [];
	let text: React.ReactNode[] = [];

	const flushText = () => {
		if ( text.some( part => '' !== String( part ).trim() ) ) {
			parts.push(
				<p key={ `text-${ parts.length }` } className="newspack-stat-card__description">
					{ text }
				</p>
			);
		}
		text = [];
	};

	Children.toArray( children ).forEach( child => {
		if ( isValidElement( child ) ) {
			flushText();
			parts.push( child );
		} else {
			text.push( child );
		}
	} );
	flushText();

	return parts;
};

const Footer = ( { className, children }: StatCardFooterProps ) => {
	useStatCardContext();

	return <div className={ classnames( 'newspack-stat-card__footer', className ) }>{ asParts( children ) }</div>;
};

export default Footer;
