/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useInstanceId } from '@wordpress/compose';
import {
	Button,
	FormToggle,
	Modal,
	Notice,
	Spinner,
	Tooltip,
	VisuallyHidden,
	__experimentalConfirmDialog as ConfirmDialog, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Icon, chevronDown, chevronUp, close, dragHandle } from '@wordpress/icons';
import { Badge, Card } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import './style.scss';

export const moveItem = ( items, from, to ) => {
	if ( from === to || from < 0 || to < 0 || from >= items.length || to >= items.length ) {
		return items;
	}
	const next = [ ...items ];
	const [ moved ] = next.splice( from, 1 );
	next.splice( to, 0, moved );
	return next;
};

const OVERLAY_SELECTOR = '.components-modal__screen-overlay';

// The overlay carries the dismissal handling and the drop target, and `Modal`
// forwards its ref to it today. Deriving it from whatever node arrives keeps
// both working if core ever forwards that ref somewhere else.
export const findOverlay = node =>
	node?.closest?.( OVERLAY_SELECTOR ) ||
	node?.querySelector?.( OVERLAY_SELECTOR ) ||
	node?.ownerDocument?.querySelector( OVERLAY_SELECTOR ) ||
	null;

// Disabled controls stay focusable here, so they report `aria-disabled` rather
// than the native attribute.
const isAvailable = button => !! button && ! button.matches( ':disabled, [aria-disabled="true"]' );

// Speech input matches on the visible label, so it has to open the accessible
// name. Composing both from one translated string keeps that true in every locale.
const nameWithTitle = ( label, title ) =>
	sprintf(
		/* translators: keep %1$s first: it repeats the button's visible label, which speech input matches on. 1: the control's visible label. 2: title of the content being moved. */
		__( '%1$s: %2$s', 'newspack-plugin' ),
		label,
		title
	);

/**
 * Put a short list of items in order and, optionally, choose which are on.
 * Changes apply on Save.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.title       Modal title.
 * @param {Array}    props.ids         Item IDs, in their current order.
 * @param {Function} props.fetchItems  Resolves the IDs to `{ value, label, badge? }`, where `badge` is `{ label, tooltip? }`.
 * @param {Function} props.onSave      Called with the new order and, when `enabledIds` is given, the enabled IDs: those
 *                                     on, in that order, then any from `enabledIds` the modal had no row for, kept as they were.
 * @param {Function} props.onClose     Called when the modal closes without saving.
 * @param {Array}    props.enabledIds  Optional. Gives each row an on/off toggle, starting on for these IDs. Save is held while none is on.
 * @param {string}   props.description Optional. Introduces the list, above it.
 * @param {string}   props.noneOnHelp  Why Save is held while no toggle is on.
 */
