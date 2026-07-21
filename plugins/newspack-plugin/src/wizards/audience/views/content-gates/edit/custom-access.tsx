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

interface CustomAccessProps {
	customAccess: CustomAccess;
	onChange: ( customAccess: Partial< CustomAccess > ) => void;
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
			handleChange( { access_rules: rules.map( rule => [ rule ] ) } );
		},
		[ handleChange ]
	);

	const hasSubscriptionRule = currentRules.some( rule => rule.slug === 'subscription' );

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
			{ hasSubscriptionRule && (
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
							onChange={ () => handleChange( { payment_recovery_grace: ! ( customAccess.payment_recovery_grace ?? true ) } ) }
						/>
					</CardBody>
				</>
			) }
		</>
	);
}
