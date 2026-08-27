/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
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
import AccessRuleValueNotice from '../../../../../content-gate/components/access-rule-value-notice';
import OneTimePurchaseRuleControl from '../../../../../content-gate/components/one-time-purchase-rule-control';
import UnlistedValuesNotice from '../../../../../content-gate/components/unlisted-values-notice';
import { getAccessRuleValueNotice, isAccessRulePickerInert, isUnconstrainedAccessRuleValue } from '../utils';
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
		const hasOptions = options.length > 0;
		// Both the caution and the inert state come from the shared module, so this picker
		// and the block editor's reach the same verdict on one stored value.
		const valueNotice = getAccessRuleValueNotice( rule, value, hasOptions );
		return (
			<>
				<AccessRuleValueNotice label={ rule.name } notice={ valueNotice }>
					<FormTokenField
						hideLabelFromVision
						label={ rule.name }
						disabled={ isAccessRulePickerInert( rule, value, hasOptions ) }
						description={ valueNotice ? undefined : __( 'Search by name or ID.', 'newspack-plugin' ) }
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
				</AccessRuleValueNotice>
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
			value={ 'string' === typeof value ? value : '' }
			onChange={ onChange }
			__next40pxDefaultSize
		/>
	);
}
