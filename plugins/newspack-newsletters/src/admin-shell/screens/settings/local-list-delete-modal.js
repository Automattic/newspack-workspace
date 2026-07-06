import {
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	Button,
	Modal,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function LocalListDeleteModal( { list, onConfirm, onCancel, isBusy } ) {
	if ( ! list ) {
		return null;
	}
	return (
		<Modal
			title={ __( 'Delete local list', 'newspack-newsletters' ) }
			onRequestClose={ isBusy ? () => {} : onCancel }
			shouldCloseOnEsc={ ! isBusy }
			shouldCloseOnClickOutside={ ! isBusy }
			size="small"
			className="newspack-newsletters-local-list-delete-modal"
		>
			<VStack spacing={ 4 }>
				<p>
					{ sprintf(
						// translators: %s is the title of the local list being deleted.
						__( 'Delete the local list "%s"? This cannot be undone.', 'newspack-newsletters' ),
						list.title
					) }
				</p>
				<HStack justify="flex-end" spacing={ 2 }>
					<Button variant="tertiary" onClick={ onCancel } disabled={ isBusy }>
						{ __( 'Cancel', 'newspack-newsletters' ) }
					</Button>
					<Button variant="primary" isDestructive onClick={ onConfirm } isBusy={ isBusy } disabled={ isBusy }>
						{ __( 'Delete list', 'newspack-newsletters' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
}
