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
			title={ __( 'Access control', 'newspack-plugin' ) }
			headerText={ BASE_HEADER_TEXT }
			ref={ ref }
			sharedProps={ { updateGatesData } }
			fixedHeader
			sections={ [
				{
					path: '/content-gates',
					render: PremiumNewslettersList,
				},
				{
					path: '/edit/:id/:type?',
					render: Edit,
					isHidden: true,
					exact: true,
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
