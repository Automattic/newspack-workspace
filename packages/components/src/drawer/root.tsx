/**
 * WordPress dependencies.
 */
import { Modal, Popover, SlotFillProvider } from '@wordpress/components';
import { useMergeRefs } from '@wordpress/compose';
import { Children, forwardRef, isValidElement, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * External dependencies.
 */
import classnames from 'classnames';

/**
 * Internal dependencies.
 */
import useConfirmDialog from '../hooks/use-confirm-dialog';
import { DrawerContext } from './context';
import Body from './body';
import Content from './content';
import Divider from './divider';
import useExitAnimation from './use-exit-animation';
import type { DrawerRootProps, DrawerTitleInfo } from './types';
import './style.scss';

const sizeClassMap = {
	small: 'newspack-drawer--size-small',
	medium: 'newspack-drawer--size-medium',
	large: 'newspack-drawer--size-large',
	'x-large': 'newspack-drawer--size-x-large',
	full: 'newspack-drawer--size-full',
};

const captureOptions = { capture: true };

const Root = forwardRef< HTMLDivElement, DrawerRootProps >( function Root(
	{
		isOpen = false,
		size = 'medium',
		onRequestClose,
		isDirty = false,
		confirmCloseMessage = __( 'You have unsaved changes that will be lost. Discard changes?', 'newspack-plugin' ),
		confirmButtonText = __( 'Discard Changes', 'newspack-plugin' ),
		requestConfirm,
		contentLabel,
		describedBy,
		className,
		style,
		children,
	},
	forwardedRef
) {
	const [ title, setTitle ] = useState< DrawerTitleInfo | null >( null );

	const titleRef = useRef< DrawerTitleInfo | null >( null );
	const titlesRef = useRef< DrawerTitleInfo[] >( [] );
	const registerTitle = useCallback( ( info: DrawerTitleInfo | null, forId?: string ) => {
		if ( info ) {
			const at = titlesRef.current.findIndex( entry => entry.id === info.id );
			if ( at === -1 ) {
				titlesRef.current = [ ...titlesRef.current, info ];
			} else {
				titlesRef.current = titlesRef.current.map( entry => ( entry.id === info.id ? info : entry ) );
			}
		} else if ( forId ) {
			titlesRef.current = titlesRef.current.filter( entry => entry.id !== forId );
		} else {
			titlesRef.current = [];
		}
		const current = titlesRef.current[ titlesRef.current.length - 1 ] ?? null;
		titleRef.current = current;
		setTitle( current );
	}, [] );

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV || ! isOpen ) {
			return;
		}
		if ( ! titleRef.current && ! contentLabel ) {
			// eslint-disable-next-line no-console
			console.warn( 'Drawer: render a Drawer.Title or pass `contentLabel`. Without one the panel has no accessible name.' );
		}
	}, [ isOpen, title, contentLabel ] );

	useEffect( () => {
		if ( 'production' === process.env.NODE_ENV || sizeClassMap[ size ] ) {
			return;
		}
		// eslint-disable-next-line no-console
		console.warn( `Drawer: unknown size "${ size }", falling back to medium. Use one of ${ Object.keys( sizeClassMap ).join( ', ' ) }.` );
	}, [ size ] );

	// No `when`: it installs a history.block guard that fights the wizard's own.
	const {
		confirmDialog,
		requestConfirm: ownRequestConfirm,
		cancelConfirm: ownCancelConfirm,
	} = useConfirmDialog( {
		message: confirmCloseMessage,
		confirmButtonText,
		// Hidden, but still the dialog's accessible name.
		title: __( 'Unsaved changes', 'newspack-plugin' ),
		hideTitle: true,
	} );

	const confirm = requestConfirm || ownRequestConfirm;

	const requestClose = useCallback( () => {
		if ( ! isOpen ) {
			return;
		}
		if ( isDirty ) {
			let prompted = true;
			confirm( () => {
				prompted = false;
				onRequestClose();
			} );
			if ( 'production' !== process.env.NODE_ENV && ! prompted ) {
				// eslint-disable-next-line no-console
				console.warn(
					'Drawer: `requestConfirm` closed the panel without prompting, so `isDirty` is set while its own `when` is not. ' +
						'Unsaved changes are being discarded silently. Drive both from the same state.'
				);
			}
			return;
		}
		onRequestClose();
	}, [ isOpen, isDirty, confirm, onRequestClose ] );

	// Core fills its dismisser ref in a passive effect and siblings call it from
	// theirs, so a closure would go stale by tree order. Layout effects run first.
	const dismissRef = useRef( { isOpen, isDirty, onRequestClose } );
	useLayoutEffect( () => {
		dismissRef.current = { isOpen, isDirty, onRequestClose };
	} );

	// Core calls this as another Modal mounts. A dirty panel cannot answer: its own
	// confirmation would land under that Modal, and a delegated one is that Modal.
	const dismiss = useCallback( () => {
		const current = dismissRef.current;
		if ( current.isOpen && ! current.isDirty ) {
			current.onRequestClose();
		}
	}, [] );

	const onKeyDown = useCallback(
		( event: React.KeyboardEvent ) => {
			// Cancelling a CJK composition raises an Escape of its own.
			if ( event.nativeEvent?.isComposing || 229 === event.keyCode ) {
				return;
			}
			if ( ( 'Escape' !== event.key && 'Escape' !== event.code ) || event.defaultPrevented ) {
				return;
			}
			event.preventDefault();
			requestClose();
		},
		[ requestClose ]
	);

	const [ node, setNode ] = useState< HTMLElement | null >( null );
	const frameRefs = useMergeRefs( [ setNode, forwardedRef ] );
	const { isRendered, isExiting } = useExitAnimation( isOpen, node );

	const openerRef = useRef< Element | null >( null );
	const [ wasOpenForFocus, setWasOpenForFocus ] = useState( false );
	if ( wasOpenForFocus !== isOpen ) {
		setWasOpenForFocus( isOpen );
		if ( isOpen ) {
			// eslint-disable-next-line @wordpress/no-global-active-element
			openerRef.current = typeof document === 'undefined' ? null : document.activeElement;
		} else {
			// During render, so it is gone in the commit that starts the exit rather
			// than holding focus while the panel goes inert. Only ours to withdraw.
			ownCancelConfirm();
		}
	}

	const movedFocusOut = useRef( false );
	useEffect( () => {
		const frame = node?.querySelector< HTMLElement >( '.components-modal__frame' );
		if ( ! frame ) {
			if ( node && 'production' !== process.env.NODE_ENV ) {
				// eslint-disable-next-line no-console
				console.warn(
					'Drawer: no .components-modal__frame inside the overlay. Focus return and `inert` are both off. ' +
						'Check this against the installed @wordpress/components.'
				);
			}
			return;
		}
		if ( isExiting ) {
			// Focus goes home before `inert` blurs it, unless parked elsewhere:
			// reclaiming it from a sibling frame would take it from an open modal.
			const active = frame.ownerDocument.activeElement;
			if ( frame.contains( active ) || ! active || active === frame.ownerDocument.body ) {
				movedFocusOut.current = true;
				const opener = openerRef.current as HTMLElement | null;
				if ( opener?.isConnected ) {
					opener.focus?.();
				}
			}
			frame.toggleAttribute( 'inert', true );
			return;
		}
		frame.toggleAttribute( 'inert', false );
		// Reopened mid-exit: the same Modal instance, so focus-on-mount never re-runs.
		const restore = movedFocusOut.current;
		movedFocusOut.current = false;
		if ( restore && frame.isConnected && ! frame.contains( frame.ownerDocument.activeElement ) ) {
			frame.focus( { preventScroll: true } );
		}
	}, [ node, isExiting ] );

	// Reduced motion has no exit to hand focus back during.
	useLayoutEffect( () => {
		if ( ! isRendered ) {
			return;
		}
		return () => {
			const opener = openerRef.current as HTMLElement | null;
			if ( ! opener?.isConnected ) {
				return;
			}
			const active = opener.ownerDocument.activeElement;
			if ( active && active !== opener.ownerDocument.body && active.isConnected ) {
				return;
			}
			opener.focus?.();
		};
	}, [ isRendered ] );

	const pressedOverlay = useRef( false );
	// On commit, not after paint: a release in the gap would close a drawer that
	// has just turned dirty.
	const requestCloseRef = useRef( requestClose );
	useLayoutEffect( () => {
		requestCloseRef.current = requestClose;
	}, [ requestClose ] );
	useEffect( () => {
		if ( ! node ) {
			return;
		}
		const { ownerDocument } = node;
		pressedOverlay.current = false;
		const onPointerDown = ( event: PointerEvent ) => {
			pressedOverlay.current = 0 === event.button && event.target === node;
			if ( pressedOverlay.current ) {
				// Touch and pen capture implicitly, reporting the release on the overlay.
				if ( 'function' === typeof node.hasPointerCapture && node.hasPointerCapture( event.pointerId ) ) {
					node.releasePointerCapture( event.pointerId );
				}
				event.preventDefault();
			}
		};
		const onPointerUp = ( event: PointerEvent ) => {
			const closes = pressedOverlay.current && 0 === event.button && event.target === node;
			pressedOverlay.current = false;
			if ( closes ) {
				requestCloseRef.current();
			}
		};
		const onPointerCancel = () => {
			pressedOverlay.current = false;
		};
		node.addEventListener( 'pointerdown', onPointerDown );
		ownerDocument.addEventListener( 'pointerup', onPointerUp, captureOptions );
		ownerDocument.addEventListener( 'pointercancel', onPointerCancel, captureOptions );
		return () => {
			pressedOverlay.current = false;
			node.removeEventListener( 'pointerdown', onPointerDown );
			ownerDocument.removeEventListener( 'pointerup', onPointerUp, captureOptions );
			ownerDocument.removeEventListener( 'pointercancel', onPointerCancel, captureOptions );
		};
	}, [ node ] );

	const contextValue = useMemo( () => ( { requestClose, title, setTitle: registerTitle } ), [ requestClose, title, registerTitle ] );

	if ( ! isRendered ) {
		return null;
	}

	const grouped: React.ReactNode[] = [];
	let run: React.ReactNode[] = [];
	let bodyIndex = 0;
	const flushRun = () => {
		if ( run.length ) {
			grouped.push( <Body key={ `body-${ bodyIndex++ }` }>{ run }</Body> );
			run = [];
		}
	};
	Children.toArray( children ).forEach( child => {
		if ( isValidElement( child ) && ( child.type === Content || child.type === Divider ) ) {
			run.push( child );
		} else {
			flushRun();
			grouped.push( child );
		}
	} );
	flushRun();

	return (
		<DrawerContext.Provider value={ contextValue }>
			<Modal
				__experimentalHideHeader
				ref={ frameRefs }
				className={ classnames( 'newspack-drawer', sizeClassMap[ size ] || sizeClassMap.medium, isExiting && 'is-exiting', className ) }
				overlayClassName={ classnames( 'newspack-drawer__overlay', isExiting && 'is-exiting' ) }
				style={ style }
				contentLabel={ title ? undefined : contentLabel }
				aria={ { labelledby: title ? title.id : undefined, describedby: describedBy } }
				shouldCloseOnEsc={ false }
				shouldCloseOnClickOutside={ false }
				onRequestClose={ dismiss }
				onKeyDown={ onKeyDown }
			>
				{ /* Without a slot inside, a popover lands in an aria-hidden container. */ }
				<SlotFillProvider>
					{ grouped }
					{ ! requestConfirm && confirmDialog }
					<Popover.Slot />
				</SlotFillProvider>
			</Modal>
		</DrawerContext.Provider>
	);
} );

export default Root;
