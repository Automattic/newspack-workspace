/**
 * Newspack > Settings > Privacy
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import { ActionCard, Button } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';

const PATH = '/newspack/v1/wizard/newspack-settings/privacy';

type PrivacyData = {
	block_ads_before_consent: boolean;
	block_before_consent: boolean;
};

function Privacy() {
	const { wizardApiFetch, isFetching } = useWizardApiFetch( 'newspack-settings/privacy' );

	const [ data, setData ] = useState< PrivacyData >( {
		block_ads_before_consent: false,
		block_before_consent: false,
	} );

	useEffect( () => get(), [] );

	function get() {
		wizardApiFetch< PrivacyData >( { path: PATH }, { onSuccess: res => setData( res ) } );
	}

	function save() {
		wizardApiFetch< PrivacyData >(
			{
				path: PATH,
				method: 'POST',
				updateCacheMethods: [ 'GET' ],
				data,
			},
			{ onSuccess: res => setData( res ) }
		);
	}

	return (
		<WizardsTab title={ __( 'Privacy', 'newspack-plugin' ) } className={ isFetching ? 'is-fetching' : '' }>
			<WizardSection
				title={ __( 'Complianz', 'newspack-plugin' ) }
				description={ __(
					"Adjust the Complianz plugin's behavior to control how scripts are loaded in relation to cookie consent.",
					'newspack-plugin'
				) }
			>
				<ActionCard
					isMedium
					disabled={ isFetching }
					toggleChecked={ data.block_before_consent }
					title={ __( 'Block cookies and third-party trackers before consent', 'newspack-plugin' ) }
					toggleOnChange={ ( block_before_consent: boolean ) => setData( { ...data, block_before_consent } ) }
					description={ __(
						'Force Complianz to attempt to block cookies and third-party trackers if a user has not consented to the cookie notice, regardless of its own configuration.',
						'newspack-plugin'
					) }
				/>
				{ data.block_before_consent && (
					<ActionCard
						isMedium
						disabled={ isFetching }
						toggleChecked={ data.block_ads_before_consent }
						title={ __( 'Block ad scripts before consent', 'newspack-plugin' ) }
						toggleOnChange={ ( block_ads_before_consent: boolean ) => setData( { ...data, block_ads_before_consent } ) }
						description={ __(
							'Attempt to prevent ad scripts from loading until the visitor has accepted the cookie notice.',
							'newspack-plugin'
						) }
					/>
				) }
			</WizardSection>
			<div className="newspack-buttons-card">
				<Button isPrimary onClick={ save } disabled={ isFetching }>
					{ isFetching ? __( 'Loading…', 'newspack-plugin' ) : __( 'Save Settings', 'newspack-plugin' ) }
				</Button>
			</div>
		</WizardsTab>
	);
}

export default Privacy;
