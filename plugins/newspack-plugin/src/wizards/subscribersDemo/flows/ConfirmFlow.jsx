/**
 * Shared confirmation modal for the simple single-action flows (cancel invite,
 * disable link, remove member, make owner, regenerate link, resend invite).
 *
 * Encapsulates the small-modal scaffold — title, body text, and a tertiary
 * Cancel + primary Confirm button pair — so each flow only supplies its copy and
 * the action to run on confirm.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies.
 */
import { Button, Modal } from '../../../../packages/components/src';

export default function ConfirmFlow( { title, children, cancelLabel, confirmLabel, isDestructive = false, busy = false, onCancel, onConfirm } ) {
	return (
		<Modal title={ title } onRequestClose={ onCancel } size="small">
			<VStack spacing={ 4 }>
				<p className="newspack-subscribers-demo__modal-text">{ children }</p>
				<HStack spacing={ 2 } justify="flex-end">
					<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onCancel }>
						{ cancelLabel || __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" size="compact" isDestructive={ isDestructive } isBusy={ busy } disabled={ busy } onClick={ onConfirm }>
						{ confirmLabel }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
