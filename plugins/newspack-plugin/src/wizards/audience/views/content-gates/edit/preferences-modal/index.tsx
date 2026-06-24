/**
 * Content Gate preferences modal.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Modal,
	Button,
	ToggleControl,
	RadioControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useState } from '@wordpress/element';

type PreferencesModalProps = {
	slug: string;
	presaveChecksEnabled: boolean;
	defaultGateStatus: GateStatus;
	onClose: () => void;
	onSaved: ( prefs: { presaveChecksEnabled: boolean; defaultGateStatus: GateStatus } ) => void;
};

const PreferencesModal = ( { slug, presaveChecksEnabled, defaultGateStatus, onClose, onSaved }: PreferencesModalProps ) => {
	const initialStatus: GateStatus = defaultGateStatus === 'publish' ? 'publish' : 'draft';
	const [ checksEnabled, setChecksEnabled ] = useState< boolean >( presaveChecksEnabled );
	const [ status, setStatus ] = useState< GateStatus >( initialStatus );
	const [ isSaving, setIsSaving ] = useState< boolean >( false );

	const isDirty = checksEnabled !== presaveChecksEnabled || status !== initialStatus;

	const handleSave = () => {
		setIsSaving( true );
		apiFetch( {
			path: `/newspack/v1/wizard/${ slug }/preferences`,
			method: 'POST',
			data: { presave_checks_enabled: checksEnabled, default_gate_status: status },
		} )
			.then( () => {
				onSaved( { presaveChecksEnabled: checksEnabled, defaultGateStatus: status } );
				onClose();
			} )
			.finally( () => setIsSaving( false ) );
	};

	return (
		<Modal title={ __( 'Preferences', 'newspack-plugin' ) } size="medium" onRequestClose={ isSaving ? () => {} : onClose }>
			<VStack spacing={ 6 }>
				<RadioControl
					label={ __( 'Default status for new gates', 'newspack-plugin' ) }
					help={ __(
						'Active gates start restricting content as soon as they’re saved. Inactive gates are saved without restricting anything until you activate them.',
						'newspack-plugin'
					) }
					selected={ status }
					options={ [
						{ label: __( 'Active', 'newspack-plugin' ), value: 'publish' },
						{ label: __( 'Inactive', 'newspack-plugin' ), value: 'draft' },
					] }
					onChange={ value => setStatus( value as GateStatus ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Enable pre-save checks', 'newspack-plugin' ) }
					help={ __( 'Review settings, such as status and access.', 'newspack-plugin' ) }
					checked={ checksEnabled }
					onChange={ setChecksEnabled }
				/>
				<HStack justify="flex-end" spacing={ 2 }>
					<Button variant="tertiary" onClick={ onClose } disabled={ isSaving }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button variant="primary" onClick={ handleSave } isBusy={ isSaving } disabled={ isSaving || ! isDirty }>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</VStack>
		</Modal>
	);
};

export default PreferencesModal;
