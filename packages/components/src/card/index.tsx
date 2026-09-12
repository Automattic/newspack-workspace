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
	/** Forwarded to CoreCard when __experimentalCoreCard is set. */
	actionType?: CoreCardProps[ 'actionType' ];
	buttonsCard?: boolean;
	/** Forwarded to CoreCard when __experimentalCoreCard is set. */
	size?: CoreCardProps[ 'size' ];
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
	id?: string | number | null;
	// onToggle is omitted from the div attributes so the rest props stay compatible
	// with CoreCard's own onToggle when forwarded.
} & Omit< HTMLAttributes< HTMLDivElement >, 'onClick' | 'id' | 'onToggle' >;

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
			// Only honoured on the core-card branch below.
			size,
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
				size,
				// A false onClick (from `onClick={ cond && handler }`) is normalized away
				// so it never reaches CoreCard's handler-typed prop.
				onClick: onClick || undefined,
				id,
				...otherProps,
				...__experimentalCoreProps,
			};
			return <CoreCard { ...props } />;
		}
		const classes = classNames(
			'newspack-card',
			className,
			headerActions && 'newspack-card__header-actions',
			isMedium && 'newspack-card__is-medium',
			isNarrow && 'newspack-card__is-narrow',
			isSmall && 'newspack-card__is-small',
			isWhite && 'newspack-card__is-white',
			noBorder && 'newspack-card__no-border'
		);
		// onClick/id pass through a spread (as part of the rest props before the split
		// destructure) — a false onClick and null id are normalized away, and a numeric
		// id is stringified (matching what React renders for the id attribute anyway).
		const passThroughProps = { onClick: onClick || undefined, id: id === null || id === undefined ? undefined : String( id ) };
		return <div className={ classes } { ...passThroughProps } { ...otherProps } />;
	}
}

export default Card;
