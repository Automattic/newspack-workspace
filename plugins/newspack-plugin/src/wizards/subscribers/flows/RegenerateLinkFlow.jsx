/**
 * Flow — regenerate the group's shareable invite link.
 *
 * A group has ONE shareable link, held in the owner's slot, and it is the same URL
 * the owner already distributed from My Account (an admin acts on the owner's link
 * — see Group_Subscription_API::resolve_link_manager_id). Regenerating replaces it
 * in place: every copy the owner previously shared stops working immediately, so
 * the confirm copy says so plainly rather than surprising the admin.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ConfirmFlow from './ConfirmFlow';

export default function RegenerateLinkFlow( { actions, onClose, onDone } ) {
	const regenerate = async () => {
		const link = await actions.generateInviteLink();
		// The fresh URL is the whole point of regenerating, so put it on the
		// clipboard for the admin to paste — the confirm modal has no field to show
		// it in. A clipboard failure (permissions, insecure context) must not read
		// as a failed regeneration, since the link was already minted server-side.
		let copied = false;
		try {
			if ( link?.url ) {
				await window.navigator?.clipboard?.writeText( link.url );
				copied = true;
			}
		} catch ( e ) {
			copied = false;
		}
		onDone(
			copied ? __( 'New invite link created and copied to clipboard.', 'newspack-plugin' ) : __( 'New invite link created.', 'newspack-plugin' )
		);
	};

	return (
		<ConfirmFlow
			title={ __( 'Regenerate invite link', 'newspack-plugin' ) }
			confirmLabel={ __( 'Regenerate link', 'newspack-plugin' ) }
			onCancel={ onClose }
			onConfirm={ regenerate }
		>
			{ __(
				"This replaces the group's shared invite link — including any copy the owner already sent out. Every existing link stops working, and you'll get a new one on your clipboard to share.",
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
