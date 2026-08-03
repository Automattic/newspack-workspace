/**
 * Settings Wizard: Connections > Webhooks > Modal > Confirmation
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Card, Button, Modal } from '../../../../../../../../packages/components/src';

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
			<Card buttonsCard noBorder className="justify-end">
				<Button variant="secondary" onClick={ onClose } disabled={ disabled }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" onClick={ onConfirm } disabled={ disabled }>
					{ __( 'Confirm', 'newspack-plugin' ) }
				</Button>
			</Card>
		</Modal>
	);
}

export default Confirmation;