const ReorderModal = ( {
	title,
	ids,
	fetchItems,
	onSave,
	onClose,
	enabledIds,
	description,
	noneOnHelp = __( 'Turn on at least one item to save.', 'newspack-plugin' ),
} ) => {
	const hasToggles = Array.isArray( enabledIds );
	const instanceId = useInstanceId( ReorderModal, 'newspack-reorder-modal' );
	const moveUpLabel = __( 'Move Up', 'newspack-plugin' );
	const moveDownLabel = __( 'Move Down', 'newspack-plugin' );
	const [ items, setItems ] = useState( null );
	const [ dragIndex, setDragIndex ] = useState( null );
	const [ hasFetchError, setHasFetchError ] = useState( false );
	const [ isConfirmingDiscard, setIsConfirmingDiscard ] = useState( false );
	const listRef = useRef( null );
	const overlayRef = useRef( null );
	const pendingFocus = useRef( null );
	const closeRef = useRef( null );
	const preDragItems = useRef( null );
	const dragBlocked = useRef( false );
	const captureOverlay = useCallback( node => {
		overlayRef.current = findOverlay( node );
	}, [] );

	// The order the modal opened with. Snapshotted so a change to `ids` from
	// elsewhere in the editor cannot desynchronise it from `items`.
	const openedWith = useRef( ids );
	const openedEnabled = useRef( hasToggles ? enabledIds : [] );
	const [ enabled, setEnabled ] = useState( () => new Set( openedEnabled.current ) );

	const isReordered = !! items && items.some( ( item, index ) => item.id !== openedWith.current[ index ] );
	const isToggled = enabled.size !== openedEnabled.current.length || openedEnabled.current.some( id => ! enabled.has( id ) );
	const isDirty = isReordered || ( hasToggles && isToggled );
	// Enabled IDs without a row can't be switched off here, so Save keeps them.
	const enabledWithoutRow = openedEnabled.current.filter( id => ! openedWith.current.includes( id ) );
	const isNoneOn = hasToggles && 0 === enabled.size && 0 === enabledWithoutRow.length;

	const toggleItem = id =>
		setEnabled( current => {
			const next = new Set( current );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );

	useEffect( () => {
		let cancelled = false;
		const openIds = openedWith.current;
		const withLabels = ( labels, badges ) =>
			openIds.map( id => ( { id, label: labels[ id ] || __( '(no title)', 'newspack-plugin' ), badge: badges[ id ] } ) );

		fetchItems( openIds )
			.then( results => {
				if ( cancelled ) {
					return;
				}
				const labels = {};
				const badges = {};
				results.forEach( ( { value, label, badge } ) => {
					labels[ value ] = label;
					badges[ value ] = badge;
				} );
				const loaded = withLabels( labels, badges );
				setItems( loaded );
				speak(
					sprintf(
						/* translators: %d: number of items in the list. */
						_n( '%d item ready to reorder.', '%d items ready to reorder.', loaded.length, 'newspack-plugin' ),
						loaded.length
					)
				);
			} )
			.catch( error => {
				// eslint-disable-next-line no-console
				console.error( 'Newspack: could not prepare the items to reorder.', error );
				if ( ! cancelled ) {
					setHasFetchError( true );
					// Only to leave the loading state: the error view lists nothing.
					setItems( [] );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	// A chevron that reaches an end of the list stops accepting moves, so send
	// focus to the row's other chevron.
	useEffect( () => {
		const pending = pendingFocus.current;
		pendingFocus.current = null;
		if ( ! pending || ! listRef.current ) {
			return;
		}
		const row = listRef.current.querySelector( `[data-item-id="${ pending.id }"]` );
		if ( ! row ) {
			return;
		}
		const preferred = row.querySelector( `[data-direction="${ pending.direction }"]` );
		const fallback = row.querySelector( `[data-direction="${ 'up' === pending.direction ? 'down' : 'up' }"]` );
		if ( isAvailable( preferred ) ) {
			preferred.focus();
		} else if ( isAvailable( fallback ) ) {
			fallback.focus();
		}
	}, [ items ] );

	const moveTo = ( from, to, direction ) => {
		const next = moveItem( items, from, to );
		if ( next === items ) {
			return;
		}
		pendingFocus.current = { id: items[ from ].id, direction };
		setItems( next );
		speak(
			sprintf(
				/* translators: 1: title of the moved item. 2: its new position. 3: total number of items. */
				__( '%1$s moved to position %2$d of %3$d.', 'newspack-plugin' ),
				items[ from ].label,
				to + 1,
				items.length
			)
		);
	};

	const requestClose = () => {
		if ( isDirty ) {
			setIsConfirmingDiscard( true );
			return;
		}
		onClose();
	};

	useEffect( () => {
		closeRef.current = requestClose;
	}, [ requestClose ] );

	const handleKeyDown = event => {
		if ( 'Escape' !== event.key || event.defaultPrevented ) {
			return;
		}
		event.preventDefault();
		requestClose();
	};

	// `Modal` plays its exit animation before it reports a close request, which
	// would fade the modal out and back in whenever the confirmation vetoes one.
	// Its own dismissal handlers are off, so drive the overlay press here.
	useEffect( () => {
		const overlay = overlayRef.current;
		if ( ! overlay ) {
			return;
		}
		let pressedOverlay = false;
		const onPointerDown = event => {
			pressedOverlay = event.target === overlay;
			if ( pressedOverlay ) {
				event.preventDefault();
			}
		};
		const onPointerUp = event => {
			const dismisses = pressedOverlay && 0 === event.button && event.target === overlay;
			pressedOverlay = false;
			if ( dismisses ) {
				closeRef.current();
			}
		};
		overlay.addEventListener( 'pointerdown', onPointerDown );
		overlay.addEventListener( 'pointerup', onPointerUp );
		return () => {
			overlay.removeEventListener( 'pointerdown', onPointerDown );
			overlay.removeEventListener( 'pointerup', onPointerUp );
		};
	}, [] );

	// Rows are the only drop targets, and the frame's own padding is not one, so a
	// release a few pixels wide of the list would report a cancelled drag and undo
	// the reorder. Accepting the drop across the whole dialog leaves "cancelled"
	// meaning only Escape or a release outside it.
	useEffect( () => {
		const frame = overlayRef.current?.querySelector( '.components-modal__frame' );
		if ( ! frame ) {
			return;
		}
		const accept = event => event.preventDefault();
		frame.addEventListener( 'dragover', accept );
		frame.addEventListener( 'drop', accept );
		return () => {
			frame.removeEventListener( 'dragover', accept );
			frame.removeEventListener( 'drop', accept );
		};
	}, [] );

	const handleDragOver = ( event, index ) => {
		event.preventDefault();
		if ( null === dragIndex ) {
			return;
		}
		event.dataTransfer.dropEffect = 'move';
		if ( dragIndex === index ) {
			return;
		}
		setItems( moveItem( items, dragIndex, index ) );
		setDragIndex( index );
	};

	return (
		<>
			<Modal
				ref={ captureOverlay }
				title={ title }
				onRequestClose={ requestClose }
				onKeyDown={ handleKeyDown }
				isDismissible={ false }
				shouldCloseOnEsc={ false }
				shouldCloseOnClickOutside={ false }
				headerActions={ <Button size="compact" icon={ close } label={ __( 'Close', 'newspack-plugin' ) } onClick={ requestClose } /> }
				size="medium"
				className="newspack-reorder-modal"
				aria={ { describedby: description ? `${ instanceId }-description` : undefined } }
			>
				<div className="newspack-reorder-modal__body" aria-busy={ ! items }>
					{ description && (
						<p id={ `${ instanceId }-description` } className="newspack-reorder-modal__description">
							{ description }
						</p>
					) }
					{ ! items && (
						<div className="newspack-reorder-modal__loading">
							<Spinner />
							<span className="screen-reader-text">{ __( 'Loading content…', 'newspack-plugin' ) }</span>
						</div>
					) }
					{ items && hasFetchError && (
						<>
							<Notice status="error" isDismissible={ false } className="newspack-reorder-modal__notice">
								{ __( 'The content could not be loaded, so the order cannot be saved.', 'newspack-plugin' ) }
							</Notice>
							<div className="newspack-reorder-modal__footer">
								<Button variant="tertiary" onClick={ requestClose }>
									{ __( 'Cancel', 'newspack-plugin' ) }
								</Button>
							</div>
						</>
					) }
					{ items && ! hasFetchError && (
						<>
							{ /* `list-style: none` drops list semantics in Safari, so the role is restated. */ }
							{ /* eslint-disable-next-line jsx-a11y/no-redundant-roles */ }
							<ul className="newspack-reorder-modal__list" role="list" ref={ listRef }>
								{ items.map( ( item, index ) => (
									<Card.Root
										key={ item.id }
										render={ <li /> }
										data-item-id={ item.id }
										className={ classnames( 'newspack-reorder-modal__item', {
											'is-dragging': dragIndex === index,
											'is-off': hasToggles && ! enabled.has( item.id ),
										} ) }
										draggable
										// `dragstart` always fires at the draggable row, never at the
										// chevron inside it, so the grab point is recorded separately.
										onPointerDown={ event => {
											dragBlocked.current = !! event.target.closest?.( 'button, input' );
										} }
										onDragStart={ event => {
											if ( dragBlocked.current ) {
												event.preventDefault();
												return;
											}
											preDragItems.current = items;
											// Firefox and Safari will not start a drag until the payload is set.
											event.dataTransfer.setData( 'text/plain', String( item.id ) );
											event.dataTransfer.effectAllowed = 'move';
											setDragIndex( index );
										} }
										onDragOver={ event => handleDragOver( event, index ) }
										onDragEnd={ event => {
											// A cancelled drag reports no drop effect: put the order back.
											if ( 'none' === event.dataTransfer.dropEffect && preDragItems.current ) {
												setItems( preDragItems.current );
											}
											preDragItems.current = null;
											setDragIndex( null );
										} }
										onDrop={ event => event.preventDefault() }
									>
										<span className="newspack-reorder-modal__grip" aria-hidden="true">
											<Icon icon={ dragHandle } />
										</span>
										<span className="newspack-reorder-modal__move">
											<Button
												icon={ chevronUp }
												size="small"
												data-direction="up"
												disabled={ 0 === index }
												accessibleWhenDisabled
												label={ moveUpLabel }
												aria-label={ nameWithTitle( moveUpLabel, item.label ) }
												onClick={ () => moveTo( index, index - 1, 'up' ) }
											/>
											<Button
												icon={ chevronDown }
												size="small"
												data-direction="down"
												disabled={ index === items.length - 1 }
												accessibleWhenDisabled
												label={ moveDownLabel }
												aria-label={ nameWithTitle( moveDownLabel, item.label ) }
												onClick={ () => moveTo( index, index + 1, 'down' ) }
											/>
										</span>
										<Card.Content className="newspack-reorder-modal__content">
											<span className="newspack-reorder-modal__title" title={ item.label }>
												{ item.label }
											</span>
											{ item.badge && (
												<span className="newspack-reorder-modal__badge">
													{ item.badge.tooltip ? (
														<Tooltip text={ item.badge.tooltip }>
															{ /* Hover only: the toggle already carries the badge and tooltip text for keyboard and screen reader users. */ }
															<span aria-hidden="true" tabIndex={ -1 }>
																<Badge intent="draft">{ item.badge.label }</Badge>
															</span>
														</Tooltip>
													) : (
														<span aria-hidden="true">
															<Badge intent="draft">{ item.badge.label }</Badge>
														</span>
													) }
													<VisuallyHidden id={ `${ instanceId }-badge-${ item.id }` }>
														{ item.badge.tooltip ? `${ item.badge.label }: ${ item.badge.tooltip }` : item.badge.label }
													</VisuallyHidden>
												</span>
											) }
										</Card.Content>
										{ hasToggles && (
											<span className="newspack-reorder-modal__toggle">
												<FormToggle
													checked={ enabled.has( item.id ) }
													onChange={ () => toggleItem( item.id ) }
													aria-label={ item.label }
													aria-describedby={ item.badge ? `${ instanceId }-badge-${ item.id }` : undefined }
												/>
											</span>
										) }
									</Card.Root>
								) ) }
							</ul>
							<div className="newspack-reorder-modal__footer">
								<Button variant="tertiary" onClick={ requestClose }>
									{ __( 'Cancel', 'newspack-plugin' ) }
								</Button>
								<Button
									variant="primary"
									disabled={ ! isDirty || isNoneOn }
									accessibleWhenDisabled
									// A button with visible children shows a tooltip only when asked and labelled.
									label={ __( 'Save', 'newspack-plugin' ) }
									showTooltip={ isDirty && isNoneOn }
									description={ isDirty && isNoneOn ? noneOnHelp : undefined }
									onClick={ () => {
										const order = items.map( item => item.id );
										if ( hasToggles ) {
											onSave( order, [ ...order.filter( id => enabled.has( id ) ), ...enabledWithoutRow ] );
										} else {
											onSave( order );
										}
									} }
								>
									{ __( 'Save', 'newspack-plugin' ) }
								</Button>
							</div>
						</>
					) }
				</div>
			</Modal>
			{ isConfirmingDiscard && (
				<ConfirmDialog
					isOpen
					contentLabel={ hasToggles ? __( 'Discard the changes?', 'newspack-plugin' ) : __( 'Discard the new order?', 'newspack-plugin' ) }
					confirmButtonText={ __( 'Discard', 'newspack-plugin' ) }
					cancelButtonText={ __( 'Keep editing', 'newspack-plugin' ) }
					onConfirm={ onClose }
					onCancel={ () => setIsConfirmingDiscard( false ) }
				>
					{ hasToggles
						? __( 'The changes you made will be lost.', 'newspack-plugin' )
						: __( 'The order you set will be lost.', 'newspack-plugin' ) }
				</ConfirmDialog>
			) }
		</>
	);
};

export default ReorderModal;
