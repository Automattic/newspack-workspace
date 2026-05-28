/**
 * WordPress dependencies.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { ToggleControl, TextareaControl, TextControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Grid } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsSection from '../../../wizards-section';

const DATA_STORE_KEY = 'newspack-audience/subscription-settings';

function SubscriptionSettings() {
	const config = useWizardData( DATA_STORE_KEY );
	const { updateWizardSettings, saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const isQuietLoading = useSelect( ( select: any ) => select( WIZARD_STORE_NAMESPACE ).isQuietLoading() ?? false, [] );

	// Toggle between the Subscription confirmation and Terms & Conditions confirmation.
	// Only one can be enabled at a time.
	const onChange = ( value: any, key: string ) => {
		// If enabling Subscription confirmation, disable terms confirmation.
		if ( key === 'woocommerce_enable_subscription_confirmation' && value ) {
			updateWizardSettings( {
				slug: DATA_STORE_KEY,
				path: [ 'woocommerce_enable_terms_confirmation' ],
				value: false,
			} );
		}
		// If enabling terms confirmation, disable subscription confirmation.
		if ( key === 'woocommerce_enable_terms_confirmation' && value ) {
			updateWizardSettings( {
				slug: DATA_STORE_KEY,
				path: [ 'woocommerce_enable_subscription_confirmation' ],
				value: false,
			} );
		}

		// Update the original setting.
		updateWizardSettings( {
			slug: DATA_STORE_KEY,
			path: [ key ],
			value,
		} );
	};

	// When saving, if any of the text fields are empty, set the default text.
	function onSave() {
		// Use the default text when the Subscription Confirmation label is empty.
		if ( ! config.woocommerce_subscription_confirmation_text ) {
			updateWizardSettings( {
				slug: DATA_STORE_KEY,
				path: [ 'woocommerce_subscription_confirmation_text' ],
				value: __(
					'I understand this is a recurring subscription and that I can cancel anytime through the My Account Page.',
					'newspack-plugin'
				),
			} );
		}

		// Use the default text when the Terms & Conditions confirmation label is empty.
		if ( ! config.woocommerce_terms_confirmation_text ) {
			updateWizardSettings( {
				slug: DATA_STORE_KEY,
				path: [ 'woocommerce_terms_confirmation_text' ],
				value: __( 'I have read and accept the {{Terms & Conditions}}.', 'newspack-plugin' ),
			} );
		}

		// Make sure the URL is populated when Terms & Conditions confirmation is enabled.
		if ( config.woocommerce_enable_terms_confirmation && ! config.woocommerce_terms_confirmation_url ) {
			// eslint-disable-next-line no-alert
			alert( __( 'Please provide a URL for the Terms & Conditions page.', 'newspack-plugin' ) );
			return;
		}

		saveWizardSettings( {
			slug: DATA_STORE_KEY,
		} );
	}

	return (
		<WizardsSection
			title={ __( 'Subscription', 'newspack-plugin' ) }
			description={ __( 'Manage the settings for subscription transparency and compliance.', 'newspack-plugin' ) }
			className={ isQuietLoading ? 'is-fetching' : '' }
		>
			<Grid columns={ 1 }>
				<Grid columns={ 1 } gutter={ 8 }>
					<ToggleControl
						label={ __( 'Enable subscription confirmation checkbox', 'newspack-plugin' ) }
						help={ __(
							'Display a separate checkbox at checkout to confirm the user understands this is a recurring subscription and they can cancel anytime.',
							'newspack-plugin'
						) }
						checked={ config.woocommerce_enable_subscription_confirmation ?? false }
						onChange={ value => onChange( value, 'woocommerce_enable_subscription_confirmation' ) }
						disabled={ isQuietLoading }
					/>

					{ config.woocommerce_enable_subscription_confirmation && (
						<TextareaControl
							label={ __( 'Label', 'newspack-plugin' ) }
							value={ config.woocommerce_subscription_confirmation_text }
							onChange={ value => onChange( value, 'woocommerce_subscription_confirmation_text' ) }
						/>
					) }
				</Grid>

				<Grid columns={ 1 } gutter={ 8 }>
					<ToggleControl
						label={ __( 'Enable Terms & Conditions confirmation checkbox', 'newspack-plugin' ) }
						help={ __(
							"Display the 'I have read and accept the Terms & Conditions' checkbox at checkout. Ensure the Terms & Conditions include subscription details to comply with the FTC guidelines.",
							'newspack-plugin'
						) }
						checked={ config.woocommerce_enable_terms_confirmation ?? false }
						onChange={ value => onChange( value, 'woocommerce_enable_terms_confirmation' ) }
						disabled={ isQuietLoading }
					/>

					{ config.woocommerce_enable_terms_confirmation && (
						<Grid>
							<TextareaControl
								label={ __( 'Label', 'newspack-plugin' ) }
								value={ config.woocommerce_terms_confirmation_text }
								help={ __( 'Text wrapped in {{ }} will be linked to the page set in the URL field.', 'newspack-plugin' ) }
								onChange={ value => onChange( value, 'woocommerce_terms_confirmation_text' ) }
							/>
							<TextControl
								label={ __( 'URL', 'newspack-plugin' ) }
								value={ config.woocommerce_terms_confirmation_url }
								onChange={ value => onChange( value, 'woocommerce_terms_confirmation_url' ) }
							/>
						</Grid>
					) }
				</Grid>
			</Grid>

			<div className="newspack-buttons-card">
				<Button variant="primary" onClick={ onSave } disabled={ isQuietLoading }>
					{ isQuietLoading ? __( 'Saving…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
				</Button>
			</div>
		</WizardsSection>
	);
}

export default SubscriptionSettings;
