/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Modal,
	Spinner,
	__experimentalConfirmDialog as ConfirmDialog, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { Icon, chevronDown, chevronUp, close, dragHandle } from '@wordpress/icons';
import { Card } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import './reorder-modal.scss';

export const moveItem = ( items, from, to ) => {
	if ( from === to || from < 0 || to < 0 || from >= items.length || to >= items.length ) {
		return items;
	}
	const next = [ ...items ];
	const [ moved ] = next.splice( from, 1 );
	next.splice( to, 0, moved );
	return next;
};

// Disabled controls stay focusable here, so they report `aria-disabled` rather
// than the native attribute.
const isAvailable = button => !! button && ! button.matches( ':disabled, [aria-disabled="true"]' );

const ReorderModal = ( { title, ids, fetchItems, onSave, onClose } ) => {
	const [ items, setItems ] = useState( null );
	const [ dragIndex, setDragIndex ] = useState( null );
	const [ isConfirmingDiscard, setIsConfirmingDiscard ] = useState( false );
	const listRef = useRef( null );
	const overlayRef = useRef( null );
	const pendingFocus = useRef( null );
	const closeRef = useRef( null );

	// `ids` is the order the modal opened with: it remounts on every open.
	const isDirty = !! items && items.some( ( item, index ) => item.id !== ids[ index ] );

	useEffect( () => {
		let cancelled = false;
		const withLabels = labels => ids.map( id => ( { id, label: labels[ id ] || __( '(no title)', 'newspack-blocks' ) } ) );

		fetchItems( ids )
			.then( results => {
				if ( cancelled ) {
					return;
				}
				const labels = {};
				results.forEach( ( { value, label } ) => {
					labels[ value ] = label;
				} );
				setItems( withLabels( labels ) );
			} )
			.catch( error => {
				// eslint-disable-next-line no-console
				console.error( 'Newspack Blocks: could not prepare the content to reorder.', error );
				if ( ! cancelled ) {
					setItems( withLabels( {} ) );
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
				/* translators: 1: new position of the moved item. 2: total number of items. */
				__( 'Moved to position %1$d of %2$d.', 'newspack-blocks' ),
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
				ref={ overlayRef }
				title={ title }
				onRequestClose={ requestClose }
				onKeyDown={ handleKeyDown }
				isDismissible={ false }
				shouldCloseOnEsc={ false }
				shouldCloseOnClickOutside={ false }
				headerActions={ <Button size="compact" icon={ close } label={ __( 'Close', 'newspack-blocks' ) } onClick={ requestClose } /> }
				size="medium"
				className="newspack-blocks-reorder-modal"
			>
				<div className="newspack-blocks-reorder-modal__body">
					{ ! items ? (
						<div className="newspack-blocks-reorder-modal__loading">
							<Spinner />
						</div>
					) : (
						<>
							<ul className="newspack-blocks-reorder-modal__list" ref={ listRef }>
								{ items.map( ( item, index ) => (
									<Card.Root
										key={ item.id }
										render={ <li /> }
										data-item-id={ item.id }
										className={ classnames( 'newspack-blocks-reorder-modal__item', {
											'is-dragging': dragIndex === index,
										} ) }
										draggable
										// Firefox and Safari will not start a drag until the payload is set.
										onDragStart={ event => {
											event.dataTransfer.setData( 'text/plain', String( item.id ) );
											event.dataTransfer.effectAllowed = 'move';
											setDragIndex( index );
										} }
										onDragOver={ event => handleDragOver( event, index ) }
										onDragEnd={ () => setDragIndex( null ) }
										onDrop={ event => event.preventDefault() }
									>
										<span className="newspack-blocks-reorder-modal__grip" aria-hidden="true">
											<Icon icon={ dragHandle } />
										</span>
										<span className="newspack-blocks-reorder-modal__move">
											<Button
												icon={ chevronUp }
												size="small"
												data-direction="up"
												disabled={ 0 === index }
												accessibleWhenDisabled
												label={ __( 'Move Up', 'newspack-blocks' ) }
												aria-label={ sprintf(
													/* translators: %s: title of the content being moved. */
													__( 'Move "%s" up', 'newspack-blocks' ),
													item.label
												) }
												onClick={ () => moveTo( index, index - 1, 'up' ) }
											/>
											<Button
												icon={ chevronDown }
												size="small"
												data-direction="down"
												disabled={ index === items.length - 1 }
												accessibleWhenDisabled
												label={ __( 'Move Down', 'newspack-blocks' ) }
												aria-label={ sprintf(
													/* translators: %s: title of the content being moved. */
													__( 'Move "%s" down', 'newspack-blocks' ),
													item.label
												) }
												onClick={ () => moveTo( index, index + 1, 'down' ) }
											/>
										</span>
										<Card.Content className="newspack-blocks-reorder-modal__title">{ item.label }</Card.Content>
									</Card.Root>
								) ) }
							</ul>
							<div className="newspack-blocks-reorder-modal__footer">
								<Button variant="tertiary" onClick={ requestClose }>
									{ __( 'Cancel', 'newspack-blocks' ) }
								</Button>
								<Button
									variant="primary"
									disabled={ ! isDirty }
									accessibleWhenDisabled
									onClick={ () => onSave( items.map( item => item.id ) ) }
								>
									{ __( 'Save', 'newspack-blocks' ) }
								</Button>
							</div>
						</>
					) }
				</div>
			</Modal>
			{ isConfirmingDiscard && (
				<ConfirmDialog
					isOpen
					contentLabel={ __( 'Discard the new order?', 'newspack-blocks' ) }
					confirmButtonText={ __( 'Discard', 'newspack-blocks' ) }
					cancelButtonText={ __( 'Keep editing', 'newspack-blocks' ) }
					onConfirm={ onClose }
					onCancel={ () => setIsConfirmingDiscard( false ) }
				>
					{ __( 'Discard the new order?', 'newspack-blocks' ) }
				</ConfirmDialog>
			) }
		</>
	);
};

export default ReorderModal;
