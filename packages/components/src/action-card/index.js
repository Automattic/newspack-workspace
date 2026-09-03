/**
 * Action Card
 */

/**
 * WordPress dependencies
 */
import { Draggable, ExternalLink, Notice, ToggleControl } from '@wordpress/components';
import { RawHTML, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, check, chevronDown, chevronUp, dragHandle } from '@wordpress/icons';
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button, Card, Grid, Handoff, Waiting } from '../';
import { ActionCardProps } from './action-card.d.ts';
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

const NOTIFICATION_LEVELS = [ 'error', 'info', 'success', 'warning' ];

/**
 * Derive a plain-text announcement from a notification.
 *
 * Notice defaults spokenMessage to its children and runs renderToString over them
 * during render, which corrupts the hook dispatcher when those children are
 * components. A notification can be any element, so the announcement is always
 * built from its text parts instead of letting that default apply.
 *
 * @param {*}       notification Notification content.
 * @param {boolean} isHTML       Whether the content is an HTML string.
 * @return {string} Message to announce.
 */
const getSpokenNotification = ( notification, isHTML ) => {
	const parts = Array.isArray( notification ) ? notification : [ notification ];
	const text = parts
		.map( part => {
			if ( typeof part === 'string' ) {
				return part;
			}
			return part instanceof Error ? part.message : '';
		} )
		.join( '' );
	return ( isHTML ? text.replace( /<[^>]*>/g, '' ) : text ).trim();
};

/**
 * ActionCard component
 *
 * @param {ActionCardProps} props Component props.
 * @return {JSX.Element} ActionCard component.
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
} ) => {
	// A badge with no label paints an empty pill, since the library Badge styles its
	// wrapper rather than its text. Callers derive labels from data that can be absent
	// (a gateway with no connection status, an unmapped placement), so drop those here
	// rather than asking every caller to guard its own array.
	const visibleBadges = ( badges || [] ).filter( badge => badge?.label );
	const [ expanded, setExpanded ] = useState( Boolean( isExpanded ) );
	const [ dragging, setDragging ] = useState( false );
	const [ targetIndex, setTargetIndex ] = useState( null );
	const [ dragRef, setDragRef ] = useState( null );

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
	const classes = classnames(
		'newspack-action-card',
		simple && 'newspack-card--is-clickable',
		hasGreyHeader && 'newspack-card--has-grey-header',
		hasWhiteHeader && 'newspack-card--has-white-header',
		hasChildren && 'newspack-card--has-children',
		indent && 'newspack-card--indent',
		isSmall && 'is-small',
		isMedium && 'is-medium',
		checkbox && 'has-checkbox',
		expandable && 'is-expandable',
		draggable && 'is-draggable',
		actionContent && 'has-action-content',
		className
	);
	const backgroundImageStyles = url => {
		return url ? { backgroundImage: `url(${ url })` } : {};
	};
	const titleProps = toggleOnChange && ! titleLink && ! disabled ? { onClick: () => toggleOnChange( ! toggleChecked ), tabIndex: '0' } : {};
	const togglePositionClass = togglePosition === 'trailing' ? 'is-toggle-trailing' : 'is-toggle-leading';
	const hasInternalLink = href && href.indexOf( 'http' ) !== 0;
	const isDisplayingSecondaryAction = secondaryActionText && onSecondaryActionClick;
	const HeadingTag = `h${ heading }`;

	const cardContent = (
		<>
			<div className="newspack-action-card__region newspack-action-card__region-top">
				{ toggleOnChange && (
					<ToggleControl checked={ toggleChecked } onChange={ toggleOnChange } disabled={ disabled } className={ togglePositionClass } />
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
									disabled={ disabled && ! isButtonEnabled }
									isLink
									href={ href }
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
					<Notice
						status={ notificationLevel }
						isDismissible={ false }
						spokenMessage={ getSpokenNotification( notification, notificationHTML ) }
					>
						{ notificationHTML ? <RawHTML>{ notification }</RawHTML> : notification }
					</Notice>
				</div>
			) }
			{ children && ( ( expandable && expanded ) || ! expandable ) ? (
				<div className="newspack-action-card__region-children">{ children }</div>
			) : null }
		</>
	);

	if ( draggable && dragRef?.current && typeof dragIndex === 'number' && onDragCallback && id ) {
		let wrapperRect = dragRef.current.getBoundingClientRect();
		let draggableCards = Array.prototype.slice.call( dragRef.current.querySelectorAll( '.newspack-action-card__draggable-wrapper' ) );
		const isFirstTarget = dragIndex === 0;
		const isLastTarget = dragIndex === draggableCards.length - 1;
		const handleDragStart = () => {
			draggableCards = Array.prototype.slice.call( dragRef.current.querySelectorAll( '.newspack-action-card__draggable-wrapper' ) );
			wrapperRect = dragRef.current.getBoundingClientRect();
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
		const handleDragOver = e => {
			const isDraggingToTop = e.pageY <= wrapperRect.top + window.scrollY;
			const isDraggingToBottom = e.pageY >= wrapperRect.bottom + window.scrollY;
			const target = e.target.closest( '.newspack-action-card__draggable-wrapper' );

			if ( isDraggingToTop || isDraggingToBottom || target ) {
				setTargetIndex( draggableCards.indexOf( target ) );

				// If dragging the element over itself or over an invalid target, cancel the drop.
				if ( 0 > targetIndex || targetIndex === dragIndex + 1 ) {
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
