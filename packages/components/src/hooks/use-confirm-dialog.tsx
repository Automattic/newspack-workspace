/**
 * WordPress dependencies.
 */
import { useCallback, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import ConfirmDialog from '../confirm-dialog';

type UseConfirmDialogOptions = {
	when?: boolean;
	message: React.ReactNode;
	title?: string;
	confirmButtonText?: string;
	cancelButtonText?: string;
	isDestructive?: boolean;
	hideTitle?: boolean;
	size?: 'small' | 'medium' | 'large' | 'x-large' | 'full';
	className?: string;
};

type UseConfirmDialogResult = {
	confirmDialog: React.ReactElement;
	requestConfirm: ( callback: () => void ) => void;
};

/**
 * A hook that encapsulates the ConfirmDialog component and provides a
 * `requestConfirm` function for imperative use.
 *
 * Calling `requestConfirm( callback )` will show a confirmation dialog.
 * If the user confirms, `callback` is invoked. If the user cancels, it is not.
 *
 * When `when` is explicitly `false`, `requestConfirm( callback )` calls
 * `callback` immediately without showing the dialog. This is useful for
 * guarding actions that are only destructive when there are unsaved changes
 * (e.g. `when={ isDirty }`). When `when` is omitted or `true`, the dialog
 * is always shown.
 *
 * The `confirmDialog` element must be rendered somewhere in the component's
 * JSX for the dialog to appear.
 */
function useConfirmDialog( options: UseConfirmDialogOptions ): UseConfirmDialogResult {
	const { message, when, ...dialogProps } = options;
	const [ pendingAction, setPendingAction ] = useState< ( () => void ) | null >( null );

	// Keep a ref so `requestConfirm` can always read the latest `when` value
	// without needing to be recreated on every render.
	const whenRef = useRef( when );
	whenRef.current = when;

	const requestConfirm = useCallback( ( callback: () => void ) => {
		if ( whenRef.current !== false ) {
			// Store as a thunk to opt out of React's functional-update interpretation.
			setPendingAction( () => callback );
		} else {
			callback();
		}
	}, [] );

	const confirmDialog = (
		<ConfirmDialog
			{ ...dialogProps }
			when={ when }
			isOpen={ !! pendingAction }
			onConfirm={ () => {
				pendingAction?.();
				setPendingAction( null );
			} }
			onCancel={ () => setPendingAction( null ) }
		>
			{ message }
		</ConfirmDialog>
	);

	return { confirmDialog, requestConfirm };
}

export default useConfirmDialog;
