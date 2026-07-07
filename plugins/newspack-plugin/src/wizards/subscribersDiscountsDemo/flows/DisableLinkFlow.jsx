/**
 * Flow — Disable the group's invite link (admin-only).
 *
 * Destructive confirmation modal mirroring the owner-facing copy: the current
 * link stops working and no new members can join through it until a new link is
 * created.
 */

import { __ } from '@wordpress/i18n';
import ConfirmFlow from './ConfirmFlow';

export default function DisableLinkFlow( { onClose, onComplete } ) {
	const disable = () => {
		onComplete( {
			type: 'success',
			transient: true,
			message: __( 'Invite link disabled.', 'newspack-plugin' ),
			mutate: g => ( { ...g, invites: ( g.invites || [] ).filter( inv => inv.type !== 'link' ) } ),
		} );
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
				"The current link will stop working. Anyone who hasn't joined yet will no longer be able to. You can create a new link at any time.",
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
