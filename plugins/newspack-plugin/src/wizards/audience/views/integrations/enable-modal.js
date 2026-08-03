/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { Button, Modal, Notice } from '../../../../../packages/components/src';
import { SettingsField } from './settings-field';
import './enable-modal.scss';

const isEmptyValue = value => value === undefined || value === null || value === '';

// Server-managed field types (see Integration::MANAGED_FIELD_TYPES) can't be
// collected here: the settings endpoint refuses client writes for them.
const MANAGED_FIELD_TYPES = [ 'oauth', 'hidden' ];

/**
 * Get an integration's required settings fields that don't have a value yet.
 *
 * @param {Object} integration Integration settings object from the wizard API.
 * @return {Array} Required field declarations with empty values.
 */
export const getMissingRequiredFields = integration =>
	( integration?.settings || [] ).filter( field => field.required && ! MANAGED_FIELD_TYPES.includes( field.type ) && isEmptyValue( field.value ) );

/**
 * Modal collecting an integration's missing required settings, then enabling it.
 *
 * @param {Object}   props                Component props.
 * @param {Object}   props.integration    Integration settings object.
 * @param {Function} props.onClose        Called to dismiss the modal.
 * @param {Function} props.onEnable       Called with the collected values; must return a promise.
 *                                        The parent closes the modal on success.
 * @param {Function} props.onGoToSettings Called to navigate to the integration's settings view.
 */
export const EnableModal = ( { integration, onClose, onEnable, onGoToSettings } ) => {
	const [ values, setValues ] = useState( {} );
	const [ enabling, setEnabling ] = useState( false );
	const [ error, setError ] = useState( null );

	const missingFields = getMissingRequiredFields( integration );
	const getValue = field => ( field.key in values ? values[ field.key ] : field.value ?? '' );
	const hasEmptyField = missingFields.some( field => isEmptyValue( getValue( field ) ) );
	// A required select with no selectable (non-empty) options can never be
	// satisfied from here — offer the settings view instead of a dead control.
	const hasUnsatisfiableField = missingFields.some(
		field => 'select' === field.type && ! ( field.options || [] ).some( option => ! isEmptyValue( option.value ) )
	);

	const handleEnable = () => {
		setEnabling( true );
		setError( null );
		// On success the parent closes the modal (unmounting this component), so
		// only reset the busy state on failure — where the modal stays open for a
		// retry. Resetting it unconditionally would set state on an unmounted
		// component in the success case.
		onEnable( values ).catch( () => {
			setError( __( 'Something went wrong. Please try again.', 'newspack-plugin' ) );
			setEnabling( false );
		} );
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: integration name. */
				__( 'Enable %s', 'newspack-plugin' ),
				integration.name
			) }
			onRequestClose={ onClose }
			size="small"
		>
			{ hasUnsatisfiableField ? (
				<VStack spacing={ 6 } className="newspack-integration-enable-modal__content">
					<Notice
						isWarning
						noticeText={ __( 'No options are available yet. Configure this integration to complete setup.', 'newspack-plugin' ) }
					/>
					<HStack justify="flex-end" spacing={ 2 }>
						<Button variant="tertiary" onClick={ onClose }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" onClick={ onGoToSettings }>
							{ __( 'Open settings', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			) : (
				<VStack spacing={ 6 } className="newspack-integration-enable-modal__content">
					{ error && <Notice isError noticeText={ error } /> }
					{ missingFields.map( field => (
						<SettingsField
							key={ field.key }
							field={ field }
							value={ getValue( field ) }
							onChange={ value => setValues( prev => ( { ...prev, [ field.key ]: value } ) ) }
						/>
					) ) }
					<HStack justify="flex-end" spacing={ 2 }>
						<Button variant="tertiary" onClick={ onClose } disabled={ enabling }>
							{ __( 'Cancel', 'newspack-plugin' ) }
						</Button>
						<Button variant="primary" onClick={ handleEnable } isBusy={ enabling } disabled={ enabling || hasEmptyField }>
							{ enabling ? __( 'Enabling…', 'newspack-plugin' ) : __( 'Enable', 'newspack-plugin' ) }
						</Button>
					</HStack>
				</VStack>
			) }
		</Modal>
	);
};
