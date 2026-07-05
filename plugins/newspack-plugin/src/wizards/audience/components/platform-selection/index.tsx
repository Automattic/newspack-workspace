/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { ActionCard, Button, Notice, PluginInstaller, useConfirmDialog, withWizardScreen } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsTab from '../../../wizards-tab';
import { NEWSPACK, NRH, OTHER } from '../../constants';

// The payment endpoint (api_update_payment_settings) persists the platform slug and
// is the same store slug the Setup view reads platform_selected from.
const PAYMENT_WIZARD_SLUG = 'newspack-audience/payment';

export const PLATFORM_PLUGINS = {
	[ NEWSPACK ]: [ 'woocommerce', 'woocommerce-subscriptions', 'newspack-blocks' ],
	[ NRH ]: [ 'newspack-blocks' ],
	[ OTHER ]: [ 'newspack-blocks' ],
};

export const OPTIONS = [
	{
		value: NEWSPACK,
		title: __( 'Newspack', 'newspack-plugin' ),
		description: __( 'Full reader revenue stack with checkout, donations, and subscriptions.', 'newspack-plugin' ),
	},
	{
		value: NRH,
		title: __( 'RevEngine', 'newspack-plugin' ),
		description: __( 'Use the Donate block with News Revenue Hub / RevEngine.', 'newspack-plugin' ),
	},
	{
		value: OTHER,
		title: __( 'Other', 'newspack-plugin' ),
		description: __( 'Use your own integrations. No checkout or donation tools are configured.', 'newspack-plugin' ),
	},
];

const PlatformSelection = ( { onComplete, onCancel, config, saveConfig, inFlight, showEnableToggle, platform, platformSelected } ) => {
	const { saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ installing, setInstalling ] = useState( null );
	const [ installFailed, setInstallFailed ] = useState( false );
	const { confirmDialog: disableDialog, requestConfirm: requestDisable } = useConfirmDialog( {
		title: __( 'Disable Audience Management?', 'newspack-plugin' ),
		message: __(
			'Disabling Audience Management turns off reader registration, the My Account dashboard, and related reader features. Your settings are preserved and you can re-enable it later.',
			'newspack-plugin'
		),
		confirmButtonText: __( 'Disable', 'newspack-plugin' ),
		isDestructive: true,
	} );

	const choose = value => {
		saveWizardSettings( {
			slug: PAYMENT_WIZARD_SLUG,
			payloadPath: [ 'platform_data' ],
			updatePayload: {
				path: [ 'platform_data', 'platform' ],
				value,
			},
		} ).then( result => {
			// On a failed save the store swallows the error and resolves to
			// undefined; don't advance past an unsaved platform choice.
			if ( ! result ) {
				return;
			}
			// Selecting a platform enables Audience Management when it isn't already on.
			if ( ! config?.enabled ) {
				saveConfig( { enabled: true } );
			}
			if ( PLATFORM_PLUGINS[ value ].length ) {
				setInstalling( value );
			} else {
				onComplete();
			}
		} );
	};

	return (
		<WizardsTab
			title={ __( 'Reader Revenue Platform', 'newspack-plugin' ) }
			description={ __(
				'Choose how you collect reader revenue. Your selection determines which plugins are installed and which settings are available.',
				'newspack-plugin'
			) }
		>
			{ installing ? (
				<>
					<PluginInstaller
						plugins={ PLATFORM_PLUGINS[ installing ] }
						autoInstall
						withoutFooterButton
						onStatus={ ( { complete, pluginInfo } ) => {
							if ( complete ) {
								onComplete();
								return;
							}
							// Some platform plugins (e.g. WooCommerce Subscriptions) can't be installed
							// automatically. Once every plugin has either activated or reported an error,
							// surface a way to continue instead of waiting on an install that won't finish.
							const settled = Object.values( pluginInfo ).every( plugin => 'active' === plugin.Status || plugin.notification );
							if ( settled ) {
								setInstallFailed( true );
							}
						} }
					/>
					{ installFailed && (
						<>
							<Notice
								isWarning
								noticeText={ __(
									'Some plugins could not be installed automatically. Install them manually using the links above, or continue and finish setup later.',
									'newspack-plugin'
								) }
							/>
							<div className="newspack-buttons-card">
								<Button isPrimary onClick={ onComplete }>
									{ __( 'Continue', 'newspack-plugin' ) }
								</Button>
							</div>
						</>
					) }
				</>
			) : (
				<>
					{ showEnableToggle && (
						<ActionCard
							isMedium
							title={ __( 'Audience Management', 'newspack-plugin' ) }
							description={
								config?.enabled
									? __( 'Audience Management is enabled.', 'newspack-plugin' )
									: __( 'Audience Management is disabled.', 'newspack-plugin' )
							}
							toggleChecked={ Boolean( config?.enabled ) }
							toggleOnChange={ value => {
								if ( value ) {
									// Enabling moves the user forward to the configuration page.
									saveConfig( { enabled: true } ).then( () => onComplete() );
								} else {
									requestDisable( () => saveConfig( { enabled: false } ) );
								}
							} }
							disabled={ inFlight }
						/>
					) }
					{ OPTIONS.map( option => {
						// Only badge a platform once one has actually been chosen. The slug
						// defaults to 'wc' (Newspack) before any selection, so without this
						// guard Newspack would appear "Selected" on first run.
						const isSelected = platformSelected && option.value === platform;
						return (
							<ActionCard
								key={ option.value }
								isMedium
								title={ option.title }
								description={ option.description }
								badge={ isSelected ? __( 'Selected', 'newspack-plugin' ) : undefined }
								badgeLevel={ isSelected ? 'success' : undefined }
								actionText={ __( 'Select', 'newspack-plugin' ) }
								onClick={ () => choose( option.value ) }
							/>
						);
					} ) }
					{ onCancel && (
						<div className="newspack-buttons-card">
							<Button isSecondary onClick={ onCancel }>
								{ __( 'Cancel', 'newspack-plugin' ) }
							</Button>
						</div>
					) }
				</>
			) }
			{ disableDialog }
		</WizardsTab>
	);
};

export default withWizardScreen( PlatformSelection );
