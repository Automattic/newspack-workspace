/**
 * WordPress dependencies.
 */
import { Children, isValidElement } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

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
// sentence rather than three stacked paragraphs.
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

	return (
		<Stack direction="column" align="flex-start" gap="xs" className={ classnames( 'newspack-stat-card__footer', className ) }>
			{ asParts( children ) }
		</Stack>
	);
};

export default Footer;
