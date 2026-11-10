/**
 * WordPress dependencies.
 */
import {
	CardBody,
	CheckboxControl,
	ToggleControl,
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

interface ArchiveRulesProps {
	rules: GateArchiveRule[];
	onChange: ( rules: GateArchiveRule[] ) => void;
}

/**
 * The Archive pages row of the content rules card: a toggle, then one checkbox per archive
 * page the site offers. Values the site no longer offers stay in the rule untouched, so a
 * stored choice survives a module being switched off and on.
 */
export default function ArchiveRules( { rules, onChange }: ArchiveRulesProps ) {
	const available = window.newspackAudienceContentGates?.available_archive_rules ?? {};
	// Keeps the row open while no box is ticked, so unticking the last one doesn't collapse it.
	const [ isOpen, setIsOpen ] = useState( false );
	const enabled = isOpen || rules.length > 0;

	const handleToggle = useCallback(
		( slug: string, optionValue: string, checked: boolean ) => {
			setIsOpen( true );
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

	const handleEnable = useCallback(
		( on: boolean ) => {
			setIsOpen( on );
			if ( ! on ) {
				onChange( [] );
			}
		},
		[ onChange ]
	);

	const groups = Object.entries( available ).filter( ( [ , config ] ) => config.options?.length );
	if ( ! groups.length && ! rules.length ) {
		return null;
	}

	return (
		<CardBody size="small">
			<ToggleControl
				label={ __( 'Archive pages', 'newspack-plugin' ) }
				help={ __( 'Show the gate in place of the list on these pages.', 'newspack-plugin' ) }
				checked={ enabled }
				onChange={ handleEnable }
			/>
			{ enabled && (
				// The class sibling rule controls use, so the options sit as far below the toggle.
				<VStack className="newspack-content-gates__content-rule-control" spacing={ 2 }>
					<fieldset className="newspack-content-gate__archive-rules">
						<legend className="screen-reader-text">{ __( 'Archive pages to gate', 'newspack-plugin' ) }</legend>
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
					<p className="description newspack-content-gate__archive-note">
						{ __( "This doesn't restrict the items in the list. Use the other rules for that.", 'newspack-plugin' ) }
					</p>
				</VStack>
			) }
		</CardBody>
	);
}
