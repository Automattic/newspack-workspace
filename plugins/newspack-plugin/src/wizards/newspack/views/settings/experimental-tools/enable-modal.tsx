/**
 * Confirmation modal for enabling an experimental tool.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { Notice, __experimentalHStack as HStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Button, Modal } from '../../../../../../packages/components/src';
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
			{ tool.location_hint && (
				<Notice status="info" isDismissible={ false } className="experimental-tools__location-hint">
					{ tool.location_hint }
				</Notice>
			) }
			<HStack justify="flex-end" spacing={ 4 } wrap className="newspack-modal__footer">
				<Button variant="secondary" onClick={ onClose } disabled={ disabled }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" onClick={ onConfirm } disabled={ disabled }>
					{ __( 'Enable', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</Modal>
	);
}
