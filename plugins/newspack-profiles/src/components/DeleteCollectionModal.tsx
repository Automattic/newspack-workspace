// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { Button, __experimentalHStack as HStack } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import type { ProfileCollection } from '../types/profile-collection';
import { createInterpolateElement } from '@wordpress/element';
import './modals.scss';

interface DeleteCollectionModalProps {
	item: ProfileCollection;
	onClose: () => void;
	onSuccess: () => void;
}

/**
 * Modal component for confirming deletion of a profile.
 *
 * @param {DeleteCollectionModalProps} props - Component props.
 *
 * @return JSX.Element The DeleteCollectionModal component.
 */
export const DeleteCollectionModal = ( {
	item,
	onClose,
	onSuccess,
}: DeleteCollectionModalProps ) => {
	const {
		createInfoNotice,
		createSuccessNotice,
		createErrorNotice,
		removeNotice,
	} = useDispatch( noticesStore );

	const handleConfirm = async () => {
		const deletingNoticeId = 'deleting-profile-collection';

		try {
			onClose();

			createInfoNotice( __( 'Deleting profile…', 'newspack-profiles' ), {
				type: 'snackbar',
				id: deletingNoticeId,
			} );

			await apiFetch( {
				method: 'DELETE',
				path: '/newspack-profiles/v1/profile-collections',
				data: { slug: item.slug },
			} );

			removeNotice( deletingNoticeId );

			createSuccessNotice(
				__( 'Profile deleted.', 'newspack-profiles' ),
				{ type: 'snackbar' }
			);

			onSuccess();
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to delete profile', error );

			removeNotice( deletingNoticeId );

			createErrorNotice(
				__( 'Failed to delete profile.', 'newspack-profiles' ),
				{ type: 'snackbar' }
			);
		}
	};

	return (
		<>
			<p className="newspack-profiles__modal-body">
				{ createInterpolateElement(
					sprintf(
						/* translators: %s is the profile name. */
						__(
							'Are you sure you want to delete the profile <strong>%s</strong>? This action cannot be undone.',
							'newspack-profiles'
						),
						item.name
					),
					{
						strong: <strong />,
					}
				) }
			</p>
			<HStack
				justify="flex-end"
				spacing={ 2 }
				className="newspack-profiles__modal-footer"
			>
				<Button variant="secondary" onClick={ onClose }>
					{ __( 'Cancel', 'newspack-profiles' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					onClick={ handleConfirm }
				>
					{ __( 'Delete', 'newspack-profiles' ) }
				</Button>
			</HStack>
		</>
	);
};
