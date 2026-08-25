/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { TextControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { FormTokenField } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import OneTimePurchaseRuleControl, { getProductTokens } from '../../../../../content-gate/components/one-time-purchase-rule-control';

type RuleOption = { value: string | number; label: string };

interface DynamicRuleConfig< T > {
	path: string;
	mapItem: ( item: T ) => RuleOption;
}

function dynamicRule< T >( config: DynamicRuleConfig< T > ): DynamicRuleConfig< T > {
	return config;
}

/**
 * Rules whose options should be fetched dynamically via the REST API.
 */
const DYNAMIC_OPTION_RULES: Record< string, DynamicRuleConfig< any > > = {
	institution: dynamicRule< Institution >( {
		path: '/wp/v2/np_institution?per_page=100&context=edit',
		mapItem: item => ( { value: item.id, label: item.title.raw } ),
	} ),
};

/**
 * Return options for a rule, fetching dynamically when configured.
 */
function useRuleOptions( slug: string ) {
	const rule = window.newspackAudienceContentGates.available_access_rules[ slug ];
	const [ options, setOptions ] = useState< RuleOption[] >( rule?.options ?? [] );
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	useEffect( () => {
		const config = DYNAMIC_OPTION_RULES[ slug ];
		if ( ! config ) {
			return;
		}
		let cancelled = false;
		apiFetch< any[] >( { path: config.path } ) // eslint-disable-line @typescript-eslint/no-explicit-any
			.then( items => {
				if ( ! cancelled ) {
					setOptions( items.map( config.mapItem ) );
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
		const selectedValues = Array.isArray( value ) ? ( value as Array< string | number > ) : [];
		// FormTokenField round-trips a selection as its display string, so two options
		// sharing a label would resolve one selection to both of their IDs. Variations
		// are named after their parent, which makes that ordinary rather than rare.
		const { tokenByValue, valueByToken } = getProductTokens( options, selectedValues );
		return (
			<FormTokenField
				label={ '' }
				value={ selectedValues.map( v => tokenByValue.get( String( v ) ) as string ) }
				onChange={ ( tokens: ( string | { value: string } )[] ) =>
					onChange(
						tokens
							.map( token => valueByToken.get( typeof token === 'string' ? token : token.value ) )
							.filter( ( v ): v is string | number => undefined !== v )
					)
				}
				suggestions={ options.map( o => tokenByValue.get( String( o.value ) ) as string ) }
				__experimentalValidateInput={ ( token: string ) => valueByToken.has( token ) }
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
