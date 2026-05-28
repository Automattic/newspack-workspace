/**
 * Action Card
 */

/**
 * WordPress dependencies
 */
import { Draggable, ExternalLink, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, check, chevronDown, chevronUp, dragHandle } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { Button, Card, Grid, Handoff, Notice, Waiting } from '../';
import { ActionCardProps } from './action-card.d.ts';
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * ActionCard component
 * @param {ActionCardProps} props Component props.
 * @return {JSX.Element} ActionCard component.
 */
const ActionCard = ( {
	badge,
	badgeLevel,
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
	const badges = ! Array.isArray( badge ) && badge ? [ badge ] : badge;
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
							{ badges?.length &&
								badges.map( ( badgeText, i ) => (
									<span
										key={ `badge-${ i }` }
										className={ `newspack-action-card__badge newspack-action-card__badge-level-${ badgeLevel }` }
									>
										{ badgeText }
									</span>
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
			{ notification && (
				<div className="newspack-action-card__notification newspack-action-card__region-children">
					{ 'error' === notificationLevel && <Notice noticeText={ notification } isError rawHTML={ notificationHTML } /> }
					{ 'info' === notificationLevel && <Notice noticeText={ notification } rawHTML={ notificationHTML } /> }
					{ 'success' === notificationLevel && <Notice noticeText={ notification } isSuccess rawHTML={ notificationHTML } /> }
					{ 'warning' === notificationLevel && <Notice noticeText={ notification } isWarning rawHTML={ notificationHTML } /> }
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
