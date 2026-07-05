/**
 * WordPress dependencies
 */
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { Card, PluginInstaller, SelectControl } from '../../../../../packages/components/src';
import { NEWSPACK, NRH, OTHER } from '../../constants';
import WizardsSection from '../../../wizards-section';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';

/**
 * Platform Selection  Screen Component
 */
const Platform = () => {
	const wizardData = useWizardData( 'newspack-audience/payment' );
	const { saveWizardSettings, updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	return (
		<WizardsSection>
			<Card noBorder>
				<SelectControl
					label={ __( 'Select Reader Revenue Platform', 'newspack' ) }
					value={ wizardData.platform_data?.platform }
					options={ [
						{
							label: __( 'Other', 'newspack' ),
							value: OTHER,
						},
						{
							label: __( 'Newspack', 'newspack' ),
							value: NEWSPACK,
						},
						{
							label: __( 'News Revenue Hub', 'newspack' ),
							value: NRH,
						},
					] }
					onChange={ value => {
						saveWizardSettings( {
							slug: 'newspack-audience/payment',
							payloadPath: [ 'platform_data' ],
							updatePayload: {
								path: [ 'platform_data', 'platform' ],
								value,
							},
						} );
					} }
				/>
			</Card>
			{ NEWSPACK === wizardData.platform_data?.platform && ! wizardData.plugin_status && (
				<PluginInstaller
					plugins={ [ 'woocommerce', 'woocommerce-subscriptions' ] }
					onStatus={ ( { complete } ) => {
						if ( complete ) {
							updateWizardSettings( {
								slug: 'newspack-audience/payment',
								path: [ 'plugin_status' ],
								value: true,
							} );
						}
					} }
					withoutFooterButton={ true }
				/>
			) }
		</WizardsSection>
	);
};

export default Platform;
