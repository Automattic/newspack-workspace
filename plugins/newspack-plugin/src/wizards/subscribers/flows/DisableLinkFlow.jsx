/**
 * Flow — disable the group's shareable invite link.
 *
 * The link belongs to the subscription rather than to whoever minted it, so a group
 * has one link and this removes it. Every copy already shared stops working, no
 * matter which manager sent it out, and a group still on the older per-manager shape
 * has those keys cleared in the same write.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ConfirmFlow from './ConfirmFlow';

export default function DisableLinkFlow( { actions, onClose, onDone } ) {
	const disable = async () => {
		await actions.disableInviteLink();
		onDone( __( 'Invite link disabled.', 'newspack-plugin' ) );
	};

	return (
		<ConfirmFlow
			title={ __( 'Disable invite link', 'newspack-plugin' ) }
			confirmLabel={ __( 'Disable link', 'newspack-plugin' ) }
			isDestructive
			onCancel={ onClose }
			onConfirm={ disable }
		>
			{ __(
				'This disables the invite link shown above, including any copy already sent out. Nobody can join through it, whoever shared it. You can create a new link at any time.',
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
