/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { TextControl } from '@wordpress/components';
import type { TokenItem } from '@wordpress/components/build-types/form-token-field/types.d.ts';

/**
 * Internal dependencies
 */
import { FormTokenField } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import {
	formatAccessRuleOptionLabel,
	getAccessRuleOptionTokens,
	getAccessRuleTokenFieldMessages,
	getMissingOptionLabel,
	getUnlistedAccessRuleValuesNotice,
	hasUnlistedAccessRuleValues,
	isAccessRuleOptionInput,
	resolveAccessRuleOptionTokens,
	type AccessRuleOption as RuleOption,
} from '../../../../../content-gate/access-rule-options';
import { getAccessRuleOptionSource } from '../../../../../content-gate/access-rule-option-sources';
import OneTimePurchaseRuleControl from '../../../../../content-gate/components/one-time-purchase-rule-control';

/**
 * Return options for a rule, fetching dynamically when configured.
 */
function useRuleOptions( slug: string ) {
	const rule = window.newspackAudienceContentGates.available_access_rules[ slug ];
	const [ options, setOptions ] = useState< RuleOption[] >( rule?.options ?? [] );
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	useEffect( () => {
		const source = getAccessRuleOptionSource( slug );
		if ( ! source ) {
			return;
		}
		let cancelled = false;
		source()
			.then( fetched => {
				if ( ! cancelled ) {
					setOptions( fetched );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					addNotice( {
						message: __( 'Failed to load options. The list may be outdated.', 'newspack-plugin' ),
						type: 'error',
						id: `rule-options-error-${ slug }`,
					} );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ slug, addNotice ] );

	return options;
}

export default function AccessRuleControl( { slug, value, onChange }: GateRuleControlProps ) {
	const rule = window.newspackAudienceContentGates.available_access_rules[ slug ];
	const options = useRuleOptions( slug );

	if ( ! rule || rule.is_boolean ) {
		return null;
	}
	if ( 'one_time_purchase' === slug ) {
		return <OneTimePurchaseRuleControl value={ value } onChange={ onChange } options={ options } TokenField={ FormTokenField } />;
	}
	if ( options && options.length > 0 ) {
		const selected = Array.isArray( value ) ? value : [];
		const description = hasUnlistedAccessRuleValues( options, selected )
			? getUnlistedAccessRuleValuesNotice()
			: __( 'Search by name or ID.', 'newspack-plugin' );
		return (
			<FormTokenField
				hideLabelFromVision
				label={ rule.name }
				description={ description }
				value={ getAccessRuleOptionTokens( options, selected, getMissingOptionLabel( slug ) ) }
				onChange={ ( tokens: ( string | TokenItem )[] ) => onChange( resolveAccessRuleOptionTokens( tokens, options, selected ) ) }
				suggestions={ options.map( formatAccessRuleOptionLabel ) }
				messages={ getAccessRuleTokenFieldMessages() }
				__experimentalValidateInput={ ( input: string ) => isAccessRuleOptionInput( input, options ) }
				__experimentalAutoSelectFirstMatch
				__experimentalExpandOnFocus
				__next40pxDefaultSize
			/>
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
