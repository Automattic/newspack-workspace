/**
 * Card using WP Core's Card component.
 * https://wordpress.github.io/gutenberg/?path=/docs/components-card--docs
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button, Card as CardWrapper, CardHeader, CardFooter, DropdownMenu, MenuGroup, MenuItem, ToggleControl } from '@wordpress/components';
import { Icon, chevronDown, chevronRight, chevronUp, dragHandle, moreVertical } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import './style-core.scss';

/**
 * External dependencies
 */
import classNames from 'classnames';
import type { ComponentProps, CSSProperties, ReactNode } from 'react';

type WpIcon = ComponentProps< typeof Icon >[ 'icon' ];

/**
 * A single action rendered in the "more" dropdown menu.
 */
export type CoreCardMenuAction = {
	label: ReactNode;
	icon?: WpIcon;
	action?: () => void;
	href?: string;
	disabled?: boolean;
	destructive?: boolean;
};

/**
 * The action button rendered at the end of the card header.
 */
export type CoreCardHeaderAction = {
	label?: ReactNode;
	icon?: WpIcon;
	href?: string;
	disabled?: boolean;
	destructive?: boolean;
	onClick?: () => void;
	tone?: string;
	variant?: ComponentProps< typeof Button >[ 'variant' ];
};

export type CoreCardProps = {
	/** Dropdown menu actions; a nested array renders as a MenuGroup of sub-actions. */
	actions?: ( CoreCardMenuAction | CoreCardMenuAction[] )[];
	/** chevron | toggle | button | link | none */
	actionType?: string | null;
	as?: keyof JSX.IntrinsicElements;
	buttonsCard?: boolean;
	className?: string;
	footer?: ReactNode;
	header?: ReactNode;
	/** Forwarded to the underlying WP Card; renders as the anchor href for link cards. */
	href?: string;
	headerAction?: CoreCardHeaderAction;
	headerStyle?: CSSProperties;
	childrenStyle?: CSSProperties;
	footerStyle?: CSSProperties;
	disabled?: boolean;
	icon?: WpIcon | null;
	/** A ready-made element rendered in the icon slot, as an alternative to `icon`. */
	iconElement?: ReactNode;
	iconBackgroundColor?: string | boolean;
	isActive?: boolean;
	isDraggable?: boolean;
	isFirstTarget?: boolean;
	isLastTarget?: boolean;
	isNarrow?: boolean;
	/** Renders the card as a chooser: chooser chrome plus hover/focus rings. Pair with `isActive`. */
	isSelectable?: boolean;
	isSmall?: boolean;
	isMedium?: boolean;
	isVertical?: boolean;
	isWhite?: boolean;
	dragIndex?: number | null;
	onDragCallback?: ( fromIndex: number, toIndex: number ) => void;
	onToggle?: ( isActive: boolean ) => void;
	onHeaderClick?: () => void;
	noBorder?: boolean;
	noMargin?: boolean;
	children?: ReactNode;
	hasGreyHeader?: boolean;
	hasHeaderBorder?: boolean;
	title?: string;
	size?: ComponentProps< typeof CardHeader >[ 'size' ];
	isBorderless?: boolean;
	/** Forwarded to the underlying element; the button `type` when rendered as `as="button"`. */
	type?: 'button' | 'submit' | 'reset';
	/** Forwarded to the underlying element, for cards rendered as an interactive control. */
	onClick?: React.MouseEventHandler;
	role?: React.AriaRole;
	tabIndex?: number;
} & React.AriaAttributes;

