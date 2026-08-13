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

const Footer = ( { className, children }: StatCardFooterProps ) => {
	useStatCardContext();

	// The common case is a bare description string, so text children are wrapped
	// rather than left to fall through at the card's default size.
	const parts = Children.toArray( children ).map( ( child, index ) =>
		isValidElement( child ) ? (
			child
		) : (
			<p key={ `text-${ index }` } className="newspack-stat-card__description">
				{ child }
			</p>
		)
	);

	return <div className={ classnames( 'newspack-stat-card__footer', className ) }>{ parts }</div>;
};

export default Footer;
