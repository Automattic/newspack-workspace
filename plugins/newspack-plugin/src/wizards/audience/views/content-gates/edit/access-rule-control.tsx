/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import type { TokenItem } from '@wordpress/components/build-types/form-token-field/types.d.ts';

/**
 * Internal dependencies
 */
import { FormTokenField } from '../../../../../../packages/components/src';
import {
	formatAccessRuleOptionLabel,
	getAccessRuleOptionTokens,
	MAX_OPTION_SUGGESTIONS,
	getAccessRuleTokenFieldMessages,
	getMissingOptionLabel,
	isAccessRuleOptionInput,
	resolveAccessRuleOptionTokens,
} from '../../../../../content-gate/access-rule-options';
import { isOptionBackedAccessRule } from '../../../../../content-gate/access-rule-option-sources';
import OneTimePurchaseRuleControl from '../../../../../content-gate/components/one-time-purchase-rule-control';
import UnlistedValuesNotice from '../../../../../content-gate/components/unlisted-values-notice';
import { isMalformedAccessRuleValue, isUnconstrainedAccessRuleValue } from '../utils';
import { useAccessRuleOptions } from '../use-access-rule-options';

export default function AccessRuleControl( { slug, value, onChange }: GateRuleControlProps ) {
	const rule = window.newspackAudienceContentGates.available_access_rules[ slug ];
	const options = useAccessRuleOptions()[ slug ] ?? [];

	if ( ! rule || rule.is_boolean ) {
		return null;
	}
	if ( 'one_time_purchase' === slug ) {
		return <OneTimePurchaseRuleControl value={ value } onChange={ onChange } options={ options } TokenField={ FormTokenField } />;
	}
	if ( isOptionBackedAccessRule( slug, rule.options ?? [], rule.has_options ) ) {
		const selected = Array.isArray( value ) ? value : [];
		// The picker can hold no token for a value that isn't an option, so on its own
		// it would read as "no constraint" — the opposite of what the stored value
		// does. Name the value, so the operator can replace it rather than guess why
		// the rule looks empty.
		const malformedValueNotice = isMalformedAccessRuleValue( rule, value )
			? sprintf(
					// translators: %s: the stored value.
					__(
						'The saved value “%s” is not one of this rule’s options, so the rule grants no access. Pick from the list to replace it.',
						'newspack-plugin'
					),
					String( value )
			  )
			: undefined;
		// Only for a rule whose empty value means "no constraint": nothing to select
		// leaves the picker able to produce only that value, which grants every
		// reader. Say so and take the field out of play, rather than offering a
		// choice that opens the gate. A rule that still constrains when empty —
		// `subscription`, which then requires any active subscription — keeps a
		// working picker.
		const hasNothingToSelect = !! rule.empty_grants_access && 0 === options.length;
		// The same state with something to pick is the operator's to fix, so the
		// picker stays usable and only says what the value does today. The save is
		// refused while it stands, and a notice here is what stops that being the
		// first they hear of it.
		const grantsEveryoneNotice = hasNothingToSelect
			? __(
					'This rule has nothing to select yet, so it grants access to everyone. Add the items it selects, or turn the rule off.',
					'newspack-plugin'
			  )
			: __(
					'Nothing is selected, so this rule grants access to everyone. Select at least one option, or turn the rule off.',
					'newspack-plugin'
			  );
		const unconstrainedNotice =
			! malformedValueNotice && ( hasNothingToSelect || isUnconstrainedAccessRuleValue( rule, value ) ) ? grantsEveryoneNotice : undefined;
		return (
			<>
				<FormTokenField
					hideLabelFromVision
					label={ rule.name }
					disabled={ hasNothingToSelect }
					description={ malformedValueNotice ?? unconstrainedNotice ?? __( 'Search by name or ID.', 'newspack-plugin' ) }
					value={ getAccessRuleOptionTokens( options, selected, getMissingOptionLabel( slug ) ) }
					onChange={ ( tokens: ( string | TokenItem )[] ) =>
						onChange( resolveAccessRuleOptionTokens( tokens, options, { slug, stored: selected } ) )
					}
					suggestions={ options.map( formatAccessRuleOptionLabel ) }
					maxSuggestions={ MAX_OPTION_SUGGESTIONS }
					messages={ getAccessRuleTokenFieldMessages( slug ) }
					__experimentalValidateInput={ ( input: string ) => isAccessRuleOptionInput( input, options, slug ) }
					__experimentalAutoSelectFirstMatch
					__experimentalExpandOnFocus
					__next40pxDefaultSize
				/>
				{ /* A value of the wrong shape is named above; this names stored IDs the
				     list cannot describe, which is a different state and can coexist with
				     none of the others. */ }
				<UnlistedValuesNotice slug={ slug } options={ options } value={ selected } />
			</>
		);
	}
	return (
		<TextControl
			hideLabelFromVision
			label={ rule.name }
			help={
				isUnconstrainedAccessRuleValue( rule, value )
					? __( 'Left empty, this rule grants access to everyone. Enter at least one value, or turn the rule off.', 'newspack-plugin' )
					: __( 'Separate with commas.', 'newspack-plugin' )
			}
			value={ value as string }
			onChange={ onChange }
			__next40pxDefaultSize
		/>
	);
}
