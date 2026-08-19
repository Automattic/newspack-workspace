/**
 * WordPress dependencies.
 */
import { CardBody, CardDivider, ToggleControl } from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Metering from './metering';
import AccessRules from './access-rules';

/**
 * Whether any rule in any group targets a subscription.
 *
 * @param accessRules Grouped access rules.
 */
function hasSubscriptionRule( accessRules: GateAccessRuleGroup[] ) {
	return accessRules.some( group => group?.some( rule => rule?.slug === 'subscription' ) );
}

interface CustomAccessProps {
	customAccess: CustomAccess;
	onChange: ( customAccess: CustomAccess ) => void;
	isNewsletter?: boolean;
}

export default function CustomAccess( { customAccess, onChange, isNewsletter = false }: CustomAccessProps ) {
	// Flatten grouped rules for display (each group has one rule in OR mode).
	const currentRules = customAccess.access_rules.map( group => group[ 0 ] ).filter( Boolean );

	const handleChange = useCallback(
		( value: Partial< CustomAccess > ) => {
			// Spread the full object so fields this screen doesn't manage
			// (e.g. gate_layout_id) survive the update and the next save.
			onChange( {
				...customAccess,
				...value,
			} );
		},
		[ customAccess, onChange ]
	);

	const handleRulesChange = useCallback(
		( rules: GateAccessRule[] ) => {
			// Each rule is its own group for OR logic: [ [rule1], [rule2] ].
			const accessRules = rules.map( rule => [ rule ] );
			handleChange( {
				access_rules: accessRules,
				// Dropping the last subscription rule hides the grace toggle, so reset
				// it to its default rather than leave a stored `false` behind to take
				// effect invisibly if a subscription rule is later re-added.
				...( hasSubscriptionRule( accessRules ) ? {} : { payment_recovery_grace: true } ),
			} );
		},
		[ handleChange ]
	);

	// Every rule of every group, not just the flattened `currentRules`: a gate
	// authored over the REST API can carry a subscription rule anywhere in a
	// group, and the toggle is load-bearing wherever it sits.
	const showGraceToggle = hasSubscriptionRule( customAccess.access_rules );

	return (
		<>
			{ ! isNewsletter && (
				<>
					<CardBody size="small">
						<Metering metering={ customAccess.metering } onChange={ ( metering: Metering ) => handleChange( { metering } ) } />
					</CardBody>
					<CardDivider />
				</>
			) }
			<AccessRules rules={ currentRules } onChange={ handleRulesChange } />
			{ showGraceToggle && (
				<>
					<CardDivider />
					<CardBody size="small">
						<ToggleControl
							label={ __( 'Grace during payment recovery', 'newspack-plugin' ) }
							help={ __(
								'Keep access for readers whose subscription renewal payment failed while it is being retried automatically.',
								'newspack-plugin'
							) }
							checked={ customAccess.payment_recovery_grace ?? true }
							onChange={ value => handleChange( { payment_recovery_grace: value } ) }
						/>
					</CardBody>
				</>
			) }
		</>
	);
}
