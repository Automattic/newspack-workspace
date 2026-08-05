/**
 * Settings Wizard: Connections > Webhooks > Modal > Confirmation
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Button, Modal } from '../../../../../../../../packages/components/src';

function Confirmation( {
	disabled,
	onConfirm,
	onClose,
	title,
	description,
}: {
	disabled?: boolean;
	onConfirm?: () => void;
	onClose: () => void;
	title: string;
	description: string;
} ) {
	return (
		<Modal title={ title } onRequestClose={ onClose }>
			<p>{ description }</p>
			<HStack justify="flex-end" spacing={ 4 } wrap className="newspack-modal__footer">
				<Button variant="secondary" onClick={ onClose } disabled={ disabled }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" onClick={ onConfirm } disabled={ disabled }>
					{ __( 'Confirm', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</Modal>
	);
}

export default Confirmation;
