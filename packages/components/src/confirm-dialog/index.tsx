/**
 * Modal
 */

/**
 * WordPress dependencies.
 */
import { __experimentalConfirmDialog as BaseComponent } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { forwardRef, useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Router from '../proxied-imports/router';
const { useHistory } = Router;

/**
 * External dependencies.
 */
import classnames from 'classnames';

/*
 * See both https://wordpress.github.io/gutenberg/?path=/docs/components-confirmdialog--docs and
 * https://wordpress.github.io/gutenberg/?path=/docs/components-modal--docs for all supported props.
 */
type ConfirmDialogProps = {
	className?: string;
	size?: 'small' | 'medium' | 'large' | 'x-large' | 'full';
	hideTitle?: boolean;
	title?: string;
	isDestructive?: boolean;
	isOpen?: boolean;
	onConfirm?: () => void;
	onCancel?: () => void;
	cancelButtonText?: string;
	confirmButtonText?: string;
	children?: React.ReactNode;
	when?: boolean;
};

const sizeClassMap = {
	small: 'newspack-modal--size-small',
	medium: 'newspack-modal--size-medium',
	large: 'newspack-modal--size-large',
	'x-large': 'newspack-modal--size-x-large',
	full: 'newspack-modal--size-full',
};

const noOp = () => {};

function ConfirmDialog(
	{
		className,
		size = 'small',
		hideTitle,
		isDestructive,
		onConfirm = noOp,
		onCancel = noOp,
		when = false,
		isOpen = false,
		children,
		...otherProps
	}: ConfirmDialogProps,
	ref: React.Ref< HTMLDivElement >
) {
	const [ showDialog, setShowDialog ] = useState( isOpen );
	const history = useHistory();
	const pendingNavigation = useRef< ( () => void ) | null >( null );
	// While true, the blocker lets navigation through — used so the confirmed
	// ("Discard changes") navigation isn't caught by the still-active blocker.
	const bypassBlock = useRef( false );

	const handleOnConfirm = useCallback( () => {
		setShowDialog( false );
		pendingNavigation.current?.();
		pendingNavigation.current = null;
		onConfirm();
	}, [ onConfirm, pendingNavigation ] );

	const handleOnCancel = useCallback( () => {
		setShowDialog( false );
		const hadPendingNavigation = !! pendingNavigation.current;
		pendingNavigation.current = null;
		// A POP may have moved the URL to the target before it was blocked; put
		// it back so the address bar matches the page the user chose to stay on.
		// Only when this instance blocked it: v5 keeps one prompt slot, so an
		// unprompted replace is caught by whichever dialog did install a blocker.
		if ( hadPendingNavigation && history ) {
			bypassBlock.current = true;
			try {
				history.replace( history.location );
			} finally {
				bypassBlock.current = false;
			}
		}
		onCancel();
	}, [ onCancel, history ] );

	// Block navigation when there are unsaved changes.
	useEffect( () => {
		if ( ! when || ! history ) {
			return;
		}
		const unblock = history.block( ( location: string, action: string ) => {
			// Let our own confirmed navigation through instead of re-blocking it.
			if ( bypassBlock.current ) {
				return undefined;
			}
			pendingNavigation.current = () => {
				bypassBlock.current = true;
				try {
					// A browser/anchor POP (e.g. a breadcrumb or back link) moves the URL
					// to the target before navigation is blocked, and v5 leaves the hash
					// there, so pushing the target would be a no-op. Re-sync to the
					// still-current location first so the real navigation actually fires.
					history.replace( history.location );
					if ( action === 'REPLACE' ) {
						history.replace( location );
					} else {
						history.push( location );
					}
				} finally {
					bypassBlock.current = false;
				}
			};
			setShowDialog( true );
			return false;
		} );
		return unblock;
	}, [ when, history ] );

	// During render: a commit later the dialog would still hold focus while the
	// caller unmounted. The blocker above raises it without touching `isOpen`.
	const [ wasOpen, setWasOpen ] = useState( isOpen );
	if ( wasOpen !== isOpen ) {
		setWasOpen( isOpen );
		setShowDialog( isOpen );
	}

	// A withdrawn prompt releases what the blocker held, as confirm and cancel do.
	// After the commit, so a discarded render cannot drop a live one.
	useEffect( () => {
		if ( ! isOpen ) {
			pendingNavigation.current = null;
		}
	}, [ isOpen ] );

	if ( ! showDialog ) {
		return null;
	}

	const classes = classnames(
		'newspack-modal',
		sizeClassMap[ size ],
		hideTitle && 'newspack-modal--hide-title', // Note: also hides the X close button.
		isDestructive && 'newspack-modal--destructive',
		className
	);

	return (
		<BaseComponent
			className={ classes }
			{ ...otherProps }
			ref={ ref }
			onConfirm={ handleOnConfirm }
			onCancel={ handleOnCancel }
			__experimentalHideHeader={ false }
		>
			{ children }
		</BaseComponent>
	);
}
export default forwardRef( ConfirmDialog );
