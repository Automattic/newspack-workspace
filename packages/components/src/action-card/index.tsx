/**
 * Action Card
 */

/**
 * WordPress dependencies
 */
import { Draggable, ExternalLink, Notice, ToggleControl } from '@wordpress/components';
import { RawHTML, useEffect, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { Icon, check, chevronDown, chevronUp, dragHandle } from '@wordpress/icons';
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button, Card, Grid, Handoff, Waiting } from '../';
import type { CardBadge } from '../types';
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';
import type { DragEvent, ReactNode, RefObject } from 'react';

export interface ActionCardProps {
	id?: string;
	title?: ReactNode;
	titleLink?: string | null;
	href?: string | null;
	description?: ReactNode | ( () => ReactNode );
	actionText?: ReactNode | null;
	badges?: CardBadge[];
	className?: string;
	indent?: boolean | string;
	notification?: ReactNode | Error;
	notificationLevel?: 'error' | 'warning' | 'info' | 'success';
	notificationHTML?: boolean;
	isSmall?: boolean;
	isMedium?: boolean;
	disabled?: boolean | string;
	hasGreyHeader?: boolean;
	hasWhiteHeader?: boolean;
	heading?: 1 | 2 | 3 | 4 | 5 | 6;
	toggleChecked?: boolean;
	toggleOnChange?: ( value: boolean ) => void;
	togglePosition?: 'leading' | 'trailing';
	actionContent?: ReactNode;
	error?: Error | string | null;
	handoff?: string | null;
	handoffUrl?: string | null;
	bannerText?: string | null;
	bannerButtonText?: string | null;
	isErrorStatus?: boolean;
	checkbox?: 'checked' | 'unchecked' | false;
	isChecked?: boolean;
	isPending?: boolean;
	isWaiting?: boolean;
	isButtonEnabled?: boolean;
	children?: ReactNode;
	editLink?: string;
	image?: string | false | null;
	imageLink?: string;
	simple?: boolean;
	onClick?: () => void;
	secondaryActionText?: ReactNode;
	onSecondaryActionClick?: () => void;
	secondaryDestructive?: boolean;
	noBorder?: boolean;
	noMargin?: boolean;
	collapse?: boolean;
	expandable?: boolean;
	isExpanded?: boolean;
	draggable?: boolean;
	dragIndex?: number;
	onDragCallback?: ( index: number ) => void;
	dragWrapperRef?: RefObject< HTMLElement | null >;
}

const NOTIFICATION_LEVELS: ActionCardProps[ 'notificationLevel' ][] = [ 'error', 'info', 'success', 'warning' ];

/**
 * Collect a notification's text, including text nested inside React elements.
 *
 * Segments join with a space so that adjacent elements do not weld together: by the
 * time speak() sees this string the markup that separated them is already gone.
 *
 * @param value Notification content.
 * @return The collected text.
 */
const getNotificationText = ( value: unknown ): string => {
	if ( typeof value === 'string' ) {
		return value;
	}
	if ( typeof value === 'number' ) {
		return String( value );
	}
	if ( value instanceof Error ) {
		return value.message;
	}
	if ( Array.isArray( value ) ) {
		return value.map( getNotificationText ).join( ' ' );
	}
	const { children } = ( value as { props?: { children?: ReactNode } } )?.props ?? {};
	return children === undefined ? '' : getNotificationText( children );
};

/**
 * Derive a plain-text announcement from a notification.
 *
 * Notice defaults spokenMessage to its children and runs renderToString over them
 * during render, which corrupts the hook dispatcher when those children are
 * components. A notification can be any element, so the announcement is always
 * built from its text instead of letting that default apply. Tags in an HTML
 * notification stay in, since speak() strips them itself. Entities are decoded so
 * the announcement matches the text on screen, which getNotificationContent decodes
 * for the same reason.
 *
 * @param notification Notification content.
 * @return Message to announce.
 */
const getSpokenNotification = ( notification: unknown ): string =>
	decodeEntities( getNotificationText( notification ) ).replace( /\s+/g, ' ' ).trim();

