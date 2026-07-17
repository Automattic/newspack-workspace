/**
 * Newspack > Settings > Print
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { CheckboxControl, SelectControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import WizardsActionCard from '../../../../wizards-action-card';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';

const PLATFORM_OPTIONS: { label: string; value: IndesignPlatform }[] = [
	{ label: __( 'Auto-detect (per export)', 'newspack-plugin' ), value: 'auto' },
	{ label: __( 'Mac', 'newspack-plugin' ), value: 'mac' },
	{ label: __( 'Windows', 'newspack-plugin' ), value: 'win' },
];

function Print() {
	const { description, apiData, isFetching, actionText, apiFetchToggle, errorMessage } = useWizardApiFetchToggle< PrintData >( {
		path: '/newspack/v1/wizard/newspack-settings/print',
		apiNamespace: 'newspack-settings/print',
		data: {
			module_enabled_print: false,
			indesign_platform: 'auto',
			indesign_post_types: [ 'post' ],
			available_post_types: [],
			indesign_exclude_captions: false,
		},
		description: __( 'Allows editors to export article content in Adobe InDesign Tagged Text format.', 'newspack-plugin' ),
	} );

	const togglePostType = ( slug: string, checked: boolean ) => {
		const next = new Set( apiData.indesign_post_types );
		if ( checked ) {
			next.add( slug );
		} else {
			next.delete( slug );
		}
		apiFetchToggle( { ...apiData, indesign_post_types: Array.from( next ) }, true );
	};

	return (
		<WizardsTab title={ __( 'Adobe InDesign', 'newspack-plugin' ) }>
			<WizardSection>
				<WizardsActionCard
					title={ __( 'Enable InDesign Export', 'newspack-plugin' ) }
					description={ description }
					disabled={ isFetching }
					actionText={ actionText }
					error={ errorMessage }
					toggleChecked={ apiData.module_enabled_print }
					toggleOnChange={ ( value: boolean ) => apiFetchToggle( { ...apiData, module_enabled_print: value }, true ) }
				/>
			</WizardSection>
			{ apiData.module_enabled_print && (
				<>
					<WizardSection
						title={ __( 'Header platform', 'newspack-plugin' ) }
						description={ __(
							'InDesign requires the export file to declare its host platform on the first line. Choose "Auto-detect" to match the operating system of whoever clicks Export, or pick a specific platform if your team always lays out on the same OS.',
							'newspack-plugin'
						) }
					>
						<SelectControl
							label={ __( 'Platform', 'newspack-plugin' ) }
							value={ apiData.indesign_platform }
							disabled={ isFetching }
							options={ PLATFORM_OPTIONS }
							onChange={ ( value: IndesignPlatform ) => apiFetchToggle( { ...apiData, indesign_platform: value }, true ) }
						/>
					</WizardSection>
					<WizardSection
						title={ __( 'Available post types', 'newspack-plugin' ) }
						description={ __(
							'Choose which post types show the "Export as Adobe InDesign" bulk and row actions on their admin list screens.',
							'newspack-plugin'
						) }
					>
						{ apiData.available_post_types.map( option => (
							<CheckboxControl
								key={ option.value }
								label={ option.label }
								checked={ apiData.indesign_post_types.includes( option.value ) }
								disabled={ isFetching }
								onChange={ ( checked: boolean ) => togglePostType( option.value, checked ) }
							/>
						) ) }
					</WizardSection>
					<WizardSection
						title={ __( 'Photo captions', 'newspack-plugin' ) }
						description={ __(
							'Photo captions are appended to the end of each export. Enable this to leave them out — photo credits are still included.',
							'newspack-plugin'
						) }
					>
						<CheckboxControl
							label={ __( 'Exclude photo captions', 'newspack-plugin' ) }
							checked={ apiData.indesign_exclude_captions }
							disabled={ isFetching }
							onChange={ ( checked: boolean ) => apiFetchToggle( { ...apiData, indesign_exclude_captions: checked }, true ) }
						/>
					</WizardSection>
				</>
			) }
		</WizardsTab>
	);
}

export default Print;
