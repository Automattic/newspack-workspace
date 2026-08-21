/**
 * WordPress dependencies.
 */
import { Children, forwardRef, Fragment, isValidElement } from '@wordpress/element';
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
		// A Fragment is part of the run, not a block of its own:
		// `createInterpolateElement` returns one, and the sentence it holds
		// belongs in the description wrapper like any other text.
		if ( isValidElement( child ) && Fragment !== child.type ) {
			flushText();
			parts.push( child );
		} else {
			text.push( child );
		}
	} );
	flushText();

	return parts;
};

const Footer = forwardRef< HTMLDivElement, StatCardFooterProps >( function Footer( { className, children, ...props }, ref ) {
	useStatCardContext();

	return (
		<Stack
			ref={ ref }
			direction="column"
			align="flex-start"
			gap="xs"
			className={ classnames( 'newspack-stat-card__footer', className ) }
			{ ...props }
		>
			{ asParts( children ) }
		</Stack>
	);
} );

export default Footer;
