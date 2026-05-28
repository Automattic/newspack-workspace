/**
 * WordPress dependencies.
 */
import { CardDivider } from '@wordpress/components';
import { Fragment, useCallback } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ContentRule from './content-rule';

const availableContentRules = window.newspackAudienceContentGates.available_content_rules || {};

interface ContentRulesProps {
	rules: GateContentRule[];
	onChange: ( rules: GateContentRule[] ) => void;
	isNewsletter?: boolean;
}

export default function ContentRules( { rules, onChange, isNewsletter = false }: ContentRulesProps ) {
	const handleToggle = useCallback(
		( slug: string ) => {
			const hasRule = rules.find( r => r.slug === slug );
			if ( hasRule ) {
				onChange( rules.filter( r => r.slug !== slug ) );
			} else {
				onChange( [ ...rules, { slug, value: availableContentRules[ slug ].default } ] );
			}
		},
		[ rules, onChange ]
	);

	const handleChange = useCallback(
		( slug: string ) => ( v: string[] ) => {
			onChange( rules.map( r => ( r.slug === slug ? { ...r, value: v } : r ) ) );
		},
		[ onChange, rules ]
	);

	const handleChangeExclusion = useCallback(
		( slug: string ) => ( e: boolean ) => {
			onChange( rules.map( r => ( r.slug === slug ? { ...r, exclusion: e } : r ) ) );
		},
		[ onChange, rules ]
	);

	return (
		<>
			{ Object.keys( availableContentRules ).map( ( slug, index ) => {
				const ruleConfig = availableContentRules[ slug ];
				const rule = rules.find( r => r.slug === slug );
				return (
					<Fragment key={ slug }>
						<ContentRule
							config={ ruleConfig }
							enabled={ rules.map( r => r.slug ).includes( slug ) }
							rule={ rule }
							slug={ slug }
							onChange={ handleChange( slug ) }
							onChangeExclusion={ handleChangeExclusion( slug ) }
							onToggle={ handleToggle }
							isNewsletter={ isNewsletter }
						/>
						{ index < Object.keys( availableContentRules ).length - 1 && <CardDivider key={ index } /> }
					</Fragment>
				);
			} ) }
		</>
	);
}
