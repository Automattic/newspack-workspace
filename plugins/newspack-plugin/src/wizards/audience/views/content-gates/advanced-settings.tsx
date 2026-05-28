/**
 * Content Gate component.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { ToggleControl, __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Button, Modal } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../hooks/use-wizard-api-fetch';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';

const AdvancedSettings = ( { closeModal, showModal }: { closeModal: () => void; showModal: boolean } ) => {
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as WizardData;
	const initialConfig = { ...( wizardData?.config?.advanced_settings || {} ) };
	const { wizardApiFetch, isFetching, resetError, setError } = useWizardApiFetch( AUDIENCE_CONTENT_GATES_WIZARD_SLUG );
	const { addNotice, resetNotices, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ config, setConfig ] = useState< AdvancedSettingsConfig >( initialConfig );

	useEffect( () => {
		if ( showModal ) {
			setConfig( initialConfig );
		}
	}, [ showModal ] );

	const updateConfig = useRef< ( _config: AdvancedSettingsConfig ) => void >();
	const handleUpdateConfig = ( _config: AdvancedSettingsConfig ) => {
		if ( isFetching ) {
			return;
		}
		resetError();
		resetNotices();
		wizardApiFetch< AdvancedSettingsConfig >(
			{
				path: `/newspack/v1/wizard/${ AUDIENCE_CONTENT_GATES_WIZARD_SLUG }/settings`,
				method: 'POST',
				data: {
					advanced_settings: _config,
				},
			},
			{
				onSuccess: ( data: AdvancedSettingsConfig ) => {
					setConfig( _config );
					updateWizardSettings( {
						slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
						path: [ 'config', 'advanced_settings' ],
						value: data,
					} );
					addNotice( {
						message: __( 'Settings updated.', 'newspack-plugin' ),
						type: 'success',
						id: 'content-gates-advanced-settings-updated',
						actions: [ { label: __( 'Undo', 'newspack-plugin' ), onClick: () => updateConfig.current?.( initialConfig ) } ],
					} );
				},
				onError: ( fetchError: WpFetchError ) => {
					setError( fetchError );
				},
				onFinally: () => {
					closeModal();
				},
			}
		);
	};

	updateConfig.current = handleUpdateConfig;
	return (
		showModal && (
			<Modal onClose={ closeModal } size="medium" title={ __( 'Advanced settings', 'newspack-plugin' ) } onRequestClose={ closeModal }>
				<VStack>
					<ToggleControl
						label={ __( 'Restrict content in feeds', 'newspack-plugin' ) }
						help={ __( 'Truncate restricted content in RSS feeds.', 'newspack-plugin' ) }
						checked={ config?.restrict_feeds }
						onChange={ value => setConfig( { ...config, restrict_feeds: value } ) }
					/>
					<HStack justify="end">
						<Button variant="tertiary" disabled={ isFetching } onClick={ closeModal }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button
							variant="primary"
							disabled={ isFetching || JSON.stringify( wizardData?.config?.advanced_settings || {} ) === JSON.stringify( config ) }
							loading={ isFetching }
							onClick={ () => updateConfig.current?.( config ) }
						>
							{ __( 'Save', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			</Modal>
		)
	);
};

export default AdvancedSettings;
