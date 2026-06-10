import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import type { ProfileCollection } from '../types/profile-collection';
import { createInterpolateElement } from '@wordpress/element';

interface DeleteCollectionModalProps {
	item: ProfileCollection;
	onClose: () => void;
	onSuccess: () => void;
}

/**
 * Modal component for confirming deletion of a profile collection.
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

			createInfoNotice(
				__( 'Deleting profile collection…', 'newspack-profiles' ),
				{ type: 'snackbar', id: deletingNoticeId }
			);

			await apiFetch( {
				method: 'DELETE',
				path: '/newspack-profiles/v1/profile-collections',
				data: { slug: item.slug },
			} );

			removeNotice( deletingNoticeId );

			createSuccessNotice(
				__( 'Profile collection deleted.', 'newspack-profiles' ),
				{ type: 'snackbar' }
			);

			onSuccess();
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to delete profile collection', error );

			removeNotice( deletingNoticeId );

			createErrorNotice(
				__(
					'Failed to delete profile collection.',
					'newspack-profiles'
				),
				{ type: 'snackbar' }
			);
		}
	};

	return (
		<div className="newspack-profiles-tailwind">
			<p className="text-sm">
				{ createInterpolateElement(
					sprintf(
						/* translators: %s is the profile collection name. */
						__(
							'Are you sure you want to delete the profile collection <strong>%s</strong>? This action cannot be undone.',
							'newspack-profiles'
						),
						item.name
					),
					{
						strong: (
							<strong className="inline bg-red-100 px-1 py-0.5 rounded" />
						),
					}
				) }
			</p>
			<div className="flex justify-end gap-2 mt-4">
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
			</div>
		</div>
	);
};
