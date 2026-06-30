/**
 * Flow — Regenerate the group's invite link (admin-only).
 *
 * Confirmation modal mirroring the owner-facing copy: the current link stops
 * working and a fresh one is copied to the clipboard. The link is a single
 * persistent entry, so regenerating replaces it in place.
 */

import { __ } from '@wordpress/i18n';
import ConfirmFlow from './ConfirmFlow';

const today = () => new Date().toISOString().slice( 0, 10 );

export default function RegenerateLinkFlow( { group, onClose, onComplete } ) {
	const regenerate = () => {
		try {
			window.navigator?.clipboard?.writeText( `https://example.com/join/${ group.id }` );
		} catch ( e ) {
			// Clipboard unavailable in the prototype — ignore.
		}
		onComplete( {
			type: 'success',
			transient: true,
			message: __( 'New invite link copied to clipboard.', 'newspack-plugin' ),
			mutate: g => ( {
				...g,
				invites: ( g.invites || [] ).map( inv => ( inv.type === 'link' && inv.status === 'active' ? { ...inv, createdAt: today() } : inv ) ),
			} ),
		} );
	};

	return (
		<ConfirmFlow
			title={ __( 'Regenerate invite link', 'newspack-plugin' ) }
			confirmLabel={ __( 'Regenerate link', 'newspack-plugin' ) }
			onCancel={ onClose }
			onConfirm={ regenerate }
		>
			{ __(
				"The current link will stop working. You'll get a new link to share, and anyone who hasn't joined yet will need the new link.",
				'newspack-plugin'
			) }
		</ConfirmFlow>
	);
}
