/**
 * Premium newsletters management screen.
 */

import '../../../../shared/js/public-path';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { forwardRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Wizard, withWizard } from '../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { PREMIUM_NEWSLETTERS_WIZARD_SLUG, BASE_HEADER_TEXT } from './consts';
import PremiumNewslettersList from './premium-newsletters-list';
import Edit from '../../../audience/views/content-gates/edit';
import { redirectWithoutAudienceManagement, requireAudienceManagement } from '../../../audience/components/audience-management-required';

// Premium Newsletters localizes into the content-gates bag, which it shares with
// the Access Control screen.
const getConfig = () => window.newspackAudienceContentGates;

const REQUIRES_AUDIENCE_MANAGEMENT = {
	description: __( 'Premium newsletters need accounts, sign-in, and account emails. Audience Management provides them.', 'newspack-plugin' ),
	getConfig,
};

const ROOT = [ { label: __( 'Newsletters', 'newspack-plugin' ) } ];
const PREMIUM_BREADCRUMBS = [ ...ROOT, { label: __( 'Premium', 'newspack-plugin' ) } ];

// Wrapped at module scope so each section keeps a stable component type across
// renders. Only the landing route renders the prerequisite state; the editor
// redirects to it.
const GATES_ROUTE = '/content-gates';
const GuardedPremiumNewslettersList = requireAudienceManagement( PremiumNewslettersList, REQUIRES_AUDIENCE_MANAGEMENT );
const GuardedEdit = redirectWithoutAudienceManagement( Edit, GATES_ROUTE, getConfig );

const PremiumNewsletters = ( props, ref ) => {
	const { updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const updateGatesData = gates => {
		updateWizardSettings( {
			slug: PREMIUM_NEWSLETTERS_WIZARD_SLUG,
			path: [ 'gates' ],
			value: gates,
		} );
	};

	return (
		<Wizard
			apiSlug={ PREMIUM_NEWSLETTERS_WIZARD_SLUG }
			title={ __( 'Access Control', 'newspack-plugin' ) }
			headerText={ BASE_HEADER_TEXT }
			ref={ ref }
			sharedProps={ { updateGatesData } }
			sections={ [
				{
					path: '/content-gates',
					render: GuardedPremiumNewslettersList,
					breadcrumbs: PREMIUM_BREADCRUMBS,
				},
				{
					path: '/edit/:id/:type?',
					render: GuardedEdit,
					isHidden: true,
					exact: true,
					breadcrumbs: PREMIUM_BREADCRUMBS,
					props: {
						isNewsletter: true,
						slug: PREMIUM_NEWSLETTERS_WIZARD_SLUG,
					},
				},
			] }
		/>
	);
};

export default withWizard( forwardRef( PremiumNewsletters ) );
