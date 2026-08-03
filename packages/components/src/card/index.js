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
import './style.scss';

/**
 * External dependencies
 */
import classNames from 'classnames';

class Card extends Component {
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
			// Pass as `true` to render using WP Core's Card component: https://wordpress.github.io/gutenberg/?path=/docs/components-card--docs
			__experimentalCoreCard,
			// Pass props supported by WP Core's Card component in this single prop.
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
		return <div className={ classes } { ...otherProps } />;
	}
}

export default Card;
