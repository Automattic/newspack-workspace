/**
 * WordPress dependencies.
 */
import { CheckboxControl } from '@wordpress/components';
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

interface ArchiveRulesProps {
	rules: GateArchiveRule[];
	onChange: ( rules: GateArchiveRule[] ) => void;
}

/**
 * One checkbox per archive page the site offers. Values the site no longer offers stay
 * in the rule untouched, so a stored choice survives a module being switched off and on.
 */
export default function ArchiveRules( { rules, onChange }: ArchiveRulesProps ) {
	const available = window.newspackAudienceContentGates?.available_archive_rules ?? {};

	const handleToggle = useCallback(
		( slug: string, optionValue: string, checked: boolean ) => {
			const current = rules.find( rule => rule.slug === slug )?.value ?? [];
			const next = checked ? [ ...current.filter( v => v !== optionValue ), optionValue ] : current.filter( v => v !== optionValue );
			const others = rules.filter( rule => rule.slug !== slug );
			if ( ! next.length ) {
				onChange( others );
			} else if ( rules.some( rule => rule.slug === slug ) ) {
				onChange( rules.map( rule => ( rule.slug === slug ? { ...rule, value: next } : rule ) ) );
			} else {
				onChange( [ ...others, { slug, value: next } ] );
			}
		},
		[ rules, onChange ]
	);

	const groups = Object.entries( available ).filter( ( [ , config ] ) => config.options?.length );
	if ( ! groups.length ) {
		return null;
	}

	return (
		<fieldset className="newspack-content-gate__archive-rules">
			<legend className="screen-reader-text">{ __( 'Archive pages', 'newspack-plugin' ) }</legend>
			{ groups.map( ( [ slug, config ] ) =>
				( config.options ?? [] ).map( option => (
					<CheckboxControl
						key={ `${ slug }:${ option.value }` }
						label={ option.label }
						checked={ ( rules.find( rule => rule.slug === slug )?.value ?? [] ).includes( option.value ) }
						onChange={ ( checked: boolean ) => handleToggle( slug, option.value, checked ) }
					/>
				) )
			) }
		</fieldset>
	);
}
