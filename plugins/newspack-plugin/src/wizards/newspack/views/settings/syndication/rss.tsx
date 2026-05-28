/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import WizardsActionCard from '../../../../wizards-action-card';
import useWizardApiFetchToggle from '../../../../hooks/use-wizard-api-fetch-toggle';

function Rss() {
	const { description, apiData, isFetching, actionText, apiFetchToggle, errorMessage } = useWizardApiFetchToggle< RssData >( {
		path: '/newspack/v1/wizard/newspack-settings/syndication',
		apiNamespace: 'newspack-settings/syndication/rss',
		refreshOn: [ 'POST' ],
		data: {
			module_enabled_rss: false,
			'module_enabled_media-partners': false,
		},
		description: __( 'Create and manage customized RSS feeds for syndication partners', 'newspack-plugin' ),
	} );

	return (
		<WizardsActionCard
			title={ __( 'RSS Enhancements', 'newspack' ) }
			description={ description }
			disabled={ isFetching }
			actionText={ actionText }
			error={ errorMessage }
			toggleChecked={ apiData.module_enabled_rss }
			toggleOnChange={ ( value: boolean ) => apiFetchToggle( { ...apiData, module_enabled_rss: value }, true ) }
		/>
	);
}

export default Rss;
