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

const ROOT = [ { label: __( 'Newsletters', 'newspack-plugin' ) } ];
const PREMIUM_BREADCRUMBS = [ ...ROOT, { label: __( 'Premium', 'newspack-plugin' ) } ];

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
					render: PremiumNewslettersList,
					breadcrumbs: PREMIUM_BREADCRUMBS,
				},
				{
					path: '/edit/:id/:type?',
					render: Edit,
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
