/**
 * WordPress dependencies.
 */
import { CardDivider } from '@wordpress/components';
import { Fragment, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import AccessRule from './access-rule';

const availableAccessRules = window.newspackAudienceContentGates.available_access_rules || {};

interface AccessRulesProps {
	rules: GateAccessRule[];
	onChange: ( rules: GateAccessRule[] ) => void;
}

export default function AccessRules( { rules, onChange }: AccessRulesProps ) {
	const handleToggle = useCallback(
		( slug: string ) => {
			const hasRule = rules.find( r => r.slug === slug );
			if ( hasRule ) {
				onChange( rules.filter( r => r.slug !== slug ) );
			} else {
				onChange( [ ...rules, { slug, value: availableAccessRules[ slug ].default } ] );
			}
		},
		[ rules, onChange ]
	);

	const handleChange = useCallback(
		( slug: string ) => ( v: GateAccessRuleValue ) => {
			onChange( rules.map( r => ( r.slug === slug ? { ...r, value: v } : r ) ) );
		},
		[ onChange, rules ]
	);

	return (
		<>
			{ Object.keys( availableAccessRules ).map( ( slug, index ) => {
				const ruleConfig = availableAccessRules[ slug ];
				const rule = rules.find( r => r.slug === slug );
				return (
					<Fragment key={ slug }>
						<AccessRule
							config={ ruleConfig }
							enabled={ rules.map( r => r.slug ).includes( slug ) }
							rule={ rule }
							slug={ slug }
							onChange={ handleChange( slug ) }
							onToggle={ handleToggle }
						/>
						{ index < Object.keys( availableAccessRules ).length - 1 && <CardDivider key={ index } /> }
					</Fragment>
				);
			} ) }
		</>
	);
}
