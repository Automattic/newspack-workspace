/**
 * Content gates management screen.
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
import ContentGates from './content-gates';
import Edit from './edit';
import CountdownBanner from './edit/countdown-banner';
import ContentGifting from './edit/content-gifting';
import Institutions from './institutions';
import InstitutionEdit from './institutions/edit';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG, BASE_HEADER_TEXT } from './consts';

const AudienceContentGates = ( props, ref ) => {
	const { updateWizardSettings } = useDispatch( WIZARD_STORE_NAMESPACE );
	const updateGatesData = gates => {
		updateWizardSettings( {
			slug: AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
			path: [ 'gates' ],
			value: gates,
		} );
	};

	return (
		<Wizard
			apiSlug={ AUDIENCE_CONTENT_GATES_WIZARD_SLUG }
			title={ __( 'Access control', 'newspack-plugin' ) }
			headerText={ BASE_HEADER_TEXT }
			ref={ ref }
			sharedProps={ { updateGatesData } }
			fixedHeader
			sections={ [
				{
					path: '/content-gates',
					render: ContentGates,
				},
				{
					path: '/edit/:id/:type?',
					render: Edit,
					isHidden: true,
					exact: true,
				},
				{
					path: '/settings/countdown-banner',
					render: CountdownBanner,
					isHidden: true,
					exact: true,
					backNav: '#/content-gates',
					title: __( 'Metered countdown', 'newspack-plugin' ),
					description: __(
						'Show a countdown banner letting readers know how many free views they have left before content is restricted.',
						'newspack-plugin'
					),
				},
				{
					path: '/settings/content-gifting',
					render: ContentGifting,
					isHidden: true,
					exact: true,
					backNav: '#/content-gates',
					title: __( 'Content gifting', 'newspack-plugin' ),
					description: __(
						'Let members gift articles to non-subscribers. Recipients can read the full content without needing to subscribe.',
						'newspack-plugin'
					),
				},
				{
					path: '/institutions',
					render: Institutions,
					exact: true,
					isHidden: true,
					backNav: '#/content-gates',
					fullWidth: true,
				},
				{
					path: '/institutions/new',
					render: InstitutionEdit,
					isHidden: true,
					exact: true,
					backNav: '#/institutions',
					title: __( 'Add new institution', 'newspack-plugin' ),
				},
				{
					path: '/institutions/:id',
					render: InstitutionEdit,
					isHidden: true,
					exact: true,
					backNav: '#/institutions',
					title: __( 'Edit institution', 'newspack-plugin' ),
				},
			] }
		/>
	);
};

export default withWizard( forwardRef( AudienceContentGates ) );
