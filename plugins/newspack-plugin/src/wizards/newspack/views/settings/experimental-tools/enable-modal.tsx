/**
 * Confirmation modal for enabling an experimental tool.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Card, Button, Modal } from '../../../../../../packages/components/src';
import type { Tool } from './types';

export default function EnableModal( {
	tool,
	disabled,
	onConfirm,
	onClose,
}: {
	tool: Tool;
	disabled?: boolean;
	onConfirm: () => void;
	onClose: () => void;
} ) {
	return (
		<Modal
			/* translators: %s: tool name. */
			title={ sprintf( __( 'Enable %s?', 'newspack-plugin' ), tool.label ) }
			onRequestClose={ onClose }
		>
			{ tool.disclosure ? (
				<p>{ tool.disclosure }</p>
			) : (
				<p>{ __( 'This tool is in active development. Your experience using it directly shapes what it becomes.', 'newspack-plugin' ) }</p>
			) }
			<Card buttonsCard noBorder className="justify-end">
				<Button variant="secondary" onClick={ onClose } disabled={ disabled }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" onClick={ onConfirm } disabled={ disabled }>
					{ __( 'Enable', 'newspack-plugin' ) }
				</Button>
			</Card>
		</Modal>
	);
}
