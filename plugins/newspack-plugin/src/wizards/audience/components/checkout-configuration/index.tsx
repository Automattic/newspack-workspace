/**
 * WordPress dependencies.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { ToggleControl, TextareaControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Grid } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsSection from '../../../wizards-section';

const DATA_STORE_KEY = 'newspack-audience/checkout-configuration';

function CheckoutConfiguration() {
	const config = useWizardData( DATA_STORE_KEY );
	const { updateWizardSettings, saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const isQuietLoading = useSelect( ( select: any ) => select( WIZARD_STORE_NAMESPACE ).isQuietLoading() ?? false, [] );

	const onChange = ( value: any, key: string ) =>
		updateWizardSettings( {
			slug: DATA_STORE_KEY,
			path: [ key ],
			value,
		} );

	function onSave() {
		saveWizardSettings( {
			slug: DATA_STORE_KEY,
		} );
	}

	return (
		<WizardsSection title={ __( 'Checkout Configuration', 'newspack-plugin' ) } className={ isQuietLoading ? 'is-fetching' : '' }>
			<ToggleControl
				label={ __( 'Require sign in or create account before checkout', 'newspack-plugin' ) }
				help={ __(
					'Prompt users who are not logged in to sign in or register a new account before proceeding to checkout. When disabled, an account will automatically be created with the email address used at checkout.',
					'newspack-plugin'
				) }
				checked={ config.woocommerce_registration_required ?? false }
				onChange={ value => onChange( value, 'woocommerce_registration_required' ) }
				disabled={ isQuietLoading }
			/>
			<Grid>
				<TextareaControl
					label={ __( 'Post-checkout success message', 'newspack-plugin' ) }
					help={ __( 'The success message to display to readers after completing checkout.', 'newspack-plugin' ) }
					value={ config.woocommerce_post_checkout_success_text }
					onChange={ value => onChange( value, 'woocommerce_post_checkout_success_text' ) }
				/>
				{ ! config.woocommerce_registration_required && (
					<TextareaControl
						label={ __( 'Post-checkout registration success message', 'newspack-plugin' ) }
						help={ __(
							'The success message to display to new readers that have an account automatically created after completing checkout.',
							'newspack-plugin'
						) }
						value={ config.woocommerce_post_checkout_registration_success_text }
						onChange={ value => onChange( value, 'woocommerce_post_checkout_registration_success_text' ) }
					/>
				) }
			</Grid>
			<Grid>
				<TextareaControl
					label={ __( 'Checkout privacy policy text', 'newspack-plugin' ) }
					help={ __(
						'The privacy policy text to display at time of checkout for existing users. This will not show up unless a privacy page is set.',
						'newspack-plugin'
					) }
					value={ config.woocommerce_checkout_privacy_policy_text }
					onChange={ value => onChange( value, 'woocommerce_checkout_privacy_policy_text' ) }
				/>
			</Grid>
			<div className="newspack-buttons-card">
				<Button variant="primary" onClick={ onSave } disabled={ isQuietLoading }>
					{ isQuietLoading ? __( 'Saving…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
				</Button>
			</div>
		</WizardsSection>
	);
}

export default CheckoutConfiguration;
