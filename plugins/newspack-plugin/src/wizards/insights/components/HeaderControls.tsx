/**
 * HeaderControls
 *
 * The Insights global-controls block rendered above the tabs: the date-range
 * picker + comparison toggle (editing a draft), an Apply / Cancel action row
 * shown only when the draft differs from the applied (viewed) state, and the
 * confirmation modal for expensive changes. All state lives in
 * usePendingControls — this component is the presentation of that hook.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { closeSmall } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import DateRangePicker from './DateRangePicker';
import ComparisonToggle from './ComparisonToggle';
import ConfirmModal from './ConfirmModal';
import type { UsePendingControlsReturn } from '../state/usePendingControls';

export interface HeaderControlsProps {
	controls: UsePendingControlsReturn;
}

const HeaderControls = ( { controls }: HeaderControlsProps ) => {
	const { draftRange, draftCompare, setPreset, setCustom, setCompare, isDirty, confirmOpen, apply, cancel, confirmApply } = controls;

	return (
		<div className="newspack-insights__header-controls">
			<DateRangePicker range={ draftRange } onPresetChange={ setPreset } onCustomChange={ setCustom } />
			<ComparisonToggle enabled={ draftCompare } onChange={ setCompare } />
			{ isDirty && ! confirmOpen && (
				<div className="newspack-insights__header-controls-actions">
					<Button variant="secondary" size={ 'small' } onClick={ apply }>
						{ __( 'Apply', 'newspack-plugin' ) }
					</Button>
					<Button variant="tertiary" size={ 'small' } onClick={ cancel } icon={ closeSmall } label={ __( 'Cancel', 'newspack-plugin' ) } />
				</div>
			) }
			{ confirmOpen && <ConfirmModal onContinue={ confirmApply } onCancel={ cancel } /> }
		</div>
	);
};

export default HeaderControls;
