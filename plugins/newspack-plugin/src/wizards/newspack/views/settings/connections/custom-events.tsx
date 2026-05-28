/**
 * Settings Wizard: Connections > Custom Events
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { WizardError } from '../../../../errors';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { Button, Grid, Notice, TextControl, utils } from '../../../../../../packages/components/src';
import { ERROR_MESSAGES } from './constants';

/**
 * Validate GA4 Measurement ID.
 *
 * @see   https://measureschool.com/ga4-measurement-id/
 *
 * @param measurementId Measurement ID to validate
 * @return boolean      True if the measurement ID is valid, false otherwise
 */
function isValidGA4MeasurementID( measurementId = '' ) {
	const ga4Pattern = /^G-[A-Za-z0-9]{10,}$/;
	return ga4Pattern.test( measurementId );
}

let { customEvents } = window.newspackSettings.connections.sections;

/**
 * Analytics Custom Events screen.
 */
function CustomEvents() {
	const [ ga4Credentials, setGa4Credentials ] = useState< Ga4Credentials >( customEvents );
	const { wizardApiFetch, errorMessage, resetError, setError } = useWizardApiFetch( 'newspack-settings/connections/custom-events' );

	function isInputsEmpty() {
		return ! ga4Credentials.measurement_id && ! ga4Credentials.measurement_protocol_secret;
	}

	useEffect( () => {
		if ( isInputsEmpty() ) {
			resetError();
			return;
		}
		if ( ! isValidGA4MeasurementID( ga4Credentials.measurement_id ) ) {
			setError( new WizardError( ERROR_MESSAGES.CUSTOM_EVENTS.INVALID_MEASUREMENT_ID, 'ga4_invalid_measurement_id' ) );
			return;
		}
		if ( ! ga4Credentials.measurement_protocol_secret ) {
			setError(
				new WizardError( ERROR_MESSAGES.CUSTOM_EVENTS.INVALID_MEASUREMENT_PROTOCOL_SECRET, 'ga4_invalid_measurement_protocol_secret' )
			);
			return;
		}
		resetError();
	}, [ ga4Credentials.measurement_id, ga4Credentials.measurement_protocol_secret ] );

	function updateGa4Credentials() {
		wizardApiFetch< Ga4Credentials >(
			{
				path: '/newspack/v2/wizard/analytics/ga4-credentials',
				method: 'POST',
				data: {
					measurement_id: ga4Credentials.measurement_id,
					measurement_protocol_secret: ga4Credentials.measurement_protocol_secret,
				},
			},
			{
				onSuccess( fetchedData ) {
					customEvents = fetchedData;
					setGa4Credentials( fetchedData );
				},
			}
		);
	}

	function resetGa4Credentials() {
		if ( ! utils.confirmAction( __( 'Are you sure you want to reset the GA4 credentials?', 'newspack-plugin' ) ) ) {
			return;
		}
		wizardApiFetch< Ga4Credentials >(
			{
				path: '/newspack/v2/wizard/analytics/ga4-credentials/reset',
				method: 'POST',
			},
			{
				onSuccess( fetchedData ) {
					customEvents = {
						measurement_id: '',
						measurement_protocol_secret: '',
					};
					setGa4Credentials( fetchedData );
				},
			}
		);
	}

	return (
		<div className="newspack__analytics-configuration">
			<div className="newspack__analytics-configuration__header">
				<p>
					{ __(
						"Newspack already sends some custom event data to your GA account, but adding the credentials below enables enhanced events that are fired from your site's backend. For example, when a donation is confirmed or when a user successfully subscribes to a newsletter.",
						'newspack-plugin'
					) }
				</p>
			</div>
			<Grid noMargin rowGap={ 16 }>
				<TextControl
					value={ ga4Credentials.measurement_id }
					label={ __( 'Measurement ID', 'newspack-plugin' ) }
					help={ __(
						'You can find this in Site Kit Settings, or in Google Analytics > Admin > Data Streams and clicking the data stream. Example: G-ABCDE12345',
						'newspack-plugin'
					) }
					onChange={ ( value: string ) => setGa4Credentials( { ...ga4Credentials, measurement_id: value } ) }
					autoComplete="off"
				/>
				<TextControl
					type="password"
					value={ ga4Credentials.measurement_protocol_secret }
					label={ __( 'Measurement Protocol API Secret', 'newspack-plugin' ) }
					help={ __(
						'Generate an API secret from your GA dashboard in Admin > Data Streams and opening your data stream. Select "Measurement Protocol API secrets" under the Events section. Create a new secret.',
						'newspack-plugin'
					) }
					onChange={ ( value: string ) => setGa4Credentials( { ...ga4Credentials, measurement_protocol_secret: value } ) }
					autoComplete="one-time-code"
				/>
			</Grid>
			{ errorMessage && <Notice isError noticeText={ errorMessage } /> }
			<Button className="mr2" variant="primary" onClick={ updateGa4Credentials } disabled={ isInputsEmpty() || !! errorMessage }>
				{ __( 'Save', 'newspack-plugin' ) }
			</Button>
			<Button variant="secondary" onClick={ resetGa4Credentials } disabled={ isInputsEmpty() }>
				{ __( 'Reset', 'newspack-plugin' ) }
			</Button>
		</div>
	);
}

export default CustomEvents;
