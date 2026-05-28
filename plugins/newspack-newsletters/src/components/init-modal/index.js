/**
 * Newsletter Modal
 */

/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';

/**
 * Internal dependencies
 */
import LayoutPicker from './screens/layout-picker';
import APIKeys from './screens/api-keys';
import './style.scss';

export default ( { shouldDisplaySettings, onSetupStatus } ) => {
	return (
		<Modal
			className="newspack-newsletters-modal__frame"
			isDismissible={ false }
			overlayClassName="newspack-newsletters-modal__screen-overlay"
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc={ false }
			size="fill"
		>
			{ shouldDisplaySettings ? <APIKeys onSetupStatus={ onSetupStatus } /> : <LayoutPicker /> }
		</Modal>
	);
};
