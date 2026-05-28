/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { AutocompleteWithSuggestions, Button, Divider, Grid, TextControl } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import WizardsSection from '../../../wizards-section';

const NRHSettings = () => {
	const [ selectedPage, setSelectedPage ] = useState( null );
	const wizardData = useWizardData( 'newspack-audience/payment' );
	const { updateWizardSettings, saveWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );

	useEffect( () => {
		if ( wizardData?.platform_data?.donor_landing_page ) {
			setSelectedPage( wizardData.platform_data.donor_landing_page );
		}
	}, [] );

	const changeHandler = ( key, value ) => {
		return updateWizardSettings( {
			slug: 'newspack-audience/payment',
			path: [ 'platform_data', key ],
			value,
		} );
	};
	const onSave = () =>
		saveWizardSettings( {
			slug: 'newspack-audience/payment',
			payloadPath: [ 'platform_data' ],
		} );

	const settings = wizardData?.platform_data || {};

	return (
		<WizardsSection
			title={ __( 'News Revenue Hub Settings', 'newspack-plugin' ) }
			description={ __( 'Configure your site’s connection to News Revenue Hub.', 'newspack-plugin' ) }
		>
			<div>
				<Grid columns={ 3 }>
					<TextControl
						label={ __( 'Organization ID', 'newspack-plugin' ) }
						placeholder="exampleid"
						value={ settings?.nrh_organization_id || '' }
						onChange={ value => changeHandler( 'nrh_organization_id', value ) }
					/>
					<TextControl
						label={ __( 'Custom domain (optional)', 'newspack-plugin' ) }
						help={ __( 'Enter the raw domain without protocol or slashes.' ) }
						placeholder="donate.example.com"
						value={ settings?.nrh_custom_domain || '' }
						onChange={ value => changeHandler( 'nrh_custom_domain', value ) }
					/>
					<TextControl
						label={ __( 'Salesforce Campaign ID (optional)', 'newspack-plugin' ) }
						placeholder="exampleid"
						value={ settings?.nrh_salesforce_campaign_id || '' }
						onChange={ value => changeHandler( 'nrh_salesforce_campaign_id', value ) }
					/>
				</Grid>
			</div>
			{ settings.hasOwnProperty( 'donor_landing_page' ) && (
				<div>
					<h3>{ __( 'Donor Landing Page', 'newspack-plugin' ) }</h3>
					<p className="components-base-control__help">
						{ __(
							'Set a page on your site as a donor landing page. Once a reader donates and lands on this page, they will be considered a donor.',
							'newspack'
						) }
					</p>
					<AutocompleteWithSuggestions
						label={ __( 'Search for a New Donor Landing Page', 'newspack-plugin' ) }
						help={ __( 'Begin typing page title, click autocomplete result to select.', 'newspack' ) }
						onChange={ items => {
							if ( ! items || ! items.length ) {
								setSelectedPage( null );
								return changeHandler( 'donor_landing_page', null );
							}
							const item = items[ 0 ];
							setSelectedPage( item );
							return changeHandler( 'donor_landing_page', item );
						} }
						postTypes={ [ { slug: 'page', label: 'Page' } ] }
						postTypeLabel={ __( 'page', 'newspack-plugin' ) }
						postTypeLabelPlural={ __( 'pages', 'newspack-plugin' ) }
						selectedItems={ selectedPage ? [ selectedPage ] : [] }
					/>
				</div>
			) }
			<div className="newspack-buttons-card">
				<Button isPrimary onClick={ onSave }>
					{ __( 'Save Settings' ) }
				</Button>
			</div>
			<Divider alignment="full-width" variant="tertiary" />
		</WizardsSection>
	);
};

export default NRHSettings;
