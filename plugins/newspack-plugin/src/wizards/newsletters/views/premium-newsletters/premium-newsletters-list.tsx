/**
 * Content Gate component.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis
import { useDispatch } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { useWizardApiFetch } from '../../../hooks/use-wizard-api-fetch';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import ContentGateOnboarding from '../../../audience/views/content-gates/content-gates-onboarding';
import ContentGateSettings from '../../../audience/views/content-gates/content-gate-settings';
import AdvancedSettings from './advanced-settings';
import { PREMIUM_NEWSLETTERS_WIZARD_SLUG } from './consts';
import '../../../audience/views/content-gates/style.scss';

const PremiumNewslettersList = ( { updateGatesData }: { updateGatesData: ( gates: Gate[] ) => void } ) => {
	const wizardData = useWizardData( PREMIUM_NEWSLETTERS_WIZARD_SLUG ) as WizardData;
	const { isFetching, errorMessage } = useWizardApiFetch( PREMIUM_NEWSLETTERS_WIZARD_SLUG );
	const { addNotice, resetHeaderData, setHeaderData } = useDispatch( WIZARD_STORE_NAMESPACE );
	const [ showAdvancedSettings, setShowAdvancedSettings ] = useState( false );

	const ref = useRef( null );
	const gates = ( wizardData?.gates || [] ) as Gate[];

	useEffect( () => {
		if ( isFetching ) {
			return;
		}
		if ( ! gates?.length ) {
			resetHeaderData();
			return;
		}
		setHeaderData( {
			actions: [
				{
					type: 'primary',
					label: __( 'Add new premium newsletter', 'newspack-plugin' ),
					href: '#/edit/new/all',
				},
			],
			sectionTitle: __( 'Premium newsletters', 'newspack-plugin' ),
			sectionDescription: __( 'Set up premium newsletters to control access to your lists.', 'newspack-plugin' ),
			sectionMenu: [
				{
					label: __( 'Advanced settings', 'newspack-plugin' ),
					action: () => setShowAdvancedSettings( true ),
				},
			],
		} );
	}, [ isFetching, gates ] );

	useEffect( () => {
		if ( errorMessage ) {
			addNotice( {
				message: errorMessage,
				type: 'error',
				id: 'premium-newsletter-error',
			} );
		}
	}, [ errorMessage ] );

	if ( ! gates?.length ) {
		return <ContentGateOnboarding isNewsletter />;
	}

	return (
		<>
			<VStack className="newspack-content-gates__gates" spacing="16px" ref={ ref }>
				{ gates.map( gate => {
					return (
						<ContentGateSettings
							key={ gate.id }
							gate={ gate }
							updateGatesData={ updateGatesData }
							slug={ PREMIUM_NEWSLETTERS_WIZARD_SLUG }
							isNewsletter
						/>
					);
				} ) }
			</VStack>
			<AdvancedSettings showModal={ showAdvancedSettings } closeModal={ () => setShowAdvancedSettings( false ) } />
		</>
	);
};
export default PremiumNewslettersList;
