/**
 * Institutions Advanced Settings modal.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { useRef, useState } from '@wordpress/element';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Button, Modal } from '../../../../../../packages/components/src';
import { useWizardData } from '../../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from '../consts';

const institutionalAccessDefaultText = window.newspackAudienceContentGates?.institutional_access_default_text || '';

const InstitutionsAdvancedSettings = ( { closeModal }: { closeModal: () => void } ) => {
	const wizardData = useWizardData( AUDIENCE_CONTENT_GATES_WIZARD_SLUG ) as ContentGatesWizardData;
	const savedText = wizardData?.config?.advanced_settings?.institutional_access_text || '';
	const { wizardApiFetch, isFetching, resetError } = useWizardApiFetch( AUDIENCE_CONTENT_GATES_WIZARD_SLUG );
	const { addNotice, resetNotices, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ text, setText ] = useState( savedText );

	const saveText = useRef< ( value: string ) => void >( undefined );
	// No isFetching guard: Undo runs after the modal has unmounted, when this closure's isFetching is stuck at true.
	const handleSaveText = ( value: string ) => {
		resetError();
		resetNotices();
		wizardApiFetch< AdvancedSettingsConfig >(
			{
				path: `/newspack/v1/wizard/${ AUDIENCE_CONTENT_GATES_WIZARD_SLUG }/settings`,
				method: 'POST',
				data: {
					advanced_settings: { institutional_access_text: value },
				},
			},
			{
				onSuccess: ( data: AdvancedSettingsConfig ) => {
					updateWizardSettings( {
						slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
						path: [ 'config', 'advanced_settings' ],
						value: data,
					} );
					addNotice( {
						message: __( 'Settings updated.', 'newspack-plugin' ),
						type: 'success',
						id: 'institutions-advanced-settings-updated',
						actions: [
							{
								label: __( 'Undo', 'newspack-plugin' ),
								onClick: () => saveText.current?.( savedText ),
							},
						],
					} );
				},
				onError: ( fetchError: WpFetchError ) => {
					addNotice( {
						message: decodeEntities( fetchError.message ),
						type: 'error',
						id: 'institutions-advanced-settings-error',
					} );
				},
				onFinally: () => {
					closeModal();
				},
			}
		);
	};

	saveText.current = handleSaveText;
	return (
		<Modal onClose={ closeModal } size="medium" title={ __( 'Advanced Settings', 'newspack-plugin' ) } onRequestClose={ closeModal }>
			<Stack direction="column" gap="xl" className="newspack-content-gates__stack">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Institutional access link text', 'newspack-plugin' ) }
					help={ __(
						'Shown to logged-out readers below the gate when an institution with an IP range can unlock it. The link checks whether the reader is on that network. Leave empty to use the default.',
						'newspack-plugin'
					) }
					placeholder={ institutionalAccessDefaultText }
					maxLength={ 200 }
					value={ text }
					onChange={ ( value: string ) => setText( value ) }
				/>
				<Stack direction="row" gap="sm" justify="end">
					<Button variant="tertiary" disabled={ isFetching } onClick={ closeModal }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						disabled={ isFetching || text === savedText }
						loading={ isFetching }
						onClick={ () => saveText.current?.( text ) }
					>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</Stack>
			</Stack>
		</Modal>
	);
};

export default InstitutionsAdvancedSettings;
