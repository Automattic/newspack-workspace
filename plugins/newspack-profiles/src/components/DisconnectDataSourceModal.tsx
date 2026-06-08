import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import type { ProfileCollection } from '../types/profile-collection';
import { createInterpolateElement } from '@wordpress/element';

interface DisconnectDataSourceModalProps {
	item: ProfileCollection;
	onClose: () => void;
	onSuccess: () => void;
}

/**
 * Modal component for confirming disconnection of a data source from a profile collection.
 *
 * @param {DisconnectDataSourceModalProps} props - Component props.
 *
 * @return JSX.Element The DisconnectDataSourceModal component.
 */
export const DisconnectDataSourceModal = ( {
	item,
	onClose,
	onSuccess,
}: DisconnectDataSourceModalProps ) => {
	const {
		createInfoNotice,
		createSuccessNotice,
		createErrorNotice,
		removeNotice,
	} = useDispatch( noticesStore );

	const handleConfirm = async () => {
		const disconnectingNoticeId = 'disconnecting-remote-data-source';

		try {
			onClose();

			createInfoNotice(
				__( 'Disconnecting data source…', 'newspack-profiles' ),
				{ type: 'snackbar', id: disconnectingNoticeId }
			);

			await apiFetch( {
				method: 'PUT',
				path: '/newspack-profiles/v1/profile-collections/disconnect-remote-source',
				data: { slug: item.slug },
			} );

			removeNotice( disconnectingNoticeId );

			createSuccessNotice(
				__( 'Importing data source…', 'newspack-profiles' ),
				{ type: 'snackbar' }
			);

			onSuccess();
		} catch ( error ) {
			// eslint-disable-next-line no-console
			console.error( 'Failed to disconnect profile collection', error );

			removeNotice( disconnectingNoticeId );

			createErrorNotice(
				__(
					'Failed to disconnect profile collection.',
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
							'Are you sure you want to disconnect the data source for <strong>%s</strong>? The profile collection will still exist, but will no longer sync with the remote source.',
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
					{ __( 'Disconnect', 'newspack-profiles' ) }
				</Button>
			</div>
		</div>
	);
};
