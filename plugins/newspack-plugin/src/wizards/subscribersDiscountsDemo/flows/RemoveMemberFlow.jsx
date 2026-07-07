/* eslint-disable @wordpress/i18n-translator-comments */
/**
 * Flow — Remove member(s) from a group (single row action or bulk).
 *
 * Confirmation modal; removing members frees up their seats.
 */

import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import ConfirmFlow from './ConfirmFlow';
import { GROUP_LABEL_LOWER } from '../labels';

export default function RemoveMemberFlow( { members, onClose, onComplete } ) {
	const list = members || [];
	const count = list.length;
	const ids = new Set( list.map( m => m.subscriberId ) );
	const name = count === 1 ? list[ 0 ].name || __( 'this member', 'newspack-plugin' ) : null;

	const remove = () => {
		onComplete( {
			type: 'success',
			transient: true,
			message:
				count === 1
					? sprintf( __( '%1$s removed from the %2$s.', 'newspack-plugin' ), name, GROUP_LABEL_LOWER )
					: sprintf(
							_n( '%1$d member removed from the %2$s.', '%1$d members removed from the %2$s.', count, 'newspack-plugin' ),
							count,
							GROUP_LABEL_LOWER
					  ),
			mutate: g => ( { ...g, members: ( g.members || [] ).filter( m => ! ids.has( m.subscriberId ) ) } ),
		} );
	};

	return (
		<ConfirmFlow
			title={ count === 1 ? __( 'Remove member', 'newspack-plugin' ) : __( 'Remove members', 'newspack-plugin' ) }
			confirmLabel={ count === 1 ? __( 'Remove member', 'newspack-plugin' ) : __( 'Remove members', 'newspack-plugin' ) }
			isDestructive
			onCancel={ onClose }
			onConfirm={ remove }
		>
			{ count === 1
				? createInterpolateElement(
						sprintf(
							__(
								'Are you sure you want to remove <strong>%1$s</strong> from this %2$s? This frees up a seat and the member loses access.',
								'newspack-plugin'
							),
							name,
							GROUP_LABEL_LOWER
						),
						{ strong: <strong /> }
				  )
				: sprintf(
						_n(
							'Are you sure you want to remove %1$d member from this %2$s? This frees up a seat and they lose access.',
							'Are you sure you want to remove %1$d members from this %2$s? This frees up their seats and they lose access.',
							count,
							'newspack-plugin'
						),
						count,
						GROUP_LABEL_LOWER
				  ) }
		</ConfirmFlow>
	);
}
