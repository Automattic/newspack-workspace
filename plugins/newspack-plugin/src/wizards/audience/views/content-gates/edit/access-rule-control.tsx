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
	getAccessRuleTokenFieldMessages,
	getMissingOptionLabel,
	isAccessRuleOptionInput,
	resolveAccessRuleOptionTokens,
} from '../../../../../content-gate/access-rule-options';
import OneTimePurchaseRuleControl from '../../../../../content-gate/components/one-time-purchase-rule-control';
import UnlistedValuesNotice from '../../../../../content-gate/components/unlisted-values-notice';
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
	if ( options.length > 0 ) {
		const selected = Array.isArray( value ) ? value : [];
		return (
			<>
				<FormTokenField
					hideLabelFromVision
					label={ rule.name }
					description={ __( 'Search by name or ID.', 'newspack-plugin' ) }
					value={ getAccessRuleOptionTokens( options, selected, getMissingOptionLabel( slug ) ) }
					onChange={ ( tokens: ( string | TokenItem )[] ) => onChange( resolveAccessRuleOptionTokens( tokens, options, selected ) ) }
					suggestions={ options.map( formatAccessRuleOptionLabel ) }
					messages={ getAccessRuleTokenFieldMessages() }
					__experimentalValidateInput={ ( input: string ) => isAccessRuleOptionInput( input, options ) }
					__experimentalAutoSelectFirstMatch
					__experimentalExpandOnFocus
					__next40pxDefaultSize
				/>
				<UnlistedValuesNotice options={ options } value={ selected } />
			</>
		);
	}
	return (
		<TextControl
			hideLabelFromVision
			label={ rule.name }
			help={ __( 'Separate with commas.', 'newspack-plugin' ) }
			value={ value as string }
			onChange={ onChange }
			__next40pxDefaultSize
		/>
	);
}
