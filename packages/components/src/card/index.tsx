/**
 * Card
 */

/**
 * WordPress dependencies
 */
import { Component } from '@wordpress/element';

/**
 * Internal dependencies
 */
import CoreCard from './core-card';
import type { CoreCardProps } from './core-card';
import './style.scss';

/**
 * External dependencies
 */
import classNames from 'classnames';
import type { HTMLAttributes, MouseEventHandler } from 'react';

type CardProps = {
	buttonsCard?: boolean;
	headerActions?: boolean;
	isNarrow?: boolean;
	isMedium?: boolean;
	isSmall?: boolean;
	isWhite?: boolean;
	noBorder?: boolean;
	/** Pass as `true` to render using WP Core's Card component: https://wordpress.github.io/gutenberg/?path=/docs/components-card--docs */
	__experimentalCoreCard?: boolean;
	/** Pass props supported by WP Core's Card component in this single prop. */
	__experimentalCoreProps?: CoreCardProps;
	/** `false` is tolerated (and ignored, like `undefined`) so callers can write `onClick={ condition && handler }`. */
	onClick?: MouseEventHandler< HTMLDivElement > | false;
	id?: string | null;
} & Omit< HTMLAttributes< HTMLDivElement >, 'onClick' | 'id' >;

class Card extends Component< CardProps > {
	/**
	 * Render
	 */
	render() {
		const {
			buttonsCard,
			className,
			headerActions,
			isNarrow,
			isMedium,
			isSmall,
			isWhite,
			noBorder,
			__experimentalCoreCard,
			__experimentalCoreProps = {
				actionType: null, // chevron | toggle | button | link | none
				header: null, // Pass a React component to render in a CardHeader component.
				icon: null,
				footer: null, // Pass a React component to render in a CardFooter component.
				noMargin: false,
				isDraggable: false,
				dragIndex: null,
				hasGreyHeader: false,
				onDragCallback: () => {},
			},
			onClick,
			id,
			...otherProps
		} = this.props;
		if ( __experimentalCoreCard ) {
			const props = {
				buttonsCard,
				className,
				isMedium,
				isNarrow,
				isSmall,
				isWhite,
				noBorder,
				onClick,
				id,
				...otherProps,
				...__experimentalCoreProps,
			};
			return <CoreCard { ...props } />;
		}
		const classes = classNames(
			'newspack-card',
			className,
			buttonsCard && 'newspack-card__buttons-card',
			headerActions && 'newspack-card__header-actions',
			isMedium && 'newspack-card__is-medium',
			isNarrow && 'newspack-card__is-narrow',
			isSmall && 'newspack-card__is-small',
			isWhite && 'newspack-card__is-white',
			noBorder && 'newspack-card__no-border'
		);
		return <div className={ classes } onClick={ onClick || undefined } id={ id ?? undefined } { ...otherProps } />;
	}
}

export default Card;
