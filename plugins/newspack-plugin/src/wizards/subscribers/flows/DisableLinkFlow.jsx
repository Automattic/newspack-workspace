/**
 * Flow — disable the group's shareable invite link.
 *
 * Links are stored per manager, and the caller is placed in one slot by
 * Group_Subscription_API::resolve_link_manager_id — their own if they manage this
 * group, otherwise the owner's. That is the slot the screen reads, so this removes
 * the link the screen shows and every copy of it stops working. A link in any other
 * manager's slot survives, and the screen does not surface those either (see
 * NPPD-2120). The confirm copy is scoped to the link this acts on rather than
 * promising the group has none left.
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
				'This disables the invite link shown above, including any copy already sent out. Nobody can join through it. A link created by another manager is not affected. You can create a new link at any time.',
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