/**
 * Decode a notification's own text for display.
 *
 * React does not decode entities in text children, and notification text is often
 * server-sourced, so a message carrying `&#8217;` would otherwise show the entity
 * and announce the character. The HTML branch is left alone: there the browser's
 * parser decodes as it renders.
 *
 * @param value Notification content.
 * @return The content, with its own strings decoded.
 */
const getNotificationContent = ( value: unknown ): ReactNode => {
	if ( value instanceof Error ) {
		return decodeEntities( value.message );
	}
	if ( typeof value === 'string' ) {
		return decodeEntities( value );
	}
	if ( Array.isArray( value ) ) {
		return value.map( getNotificationContent );
	}
	return value as ReactNode;
};

/**
 * ActionCard component
 */
const ActionCard = ( {
	badges,
	className,
	checkbox,
	children,
	collapse,
	disabled,
	title,
	heading = 2,
	description,
	handoff,
	handoffUrl,
	bannerText,
	bannerButtonText,
	editLink,
	href,
	notification,
	notificationLevel,
	notificationHTML,
	actionContent,
	actionText,
	secondaryActionText,
	secondaryDestructive,
	id,
	image,
	imageLink,
	indent,
	isSmall,
	isMedium,
	simple,
	onClick,
	onSecondaryActionClick,
	isWaiting,
	titleLink,
	toggleChecked = false,
	toggleOnChange,
	togglePosition = 'leading',
	hasGreyHeader,
	hasWhiteHeader,
	noBorder,
	isPending,
	expandable = false,
	isExpanded,
	isButtonEnabled = false,
	// Draggable props. All are required to enable drag sorting.
	draggable = false,
	dragIndex,
	dragWrapperRef,
	onDragCallback,
}: ActionCardProps ) => {
	// A badge with no label paints an empty pill, since the library Badge styles its
	// wrapper rather than its text. Callers derive labels from data that can be absent
	// (a gateway with no connection status, an unmapped placement), so drop those here
	// rather than asking every caller to guard its own array.
	const visibleBadges = ( badges || [] ).filter( ( badge ): badge is CardBadge & { label: string } => Boolean( badge?.label ) );
	const [ expanded, setExpanded ] = useState( Boolean( isExpanded ) );
	const [ dragging, setDragging ] = useState( false );
	const [ targetIndex, setTargetIndex ] = useState< number | null >( null );
	const [ dragRef, setDragRef ] = useState< RefObject< HTMLElement | null > | null >( null );

	useEffect( () => {
		if ( typeof isExpanded === 'boolean' ) {
			setExpanded( isExpanded );
		}
	}, [ isExpanded ] );

	useEffect( () => {
		if ( dragWrapperRef && ! dragRef ) {
			setDragRef( dragWrapperRef );
		}
	}, [ dragWrapperRef?.current ] );

	useEffect( () => {
		if ( collapse && expanded ) {
			setExpanded( false );
		}
	}, [ collapse ] );

	const hasChildren = notification || children;
	const notificationContent = getNotificationContent( notification );
	const classes = classnames(
		'newspack-action-card',
		simple && 'newspack-card--is-clickable',
		hasGreyHeader && 'newspack-card--has-grey-header',
		hasWhiteHeader && 'newspack-card--has-white-header',
		!! hasChildren && 'newspack-card--has-children',
		indent && 'newspack-card--indent',
		isSmall && 'is-small',
		isMedium && 'is-medium',
		checkbox && 'has-checkbox',
		expandable && 'is-expandable',
		draggable && 'is-draggable',
		!! actionContent && 'has-action-content',
		className
	);
	const backgroundImageStyles = ( url: string | false | null | undefined ) => {
		return url ? { backgroundImage: `url(${ url })` } : {};
	};
	const titleProps = toggleOnChange && ! titleLink && ! disabled ? { onClick: () => toggleOnChange( ! toggleChecked ), tabIndex: 0 } : {};
	const togglePositionClass = togglePosition === 'trailing' ? 'is-toggle-trailing' : 'is-toggle-leading';
	const hasInternalLink = href && href.indexOf( 'http' ) !== 0;
	const isDisplayingSecondaryAction = secondaryActionText && onSecondaryActionClick;
	const HeadingTag = `h${ heading }` as const;

	const cardContent = (
		<>
			<div className="newspack-action-card__region newspack-action-card__region-top">
				{ toggleOnChange && (
					<ToggleControl
						label={ undefined }
						checked={ toggleChecked }
						onChange={ toggleOnChange }
						disabled={ Boolean( disabled ) }
						className={ togglePositionClass }
					/>
				) }
				{ image && ! toggleOnChange && (
					<div className="newspack-action-card__region newspack-action-card__region-left">
						<a href={ imageLink }>
							<div className="newspack-action-card__image" style={ backgroundImageStyles( image ) } />
						</a>
					</div>
				) }
				{ checkbox && ! toggleOnChange && (
					<div className="newspack-action-card__region newspack-action-card__region-left">
						<span
							className={ classnames(
								'newspack-checkbox-icon',
								'is-primary',
								'checked' === checkbox && 'newspack-checkbox-icon--checked',
								isPending && 'newspack-checkbox-icon--pending'
							) }
						>
							{ 'checked' === checkbox && <Icon icon={ check } /> }
						</span>
					</div>
				) }
				<div className="newspack-action-card__region newspack-action-card__region-center">
					<Grid columns={ 1 } gutter={ 8 } noMargin>
						<HeadingTag>
							<span className="newspack-action-card__title" { ...titleProps }>
								{ titleLink && <a href={ titleLink }>{ title }</a> }
								{ ! titleLink && expandable && (
									<Button isLink onClick={ () => setExpanded( ! expanded ) }>
										{ title }
									</Button>
								) }
								{ ! titleLink && ! expandable && title }
							</span>
							{ visibleBadges.map( ( { label, intent }, i ) => (
								<Badge key={ `badge-${ i }` } intent={ intent ?? 'none' }>
									{ label }
								</Badge>
							) ) }
						</HeadingTag>
						{ description && (
							<p>
								{ typeof description === 'string' && description }
								{ typeof description === 'function' && description() }
							</p>
						) }
					</Grid>
				</div>
				{ ! expandable && ( actionText || isDisplayingSecondaryAction || actionContent ) && (
					<div className="newspack-action-card__region newspack-action-card__region-right">
						{ /* eslint-disable no-nested-ternary */ }
						{ actionContent && actionContent }
						{ actionText &&
							( handoff ? (
								<Handoff
									plugin={ handoff }
									editLink={ editLink }
									bannerText={ bannerText }
									bannerButtonText={ bannerButtonText }
									compact
									isLink
								>
									{ actionText }
								</Handoff>
							) : handoffUrl ? (
								<Handoff url={ handoffUrl } bannerText={ bannerText } bannerButtonText={ bannerButtonText } compact isLink>
									{ actionText }
								</Handoff>
							) : onClick || hasInternalLink ? (
								<Button
									disabled={ Boolean( disabled ) && ! isButtonEnabled }
									isLink
									href={ href || undefined }
									onClick={ onClick }
									className="newspack-action-card__primary_button"
								>
									{ actionText }
								</Button>
							) : href ? (
								<ExternalLink href={ href } className="newspack-action-card__primary_button">
									{ actionText }
								</ExternalLink>
							) : (
								<div className="newspack-action-card__container">
									{ actionText }
									{ isWaiting && <Waiting isRight /> }
								</div>
							) ) }
						{ /* eslint-enable no-nested-ternary */ }
						{ isDisplayingSecondaryAction && (
							<Button
								isLink
								onClick={ onSecondaryActionClick }
								className="newspack-action-card__secondary_button"
								isDestructive={ secondaryDestructive }
							>
								{ secondaryActionText }
							</Button>
						) }
					</div>
				) }
				{ expandable && (
					<Button onClick={ () => setExpanded( ! expanded ) }>
						<Icon icon={ expanded ? chevronUp : chevronDown } height={ 24 } width={ 24 } />
					</Button>
				) }
			</div>
			{ notification && NOTIFICATION_LEVELS.includes( notificationLevel ) && (
				<div className="newspack-action-card__notification newspack-action-card__region-children">
					<Notice status={ notificationLevel } isDismissible={ false } spokenMessage={ getSpokenNotification( notification ) }>
						{ notificationHTML && typeof notification === 'string' ? (
							<RawHTML className="newspack-action-card__notification-html">{ notification }</RawHTML>
						) : (
							notificationContent
						) }
					</Notice>
				</div>
			) }
			{ children && ( ( expandable && expanded ) || ! expandable ) ? (
				<div className="newspack-action-card__region-children">{ children }</div>
			) : null }
		</>
	);

	const dragContainer = dragRef?.current;
	if ( draggable && dragContainer && typeof dragIndex === 'number' && onDragCallback && id ) {
		let wrapperRect = dragContainer.getBoundingClientRect();
		let draggableCards: ( Element | null )[] = Array.prototype.slice.call(
			dragContainer.querySelectorAll( '.newspack-action-card__draggable-wrapper' )
		);
		const isFirstTarget = dragIndex === 0;
		const isLastTarget = dragIndex === draggableCards.length - 1;
		const handleDragStart = () => {
			draggableCards = Array.prototype.slice.call( dragContainer.querySelectorAll( '.newspack-action-card__draggable-wrapper' ) );
			wrapperRect = dragContainer.getBoundingClientRect();
			if ( dragging ) {
				return;
			}
			setTargetIndex( dragIndex );
			setDragging( true );
		};
		const handleDragEnd = () => {
			if ( targetIndex !== null && targetIndex !== dragIndex ) {
				onDragCallback( targetIndex );
			}
			setTargetIndex( null );
			setDragging( false );
		};
		const handleDragOver = ( e: DragEvent ) => {
			const isDraggingToTop = e.pageY <= wrapperRect.top + window.scrollY;
			const isDraggingToBottom = e.pageY >= wrapperRect.bottom + window.scrollY;
			const target = ( e.target as Element ).closest( '.newspack-action-card__draggable-wrapper' );

			if ( isDraggingToTop || isDraggingToBottom || target ) {
				setTargetIndex( draggableCards.indexOf( target ) );

				// If dragging the element over itself or over an invalid target, cancel the drop.
				// A null targetIndex compares like 0 here, matching the pre-TS coercion.
				if ( 0 > ( targetIndex ?? 0 ) || targetIndex === dragIndex + 1 ) {
					setTargetIndex( dragIndex );
				}

				// Handle dropping before the first item.
				if ( isDraggingToTop ) {
					setTargetIndex( 0 );
				}

				// Handle dropping after the last item.
				if ( isDraggingToBottom ) {
					setTargetIndex( draggableCards.length );
				}
			}
		};

		return (
			<div className={ 'newspack-action-card__draggable-wrapper' + ( dragging ? ' is-dragging' : '' ) } id={ `draggable-card-${ id }` }>
				<Draggable
					elementId={ `draggable-card-${ id }` }
					transferData={ {} }
					onDragStart={ handleDragStart }
					onDragEnd={ handleDragEnd }
					onDragOver={ handleDragOver }
				>
					{ ( { onDraggableStart, onDraggableEnd } ) => (
						<Card className={ classes } onClick={ simple && onClick } id={ id ?? null } noBorder={ noBorder }>
							<div className="newspack-action-card__draggable-controls">
								<div className="drag-handle" draggable onDragStart={ onDraggableStart } onDragEnd={ onDraggableEnd }>
									<Icon icon={ dragHandle } />
								</div>
								<div className="movers">
									<Button
										icon={ chevronUp }
										onClick={ () => onDragCallback( dragIndex - 1 ) }
										disabled={ isFirstTarget }
										label={ __( 'Move one position up', 'newspack-plugin' ) }
									/>
									<Button
										icon={ chevronDown }
										onClick={ () => onDragCallback( dragIndex + 1 ) }
										disabled={ isLastTarget }
										label={ __( 'Move one position down', 'newspack-plugin' ) }
									/>
								</div>
							</div>
							{ cardContent }
						</Card>
					) }
				</Draggable>
			</div>
		);
	}

	return (
		<Card className={ classes } onClick={ simple && onClick } id={ id ?? null } noBorder={ noBorder }>
			{ cardContent }
		</Card>
	);
};

export default ActionCard;
