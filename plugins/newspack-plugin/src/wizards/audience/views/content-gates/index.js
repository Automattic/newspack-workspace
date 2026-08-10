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
import { redirectWithoutAudienceManagement, requireAudienceManagement } from './audience-management-required';
import ContentGates from './content-gates';
import Edit from './edit';
import CountdownBanner from './edit/countdown-banner';
import ContentGifting from './edit/content-gifting';
import Institutions from './institutions';
import InstitutionEdit from './institutions/edit';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG, BASE_HEADER_TEXT } from './consts';

const ROOT = [ { label: __( 'Audience Management', 'newspack-plugin' ) } ];
const ACCESS_CONTROL = [ ...ROOT, { label: __( 'Access Control', 'newspack-plugin' ), url: '#/content-gates' } ];
const ACCESS_CONTROL_INSTITUTIONS = [ ...ACCESS_CONTROL, { label: __( 'Institutions', 'newspack-plugin' ), url: '#/institutions' } ];

// Wrapped at module scope so each section keeps a stable component type across
// renders. Only the landing route renders the prerequisite state; the rest redirect
// to it, so the explanation lives in exactly one place.
const GATES_ROUTE = '/content-gates';
const GuardedContentGates = requireAudienceManagement( ContentGates );
const GuardedEdit = redirectWithoutAudienceManagement( Edit, GATES_ROUTE );
const GuardedCountdownBanner = redirectWithoutAudienceManagement( CountdownBanner, GATES_ROUTE );
const GuardedContentGifting = redirectWithoutAudienceManagement( ContentGifting, GATES_ROUTE );
const GuardedInstitutions = redirectWithoutAudienceManagement( Institutions, GATES_ROUTE );
const GuardedInstitutionEdit = redirectWithoutAudienceManagement( InstitutionEdit, GATES_ROUTE );

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
			title={ __( 'Access Control', 'newspack-plugin' ) }
			headerText={ BASE_HEADER_TEXT }
			ref={ ref }
			sharedProps={ { updateGatesData } }
			sections={ [
				{
					path: '/content-gates',
					render: GuardedContentGates,
					breadcrumbs: ACCESS_CONTROL,
				},
				{
					path: '/edit/:id/:type?',
					render: GuardedEdit,
					isHidden: true,
					exact: true,
					breadcrumbs: ACCESS_CONTROL,
				},
				{
					path: '/settings/countdown-banner',
					render: GuardedCountdownBanner,
					isHidden: true,
					exact: true,
					backNav: '#/content-gates',
					title: __( 'Metered Countdown', 'newspack-plugin' ),
					breadcrumbs: [ ...ACCESS_CONTROL, { label: __( 'Metered Countdown', 'newspack-plugin' ) } ],
					description: __(
						'Show a countdown banner letting readers know how many free views they have left before content is restricted.',
						'newspack-plugin'
					),
				},
				{
					path: '/settings/content-gifting',
					render: GuardedContentGifting,
					isHidden: true,
					exact: true,
					backNav: '#/content-gates',
					title: __( 'Content Gifting', 'newspack-plugin' ),
					breadcrumbs: [ ...ACCESS_CONTROL, { label: __( 'Content Gifting', 'newspack-plugin' ) } ],
					description: __(
						'Let members gift articles to non-subscribers. Recipients can read the full content without needing to subscribe.',
						'newspack-plugin'
					),
				},
				{
					path: '/institutions',
					render: GuardedInstitutions,
					exact: true,
					isHidden: true,
					backNav: '#/content-gates',
					fullWidth: true,
					label: __( 'Institutions', 'newspack-plugin' ),
					breadcrumbs: [ ...ACCESS_CONTROL, { label: __( 'Institutions', 'newspack-plugin' ) } ],
				},
				{
					path: '/institutions/new',
					render: GuardedInstitutionEdit,
					isHidden: true,
					exact: true,
					backNav: '#/institutions',
					title: __( 'Add Institution', 'newspack-plugin' ),
					breadcrumbs: ACCESS_CONTROL_INSTITUTIONS,
				},
				{
					path: '/institutions/:id',
					render: GuardedInstitutionEdit,
					isHidden: true,
					exact: true,
					backNav: '#/institutions',
					title: __( 'Edit Institution', 'newspack-plugin' ),
					breadcrumbs: ACCESS_CONTROL_INSTITUTIONS,
				},
			] }
		/>
	);
};

export default withWizard( forwardRef( AudienceContentGates ) );
