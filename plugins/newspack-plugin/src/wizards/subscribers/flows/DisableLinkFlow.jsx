/**
 * Flow — disable the group's shareable invite link.
 *
 * Links are stored per manager, and an admin acts on the owner's slot (see
 * Group_Subscription_API::resolve_link_manager_id). So this removes the owner's
 * link: the same URL the owner distributed from My Account, and the one this
 * screen shows. Every copy of it stops working. A link another manager minted
 * lives in their own slot and survives; the screen does not surface those either
 * (see NPPD-2120). The confirm copy is scoped to the link this acts on rather than
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
				"This disables the owner's invite link, including any copy already sent out. Nobody can join through it. A link created by another manager is not affected. You can create a new link at any time.",
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
