/**
 * External dependencies
 */
import values from 'lodash/values';
import keys from 'lodash/keys';
import mapValues from 'lodash/mapValues';
import property from 'lodash/property';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { withWizardScreen, ActionCard, hooks } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import ReaderRevenue from './ReaderRevenue';
import { Settings as NewslettersSettings } from '../../../newsletters/views/settings';
import GAMOnboarding from '../../../advertising/components/onboarding';
import { AUDIENCE_DONATIONS_WIZARD_SLUG } from '../../../audience/constants';
import './style.scss';

const SERVICES_LIST = {
	'reader-revenue': {
		label: __( 'Reader Revenue', 'newspack' ),
		description: __( 'Encourage site visitors to contribute to your publishing through donations', 'newspack' ),
		Component: ReaderRevenue,
		configuration: { is_service_enabled: false },
	},
	newsletters: {
		label: __( 'Newsletters', 'newspack' ),
		description: __( 'Create email newsletters and send them to your mail lists, all without leaving your website', 'newspack' ),
		Component: NewslettersSettings,
		configuration: { is_service_enabled: false },
	},
	'google-ad-manager': {
		label: __( 'Google Ad Manager', 'newspack' ),
		description: __( 'An advanced ad inventory creation and management platform, allowing you to be specific about ad placements', 'newspack' ),
		Component: GAMOnboarding,
		configuration: { is_service_enabled: false },
	},
};

const Services = ( { renderPrimaryButton } ) => {
	const [ services, updateServices ] = hooks.useObjectState( SERVICES_LIST );
	const [ isLoading, setIsLoading ] = useState( true );
	const slugs = keys( services );
	const wizardData = useWizardData( AUDIENCE_DONATIONS_WIZARD_SLUG );

	useEffect( () => {
		apiFetch( {
			path: '/newspack/v1/wizard/newspack-setup-wizard/services',
		} ).then( response => {
			updateServices( response );
			setIsLoading( false );
		} );
	}, [] );

	const saveSettings = async () => {
		const data = mapValues( services, property( 'configuration' ) );
		// Add Reader Revenue Wizard data straight from the Wizard.
		data[ 'reader-revenue' ] = {
			...data[ 'reader-revenue' ],
			...wizardData,
		};
		return apiFetch( {
			path: '/newspack/v1/wizard/newspack-setup-wizard/services',
			method: 'POST',
			data,
		} );
	};

	return (
		<>
			{ values( services ).map( ( service, i ) => {
				const serviceSlug = slugs[ i ];
				const ServiceComponent = service.Component;
				return (
					<ActionCard
						isMedium
						key={ i }
						title={ service.label }
						description={ service.description }
						className={ serviceSlug }
						toggleChecked={ service.configuration.is_service_enabled }
						hasGreyHeader={ service.configuration.is_service_enabled }
						toggleOnChange={ is_service_enabled =>
							updateServices( {
								[ serviceSlug ]: { configuration: { is_service_enabled } },
							} )
						}
						disabled={ isLoading }
						href={ service.configuration.is_service_enabled && service.href }
						actionText={ service.configuration.is_service_enabled && service.actionText }
					>
						{ service.configuration.is_service_enabled && ServiceComponent ? (
							<ServiceComponent
								className="newspack-action-card__region-children__inner"
								configuration={ service.configuration }
								onUpdate={ configuration => updateServices( { [ serviceSlug ]: { configuration } } ) }
							/>
						) : null }
					</ActionCard>
				);
			} ) }
			<div className="newspack-buttons-card">{ renderPrimaryButton( { onClick: saveSettings } ) }</div>
		</>
	);
};

export default withWizardScreen( Services, { hidePrimaryButton: true } );
