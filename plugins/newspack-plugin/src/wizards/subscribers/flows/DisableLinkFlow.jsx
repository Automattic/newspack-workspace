/**
 * Flow — disable the group's shareable invite link.
 *
 * A group has ONE shareable link, held in the owner's slot, and it is the same URL
 * the owner already distributed from My Account. Disabling removes it entirely, so
 * every copy the owner previously shared stops working — the confirm copy says so
 * plainly. A new link can be created at any time.
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
				"This disables the group's shared invite link — including any copy the owner already sent out. Every existing link stops working and nobody can join through it. You can create a new link at any time.",
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
