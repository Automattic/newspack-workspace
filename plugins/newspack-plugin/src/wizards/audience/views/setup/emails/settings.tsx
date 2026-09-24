/**
 * Newspack > Settings > Emails > Emails section
 */

/**
 * WordPress dependencies.
 */
import { useDispatch } from '@wordpress/data';
import { Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies.
 */
import WizardsActionCard from '../../../../wizards-action-card';
import { useWizardData } from '../../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { SectionHeader } from '../../../../../../packages/components/src';

const DATA_STORE_KEY = 'newspack-settings/emails';

const Settings = () => {
	const { enable_woocommerce_email_editor: isEnabled, admin_url: url } = useWizardData( DATA_STORE_KEY );
	const { saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );

	if ( typeof isEnabled !== 'boolean' || ! url ) {
		return null;
	}

	const toggle = () => {
		saveWizardSettings( {
			slug: DATA_STORE_KEY,
			updatePayload: {
				path: [ 'enable_woocommerce_email_editor' ],
				value: ! isEnabled,
			},
		} );
	};

	const title = __( "Use WooCommerce's block email editor (alpha)", 'newspack-plugin' );
	const description = __( 'Enable the block-based email editor for transacitonal emails', 'newspack-plugin' );

	return (
		<Fragment>
			<SectionHeader heading={ 3 } title={ __( 'Transactional emails', 'newspack-plugin' ) } />
			<WizardsActionCard
				isSmall
				key={ 'enable_woocommerce_email_editor' }
				href={ url }
				title={ title }
				titleLink={ url }
				description={ description }
				actionText={ __( 'Edit emails', 'newspack-plugin' ) }
				toggleChecked={ isEnabled }
				toggleOnChange={ toggle }
			/>
		</Fragment>
	);
};

export default Settings;
