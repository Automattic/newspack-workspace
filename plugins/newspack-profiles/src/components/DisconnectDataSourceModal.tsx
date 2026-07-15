// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { Button, __experimentalHStack as HStack } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import apiFetch from '@wordpress/api-fetch';
import type { ProfileCollection } from '../types/profile-collection';
import { createInterpolateElement } from '@wordpress/element';
import './modals.scss';

interface DisconnectDataSourceModalProps {
	item: ProfileCollection;
	onClose: () => void;
	onSuccess: () => void;
}

/**
 * Modal component for confirming disconnection of a data source from a profile.
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
			console.error( 'Failed to disconnect profile', error );

			removeNotice( disconnectingNoticeId );

			createErrorNotice(
				__( 'Failed to disconnect profile.', 'newspack-profiles' ),
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
							'Are you sure you want to disconnect the data source for <strong>%s</strong>? The profile will still exist, but will no longer sync with the remote source.',
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
					{ __( 'Disconnect', 'newspack-profiles' ) }
				</Button>
			</HStack>
		</>
	);
};
