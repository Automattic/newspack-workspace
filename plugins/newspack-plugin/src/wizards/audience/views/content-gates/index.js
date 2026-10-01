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
import { hasAudienceManagement, redirectWithoutAudienceManagement, requireAudienceManagement } from '../../components/audience-management-required';
import ContentGates from './content-gates';
import Edit from './edit';
import MeteringSettings from './edit/metering-settings';
import ContentGifting from './edit/content-gifting';
import Institutions from './institutions';
import InstitutionEdit from './institutions/edit';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG, BASE_HEADER_TEXT } from './consts';

const ROOT = [ { label: __( 'Audience Management', 'newspack-plugin' ) } ];
const ACCESS_CONTROL = [ ...ROOT, { label: __( 'Access Control', 'newspack-plugin' ) } ];
const ACCESS_CONTROL_GATES = [ ...ACCESS_CONTROL, { label: __( 'Content Gates', 'newspack-plugin' ), url: '#/content-gates' } ];
const ACCESS_CONTROL_INSTITUTIONS = [ ...ACCESS_CONTROL, { label: __( 'Institutions', 'newspack-plugin' ), url: '#/institutions' } ];

// Wrapped at module scope so each section keeps a stable component type across
// renders. Only the landing route renders the prerequisite state; the rest redirect
// to it, so the explanation lives in exactly one place.
const GATES_ROUTE = '/content-gates';
const getConfig = () => window.newspackAudienceContentGates;

const GuardedContentGates = requireAudienceManagement( ContentGates, {
	description: __( 'Access Control needs accounts, sign-in, and account emails. Audience Management provides them.', 'newspack-plugin' ),
	getConfig,
} );
const GuardedEdit = redirectWithoutAudienceManagement( Edit, GATES_ROUTE, getConfig );
const GuardedMeteringSettings = redirectWithoutAudienceManagement( MeteringSettings, GATES_ROUTE, getConfig );
const GuardedContentGifting = redirectWithoutAudienceManagement( ContentGifting, GATES_ROUTE, getConfig );
const GuardedInstitutions = redirectWithoutAudienceManagement( Institutions, GATES_ROUTE, getConfig );
const GuardedInstitutionEdit = redirectWithoutAudienceManagement( InstitutionEdit, GATES_ROUTE, getConfig );

const AudienceContentGates = ( props, ref ) => {
	// Without Audience Management these tabs only redirect back to Content Gates, so they are not offered.
	const withoutAudienceManagement = ! hasAudienceManagement( getConfig() );
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
					label: __( 'Content Gates', 'newspack-plugin' ),
					render: GuardedContentGates,
					breadcrumbs: [ ...ACCESS_CONTROL, { label: __( 'Content Gates', 'newspack-plugin' ) } ],
				},
				{
					path: '/settings/metering',
					isHidden: withoutAudienceManagement,
					label: __( 'Metering', 'newspack-plugin' ),
					render: GuardedMeteringSettings,
					exact: true,
					breadcrumbs: [ ...ACCESS_CONTROL, { label: __( 'Metering', 'newspack-plugin' ) } ],
				},
				{
					path: '/institutions',
					isHidden: withoutAudienceManagement,
					label: __( 'Institutions', 'newspack-plugin' ),
					render: GuardedInstitutions,
					exact: true,
					fullWidth: true,
					breadcrumbs: [ ...ACCESS_CONTROL, { label: __( 'Institutions', 'newspack-plugin' ) } ],
				},
				{
					path: '/settings/content-gifting',
					isHidden: withoutAudienceManagement,
					label: __( 'Content Gifting', 'newspack-plugin' ),
					render: GuardedContentGifting,
					exact: true,
					breadcrumbs: [ ...ACCESS_CONTROL, { label: __( 'Content Gifting', 'newspack-plugin' ) } ],
				},
				{
					path: '/edit/:id/:type?',
					render: GuardedEdit,
					isHidden: true,
					hideTabbedNavigation: true,
					exact: true,
					breadcrumbs: ACCESS_CONTROL_GATES,
				},
				{
					path: '/institutions/new',
					render: GuardedInstitutionEdit,
					isHidden: true,
					hideTabbedNavigation: true,
					exact: true,
					breadcrumbs: ACCESS_CONTROL_INSTITUTIONS,
				},
				{
					path: '/institutions/:id',
					render: GuardedInstitutionEdit,
					isHidden: true,
					hideTabbedNavigation: true,
					exact: true,
					breadcrumbs: ACCESS_CONTROL_INSTITUTIONS,
				},
			] }
		/>
	);
};

export default withWizard( forwardRef( AudienceContentGates ) );