const CoreCard = ( {
	actions,
	actionType,
	as,
	buttonsCard,
	className,
	footer,
	header,
	headerAction,
	headerStyle,
	childrenStyle,
	footerStyle,
	disabled,
	icon,
	iconElement,
	iconBackgroundColor,
	isActive,
	isDraggable,
	isFirstTarget,
	isLastTarget,
	isNarrow,
	isSelectable,
	isSmall,
	isVertical,
	size,
	dragIndex,
	onDragCallback = () => {},
	onToggle = () => {},
	onHeaderClick,
	noBorder,
	noMargin,
	children = null,
	hasGreyHeader,
	hasHeaderBorder = true,
	...otherProps
}: CoreCardProps ) => {
	const hasActions = ( actions?.length ?? 0 ) > 0;
	// `size` styles the body; a small card ignores it so it stays compact.
	const bodySize = isSmall ? undefined : size;
	const classes = classNames(
		'newspack-card--core',
		className,
		( buttonsCard || as === 'a' ) && 'newspack-card--core__buttons-card',
		hasActions && 'newspack-card--core__header--has-actions',
		isDraggable && 'newspack-card--core__is-draggable',
		isNarrow && 'newspack-card--core__is-narrow',
		isSmall && 'newspack-card--core__is-small',
		isSelectable && 'newspack-card--core__is-selectable',
		bodySize === 'large' && 'newspack-card--core__is-large',
		isVertical && 'newspack-card--core__is-vertical',
		!! ( icon || iconElement ) && 'newspack-card--core__has-icon',
		!! iconBackgroundColor && 'newspack-card--core__has-icon-background-color',
		isActive && 'newspack-card--core__is-active',
		disabled && 'newspack-card--core__is-disabled',
		!! children && 'newspack-card--core__has-children',
		noMargin && 'newspack-card--core__no-margin',
		hasGreyHeader && 'newspack-card--core__has-grey-header'
	);
	let sizeProps = isSmall ? ( 'small' as const ) : size;
	let wrapperAs = as;
	if ( buttonsCard || as === 'a' ) {
		if ( ! isSmall ) {
			sizeProps = 'large';
		}
		if ( as !== 'a' ) {
			wrapperAs = 'a'; // Render as an anchor tag.
		}
	}
	if ( noBorder ) {
		otherProps.isBorderless = true;
	}
	// The current drag position, for the draggable move buttons. NaN when the
	// consumer marked the card draggable without providing a dragIndex.
	const dragPosition = typeof dragIndex === 'number' ? dragIndex : NaN;
	// A button header would nest its interactive children (toggle, header action, actions menu, or
	// drag controls) inside a <button>, which is invalid. Only render the header as a button when a
	// real click handler is supplied and the header has no interactive children of its own.
	const hasInteractiveHeaderChildren = actionType === 'toggle' || !! headerAction || hasActions || isDraggable;
	const headerIsButton = !! onHeaderClick && ! hasInteractiveHeaderChildren;
	// `disabled` and `gap` aren't in CardHeader's typed props (they're forwarded to the
	// underlying element), so they're passed via a spread, which tolerates extra props.
	const headerForwardedProps = {
		as: headerIsButton ? ( 'button' as const ) : undefined,
		gap: 4,
		onClick: headerIsButton && ! disabled ? onHeaderClick : undefined,
		disabled: headerIsButton && disabled ? true : undefined,
		'aria-disabled': headerIsButton && disabled ? true : undefined,
	};
	// `tone` isn't a @wordpress/components Button prop; it's forwarded to the underlying
	// element, so it's passed via a spread, which tolerates extra props.
	const headerActionForwardedProps = {
		tone: headerAction?.tone || 'primary',
	};
	return (
		<CardWrapper as={ wrapperAs } className={ classes } size={ bodySize } { ...otherProps }>
			{ ( header || icon || iconElement ) && (
				<CardHeader
					className={ classNames(
						'newspack-card--core__header',
						isDraggable && 'newspack-card--core__header--is-draggable',
						! hasHeaderBorder && 'newspack-card--core__header--no-border'
					) }
					style={ headerStyle }
					size={ sizeProps }
					{ ...headerForwardedProps }
				>
					{ isDraggable && (
						<div className="newspack-card--core__header__draggable-controls">
							<div className="newspack-card--core__header__draggable-controls__drag-handle">
								<Icon icon={ dragHandle } />
							</div>
							<div className="newspack-card--core__header__draggable-controls__move-buttons">
								<Button
									icon={ chevronUp }
									onClick={ () => onDragCallback( dragPosition, dragPosition - 1 ) }
									disabled={ isFirstTarget }
									label={ __( 'Move one position up', 'newspack-plugin' ) }
									size="small"
								/>
								<Button
									icon={ chevronDown }
									onClick={ () => onDragCallback( dragPosition, dragPosition + 1 ) }
									disabled={ isLastTarget }
									label={ __( 'Move one position down', 'newspack-plugin' ) }
									size="small"
								/>
							</div>
						</div>
					) }
					{ iconElement ? (
						<div className="newspack-card--core__icon-slot">{ iconElement }</div>
					) : (
						icon && (
							<div className="newspack-card--core__icon">
								<Icon icon={ icon } height={ isSmall ? 24 : 48 } width={ isSmall ? 24 : 48 } />
							</div>
						)
					) }
					{ hasActions && actionType === 'toggle' && (
						<ToggleControl
							className="newspack-card--core__action"
							label={ otherProps.title }
							// hideLabelFromVision is not a typed ToggleControl prop; forwarded via spread for prop-parity.
							{ ...{ hideLabelFromVision: true } }
							checked={ isActive }
							onChange={ onToggle }
						/>
					) }
					{ header && <div className="newspack-card--core__header-content">{ header }</div> }
					{ ! hasActions && actionType === 'chevron' && (
						<Icon className="newspack-card--core__action" icon={ chevronRight } height={ 24 } width={ 24 } />
					) }
					{ ! hasActions && actionType === 'toggle' && (
						<ToggleControl
							className="newspack-card--core__action"
							label={ otherProps.title }
							// hideLabelFromVision is not a typed ToggleControl prop; forwarded via spread for prop-parity.
							{ ...{ hideLabelFromVision: true } }
							checked={ isActive }
							onChange={ onToggle }
						/>
					) }
					{ hasActions && actions && (
						<DropdownMenu icon={ moreVertical } label={ __( 'More', 'newspack-plugin' ) }>
							{ () =>
								actions.map( ( action, index ) => {
									// Actions can be an array of sub-actions, which are rendered within a MenuGroup.
									if ( Array.isArray( action ) ) {
										return (
											<MenuGroup key={ index }>
												{ action.map( ( subAction, i ) => {
													return (
														<MenuItem
															key={ i }
															icon={ subAction.icon }
															onClick={ subAction.action }
															// href is only typed on the anchor variant of MenuItem's underlying Button; forwarded via spread.
															{ ...{ href: subAction.href } }
															disabled={ subAction.disabled || false }
															isDestructive={ subAction.destructive || false }
														>
															{ subAction.label }
														</MenuItem>
													);
												} ) }
											</MenuGroup>
										);
									}
									return (
										<MenuItem
											key={ index }
											icon={ action.icon }
											onClick={ action.action }
											// href is only typed on the anchor variant of MenuItem's underlying Button; forwarded via spread.
											{ ...{ href: action.href } }
											disabled={ action.disabled || false }
											isDestructive={ action.destructive || false }
										>
											{ action.label }
										</MenuItem>
									);
								} )
							}
						</DropdownMenu>
					) }
					{ headerAction && (
						<Button
							className="newspack-card--core__header__action"
							icon={ headerAction.icon }
							href={ headerAction.href }
							disabled={ headerAction.disabled || false }
							isDestructive={ headerAction.destructive || false }
							onClick={ headerAction.onClick }
							variant={ headerAction.variant || 'secondary' }
							{ ...headerActionForwardedProps }
						>
							{ headerAction.label }
						</Button>
					) }
				</CardHeader>
			) }
			{ children && (
				<div
					className={ classNames( 'newspack-card--core__body', ! hasHeaderBorder && 'newspack-card--core__body--no-header-border' ) }
					style={ childrenStyle }
				>
					{ children }
				</div>
			) }
			{ footer && (
				<CardFooter size={ sizeProps } style={ footerStyle }>
					{ footer }
				</CardFooter>
			) }
		</CardWrapper>
	);
};

export default CoreCard;
