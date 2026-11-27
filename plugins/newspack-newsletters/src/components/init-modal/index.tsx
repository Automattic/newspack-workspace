/**
 * Newsletter Modal
 */

/**
 * WordPress dependencies
 */
import { Modal } from '@wordpress/components';

/**
 * External dependencies
 */
import type { ComponentProps, ComponentType } from 'react';

/**
 * Internal dependencies
 */
import LayoutPicker from './screens/layout-picker';
import APIKeys from './screens/api-keys';
import './style.scss';

// `Modal`'s types mark `onRequestClose` required, but this init modal is
// non-dismissible (every close affordance is disabled), so it renders without
// one. Widening the props to `Partial` keeps the same component at runtime.
const NonDismissibleModal = Modal as ComponentType< Partial< ComponentProps< typeof Modal > > >;

interface InitModalProps {
	shouldDisplaySettings?: boolean;
	onSetupStatus: ( status: boolean ) => void;
}

export default ( { shouldDisplaySettings, onSetupStatus }: InitModalProps ) => {
	return (
		<NonDismissibleModal
			className="newspack-newsletters-modal__frame"
			isDismissible={ false }
			overlayClassName="newspack-newsletters-modal__screen-overlay"
			shouldCloseOnClickOutside={ false }
			shouldCloseOnEsc={ false }
			size="fill"
		>
			{ shouldDisplaySettings ? <APIKeys onSetupStatus={ onSetupStatus } /> : <LayoutPicker /> }
		</NonDismissibleModal>
	);
};
