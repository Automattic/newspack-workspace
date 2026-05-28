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
import { PREMIUM_NEWSLETTERS_WIZARD_SLUG } from './consts';

type PremiumNewslettersConfig = {
	auto_signup: boolean;
};

const AdvancedSettings = ( { closeModal, showModal }: { closeModal: () => void; showModal: boolean } ) => {
	const wizardData = useWizardData( PREMIUM_NEWSLETTERS_WIZARD_SLUG ) as WizardData;
	const initialConfig = { ...( ( wizardData?.config as PremiumNewslettersConfig ) || { auto_signup: true } ) };
	const { wizardApiFetch, isFetching, resetError, setError } = useWizardApiFetch( PREMIUM_NEWSLETTERS_WIZARD_SLUG );
	const { addNotice, resetNotices, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ config, setConfig ] = useState< PremiumNewslettersConfig >( initialConfig );

	useEffect( () => {
		if ( showModal ) {
			setConfig( initialConfig );
		}
	}, [ showModal ] );

	const updateConfig = useRef< ( _config: PremiumNewslettersConfig ) => void >();
	const handleUpdateConfig = ( _config: PremiumNewslettersConfig ) => {
		if ( isFetching ) {
			return;
		}
		resetError();
		resetNotices();
		wizardApiFetch< Gate >(
			{
				path: `/newspack/v1/wizard/${ PREMIUM_NEWSLETTERS_WIZARD_SLUG }/config`,
				method: 'POST',
				data: {
					config: _config,
				},
			},
			{
				onSuccess: () => {
					setConfig( _config );
					updateWizardSettings( {
						slug: PREMIUM_NEWSLETTERS_WIZARD_SLUG,
						path: [ 'config' ],
						value: _config,
					} );
					addNotice( {
						message: __( 'Settings updated.', 'newspack-plugin' ),
						type: 'success',
						id: 'premium-newsletters-advanced-settings-updated',
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
						label={ __( 'Auto signup', 'newspack-plugin' ) }
						help={ __( 'Automatically sign up users when they meet access requirements for premium newsletters.', 'newspack-plugin' ) }
						checked={ config?.auto_signup }
						onChange={ value => setConfig( { ...config, auto_signup: value } ) }
					/>
				</VStack>
				<HStack justify="end">
					<Button variant="tertiary" disabled={ isFetching } onClick={ closeModal }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						disabled={ isFetching || JSON.stringify( wizardData?.config || {} ) === JSON.stringify( config ) }
						loading={ isFetching }
						onClick={ () => updateConfig.current?.( config ) }
					>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</Modal>
		)
	);
};

export default AdvancedSettings;
