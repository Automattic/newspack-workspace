/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — remove one or more people from a group.
 *
 * Removing frees the seat and the person loses the access the group was giving
 * them; it never touches any individual subscription they hold of their own.
 *
 * The server is the authority on who may be removed: the owner can never be, and
 * a manager may not remove a peer manager (Group_Subscription::can_actor_remove_member()).
 * The screen only offers what is permitted; a refusal still surfaces here.
 */

/**
 * WordPress dependencies.
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import ConfirmFlow from './ConfirmFlow';
import { GROUP_LABEL } from '../labels';

export default function RemoveMemberFlow( { members, actions, onClose, onDone } ) {
	const list = members || [];
	const count = list.length;
	const name = 1 === count ? list[ 0 ].name || __( 'this member', 'newspack-plugin' ) : null;
	const groupLabel = GROUP_LABEL.toLowerCase();

	const remove = async () => {
		await actions.removeMembers( list.map( member => member.id ) );
		onDone(
			1 === count
				? sprintf( __( '%1$s removed from the %2$s.', 'newspack-plugin' ), name, groupLabel )
				: sprintf(
						_n( '%1$d member removed from the %2$s.', '%1$d members removed from the %2$s.', count, 'newspack-plugin' ),
						count,
						groupLabel
				  )
		);
	};

	return (
		<ConfirmFlow
			title={ 1 === count ? __( 'Remove member', 'newspack-plugin' ) : __( 'Remove members', 'newspack-plugin' ) }
			confirmLabel={ 1 === count ? __( 'Remove member', 'newspack-plugin' ) : __( 'Remove members', 'newspack-plugin' ) }
			isDestructive
			onCancel={ onClose }
			onConfirm={ remove }
		>
			{ 1 === count
				? createInterpolateElement(
						// The member's name is interpolated as a React token, never into the
						// format string: a display name containing "<" or ">" would otherwise
						// be parsed as markup by createInterpolateElement and crash the modal.
						sprintf(
							__(
								'Are you sure you want to remove <name/> from this %s? This frees up a seat and the member loses access.',
								'newspack-plugin'
							),
							groupLabel
						),
						{ name: <strong>{ name }</strong> }
				  )
				: sprintf(
						_n(
							'Are you sure you want to remove %1$d member from this %2$s? This frees up a seat and they lose access.',
							'Are you sure you want to remove %1$d members from this %2$s? This frees up their seats and they lose access.',
							count,
							'newspack-plugin'
						),
						count,
						groupLabel
				  ) }
		</ConfirmFlow>
	);
}
