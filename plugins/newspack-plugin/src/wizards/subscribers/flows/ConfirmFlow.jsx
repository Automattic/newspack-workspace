/**
 * Shared confirmation modal for the single-action group flows (resend invite,
 * cancel invite, regenerate link, disable link, remove member).
 *
 * Encapsulates the small-modal scaffold — title, body copy, and a tertiary Cancel
 * + primary Confirm pair — so each flow supplies only its copy and the write to
 * run. It also owns the two things every write shares: the busy state while the
 * request is in flight, and rendering the server's own error message when the
 * request is refused, leaving the modal open so the publisher can read it and
 * decide what to do. See `data/use-group.js` for why errors are surfaced verbatim.
 */

/**
 * WordPress dependencies.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal, Notice } from '../../../../packages/components/src';

/**
 * @param {Object}   props                 Component props.
 * @param {string}   props.title           Modal title.
 * @param {*}        props.children        Body copy.
 * @param {string}   [props.cancelLabel]   Label for the dismiss button.
 * @param {string}   props.confirmLabel    Label for the confirm button.
 * @param {boolean}  [props.isDestructive] Render the confirm button destructively.
 * @param {Function} props.onCancel        Close without acting.
 * @param {Function} props.onConfirm       Returns a promise; resolves to close, rejects to show the error.
 */
export default function ConfirmFlow( { title, children, cancelLabel, confirmLabel, isDestructive = false, onCancel, onConfirm } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const confirm = async () => {
		setBusy( true );
		setError( '' );
		try {
			await onConfirm();
		} catch ( e ) {
			// The server's message is written for a publisher; show it as-is.
			setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
			setBusy( false );
		}
	};

	return (
		<Modal title={ title } onRequestClose={ busy ? () => {} : onCancel } size="small">
			<VStack spacing={ 4 }>
				{ error && <Notice isError noticeText={ error } /> }
				<p className="newspack-subscribers__modal-text">{ children }</p>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onCancel }>
						{ cancelLabel || __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" isDestructive={ isDestructive } isBusy={ busy } disabled={ busy } onClick={ confirm }>
						{ confirmLabel }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
