/**
 * Pricing Rules management screen. DataViews list + a common-fields
 * editor over the standalone plugin's rules REST.
 */

import '../../../../shared/js/public-path';

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { forwardRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import { Wizard, withWizard } from '../../../../../packages/components/src';
import PricingRulesList from './list';
import RuleEdit from './rule-edit';
import './style.scss';

const ROOT = [ { label: __( 'Audience Management', 'newspack-plugin' ) } ];
const RULES_TRAIL = [ ...ROOT, { label: __( 'Pricing Rules', 'newspack-plugin' ), url: '#/' } ];

// No `title` on the editors: they publish their own leaf crumb, and a section title
// would repeat it as an <h2>.
export const SECTIONS = [
	// Ancestors only: the list owns its own leaf, so it can annotate it with the
	// number of rules matching the current search and filters.
	{ path: '/', render: PricingRulesList, exact: true, breadcrumbs: ROOT, fullWidth: true },
	{
		// One entry for both #/new and #/new/<goal>: choosing a goal rewrites the URL,
		// and a second entry would change the Route key and remount the editor.
		path: '/new/:goal?',
		render: RuleEdit,
		isHidden: true,
		exact: true,
		breadcrumbs: RULES_TRAIL,
		backNav: '#/',
	},
	{
		path: '/edit/:id',
		render: RuleEdit,
		isHidden: true,
		exact: true,
		breadcrumbs: RULES_TRAIL,
		backNav: '#/',
	},
];

const AudiencePricingRules = ( props: object, ref: React.Ref< HTMLDivElement > ) => {
	return <Wizard ref={ ref } sections={ SECTIONS } />;
};

export default withWizard( forwardRef( AudiencePricingRules ) );
