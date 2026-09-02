/**
 * Flow — regenerate the group's shareable invite link.
 *
 * Links are stored per manager, and the caller is placed in one slot by
 * Group_Subscription_API::resolve_link_manager_id — their own if they manage this
 * group, otherwise the owner's. Regenerating replaces the link in that slot, which
 * is the one the screen shows. Every copy of it stops working immediately, which the
 * confirm copy says plainly rather than surprising the admin. A link in any other
 * manager's slot is untouched.
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
		// `clipboard` is absent in an insecure context and in older browsers, where
		// optional chaining would resolve to undefined and let the await succeed,
		// reporting a copy that never happened — so test for the method first.
		let copied = false;
		try {
			if ( link?.url && window.navigator?.clipboard?.writeText ) {
				await window.navigator.clipboard.writeText( link.url );
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
				"This replaces the invite link shown above, including any copy already sent out. That link stops working, and you'll get the new one on your clipboard to share. A link created by another manager is not affected.",
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
