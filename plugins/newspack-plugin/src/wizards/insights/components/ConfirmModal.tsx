/**
 * ConfirmModal
 *
 * Confirmation dialog shown before applying an expensive Insights control
 * change (a custom date range, or a comparison-mode toggle). Warns that the
 * data may be slow to load. Assembled from @wordpress/components Modal + Button,
 * following the FeedbackModal convention — a horizontal, right-aligned action
 * row with the dismiss button first.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Modal, Button } from '@wordpress/components';

export interface ConfirmModalProps {
	onContinue: () => void;
	onCancel: () => void;
}

// Wire the warning text to the dialog's accessible description (WP Modal
// forwards `aria.describedby` onto the dialog) so screen readers announce the
// message, not just the title — matching the FeedbackModal convention.
const MESSAGE_ID = 'newspack-insights-confirm-modal-message';

const ConfirmModal = ( { onContinue, onCancel }: ConfirmModalProps ) => (
	<Modal
		title={ __( 'Load new data?', 'newspack-plugin' ) }
		onRequestClose={ onCancel }
		className="newspack-insights__confirm-modal"
		aria={ { describedby: MESSAGE_ID } }
	>
		<p id={ MESSAGE_ID } className="newspack-insights__confirm-modal-message">
			{ __( 'The data for these settings may take a while to load. Continue?', 'newspack-plugin' ) }
		</p>
		<div className="newspack-insights__confirm-modal-actions">
			<Button variant="tertiary" onClick={ onCancel }>
				{ __( 'Cancel', 'newspack-plugin' ) }
			</Button>
			<Button variant="primary" onClick={ onContinue }>
				{ __( 'Continue', 'newspack-plugin' ) }
			</Button>
		</div>
	</Modal>
);

export default ConfirmModal;
