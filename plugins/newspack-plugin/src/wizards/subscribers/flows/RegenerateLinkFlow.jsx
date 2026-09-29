/**
 * Flow — regenerate the group's shareable invite link.
 *
 * The link belongs to the subscription rather than to whoever minted it, so a group
 * has one link and this replaces it. Every copy already shared stops working
 * immediately, no matter which manager sent it out, which the confirm copy says
 * plainly rather than surprising the admin.
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
				"This replaces the invite link shown above, including any copy already sent out. That link stops working, whoever shared it, and you'll get the new one on your clipboard to share.",
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
