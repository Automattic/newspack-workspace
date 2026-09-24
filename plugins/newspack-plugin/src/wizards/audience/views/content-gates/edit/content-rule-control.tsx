/**
 * Content Gate component.
 */

/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	__experimentalToggleGroupControl as ToggleGroupControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';

/**
 * Internal dependencies
 */
import ContentRuleControlTokenField from './content-rule-control-tokenfield';
import { Grid } from '../../../../../../packages/components/src';

export default function ContentRuleControl( { slug, value, exclusion, onChange, onChangeExclusion, isStatic = false }: GateRuleControlProps ) {
	const rule = window.newspackAudienceContentGates.available_content_rules[ slug ];
	if ( ! rule || ! Array.isArray( value ) ) {
		return null;
	}
	if ( isStatic ) {
		return rule.options && rule.options.length > 0 ? (
			<p>
				<strong>
					{ sprintf(
						// translators: 1: rule name, 2: includes or excludes
						__( '%1$s %2$s:', 'newspack-plugin' ),
						rule.name,
						exclusion && ( slug !== 'newsletters' || value?.length )
							? __( 'exclude', 'newspack-plugin' )
							: __( 'include', 'newspack-plugin' )
					) }
				</strong>{ ' ' }
				{ slug === 'newsletters' && ! value?.length && __( 'All lists', 'newspack-plugin' ) }
				{ value.map( v => rule.options?.find( option => option.value === v )?.label ).join( ', ' ) }
			</p>
		) : (
			<ContentRuleControlTokenField slug={ slug } value={ value } exclusion={ exclusion } onChange={ onChange } isStatic={ true } />
		);
	}
	return (
		<VStack className="newspack-content-gates__content-rule-control" spacing={ 4 }>
			{ ! rule.include_only && (
				<ToggleGroupControl
					label={ __( 'Mode', 'newspack-plugin' ) }
					value={ exclusion ? 'exclude' : 'include' }
					onChange={ () => onChangeExclusion?.( exclusion ? false : true ) }
					hideLabelFromVision
					isBlock
					__next40pxDefaultSize
				>
					<ToggleGroupControlOption label={ __( 'Include', 'newspack-plugin' ) } value="include" />
					<ToggleGroupControlOption label={ __( 'Exclude', 'newspack-plugin' ) } value="exclude" />
				</ToggleGroupControl>
			) }
			{ rule.options && rule.options.length > 0 ? (
				<Grid columns={ 2 } gutter={ 8 }>
					{ ( rule.options || [] ).map( ( option: { value: string; label: string; help?: string } ) => (
						<CheckboxControl
							key={ option.value }
							label={ option.label }
							help={ option.help }
							checked={ value.includes( option.value ) }
							onChange={ () =>
								onChange( value.includes( option.value ) ? value.filter( v => v !== option.value ) : [ ...value, option.value ] )
							}
						/>
					) ) }
				</Grid>
			) : (
				<ContentRuleControlTokenField slug={ slug } value={ value } onChange={ onChange } exclusion={ exclusion } />
			) }
		</VStack>
	);
}
