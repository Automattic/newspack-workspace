/**
 * Pricing Rules management screen (RSM prototype). DataViews list + a common-fields
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

const AudiencePricingRules = ( props: object, ref: React.Ref< HTMLDivElement > ) => {
	return (
		<Wizard
			title={ __( 'Pricing Rules', 'newspack-plugin' ) }
			headerText={ __( 'Audience Management / Pricing Rules', 'newspack-plugin' ) }
			ref={ ref }
			fixedHeader
			sections={ [
				{ path: '/', render: PricingRulesList, exact: true, fullWidth: true },
				{ path: '/new', render: RuleEdit, isHidden: true, exact: true, backNav: '#/', title: __( 'Add rule', 'newspack-plugin' ) },
				{ path: '/edit/:id', render: RuleEdit, isHidden: true, exact: true, backNav: '#/', title: __( 'Edit rule', 'newspack-plugin' ) },
			] }
		/>
	);
};

export default withWizard( forwardRef( AudiencePricingRules ) );
