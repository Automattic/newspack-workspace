/**
 * One-time purchase access rule control.
 *
 * Shared between the Audience > Access control wizard and the block editor's
 * block-visibility panel: renders the product selector plus the access-duration
 * configuration for the `one_time_purchase` rule. The surfaces use different
 * FormTokenField implementations (Newspack-styled vs. core), so the token field
 * component is injectable.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { Flex, FlexBlock, FormTokenField as CoreFormTokenField, SelectControl, TextControl } from '@wordpress/components';

const DURATION_UNITS = [ 'days', 'months', 'forever' ] as const;

/**
 * '' marks an unrecognized or missing stored unit. The server fails closed on
 * it (the rule never grants), so the UI must not silently coerce it into a
 * granting unit either.
 */
export type OneTimePurchaseDurationUnit = ( typeof DURATION_UNITS )[ number ] | '';

export type OneTimePurchaseValue = {
	product_ids: Array< string | number >;
	duration_value: number;
	duration_unit: OneTimePurchaseDurationUnit;
};

type RuleOption = { value: string | number; label: string };

/**
 * Normalize any stored rule value (including legacy/empty shapes) to the
 * composite one-time purchase value. An unrecognized duration unit maps to ''
 * (invalid, never grants), mirroring the server-side sanitizer.
 */
export function normalizeOneTimePurchaseValue( value: unknown ): OneTimePurchaseValue {
	const raw = ( value && typeof value === 'object' && ! Array.isArray( value ) ? value : {} ) as Partial< OneTimePurchaseValue >;
	return {
		product_ids: Array.isArray( raw.product_ids ) ? raw.product_ids : [],
		duration_value: Number( raw.duration_value ) || 0,
		duration_unit: ( DURATION_UNITS as readonly string[] ).includes( raw.duration_unit as string )
			? ( raw.duration_unit as OneTimePurchaseDurationUnit )
			: '',
	};
}

export default function OneTimePurchaseRuleControl( {
	value,
	onChange,
	options,
	productsLabel = '',
	TokenField = CoreFormTokenField,
}: {
	value: unknown;
	onChange: ( value: OneTimePurchaseValue ) => void;
	options: RuleOption[];
	productsLabel?: string;
	// eslint-disable-next-line @typescript-eslint/no-explicit-any
	TokenField?: React.ComponentType< any >;
} ) {
	const currentValue = normalizeOneTimePurchaseValue( value );
	const isFiniteDuration = 'days' === currentValue.duration_unit || 'months' === currentValue.duration_unit;
	const selectedLabels = options
		.filter( option => currentValue.product_ids.some( id => String( id ) === String( option.value ) ) )
		.map( option => option.label );

	let durationHelp = __( 'How long a purchase grants access, counted from the order date.', 'newspack-plugin' );
	if ( 'forever' === currentValue.duration_unit ) {
		durationHelp = __( 'Purchasers keep access forever.', 'newspack-plugin' );
	} else if ( '' === currentValue.duration_unit ) {
		durationHelp = __( 'The stored duration is invalid and grants no access. Pick a duration to fix this rule.', 'newspack-plugin' );
	}

	return (
		<>
			<TokenField
				label={ productsLabel }
				value={ selectedLabels }
				suggestions={ options.map( option => option.label ) }
				onChange={ ( tokens: ( string | { value: string } )[] ) => {
					const labels = tokens.map( token => ( typeof token === 'string' ? token : token.value ) );
					onChange( {
						...currentValue,
						product_ids: options.filter( option => labels.includes( option.label ) ).map( option => option.value ),
					} );
				} }
				__experimentalExpandOnFocus
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<Flex align="flex-end" gap={ 2 } style={ { marginTop: '8px' } }>
				<FlexBlock>
					<SelectControl
						label={ __( 'Access duration', 'newspack-plugin' ) }
						help={ durationHelp }
						value={ currentValue.duration_unit }
						options={ [
							// Surface an invalid stored unit honestly instead of masking it
							// as a granting choice; selecting any real option clears it.
							...( '' === currentValue.duration_unit
								? [ { value: '', label: __( 'Invalid (grants no access)', 'newspack-plugin' ), disabled: true } ]
								: [] ),
							{ value: 'forever', label: __( 'Forever', 'newspack-plugin' ) },
							{ value: 'days', label: __( 'Days from purchase', 'newspack-plugin' ) },
							{ value: 'months', label: __( 'Months from purchase', 'newspack-plugin' ) },
						] }
						onChange={ ( duration_unit: string ) =>
							onChange( {
								...currentValue,
								duration_unit: duration_unit as OneTimePurchaseDurationUnit,
								// Seed a sane default when switching from "forever" to a finite unit.
								duration_value:
									'forever' === duration_unit ? 0 : currentValue.duration_value || ( 'days' === duration_unit ? 30 : 12 ),
							} )
						}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</FlexBlock>
				{ isFiniteDuration && (
					<FlexBlock>
						<TextControl
							label={
								'days' === currentValue.duration_unit
									? __( 'Number of days', 'newspack-plugin' )
									: __( 'Number of months', 'newspack-plugin' )
							}
							type="number"
							min={ 1 }
							value={ currentValue.duration_value || '' }
							help={
								currentValue.duration_value < 1
									? __( 'Enter a duration of at least 1. Until then, purchases do not grant access.', 'newspack-plugin' )
									: undefined
							}
							onChange={ ( duration_value: string ) =>
								onChange( {
									...currentValue,
									duration_value: Math.max( 0, parseInt( duration_value, 10 ) || 0 ),
								} )
							}
							__next40pxDefaultSize
							__nextHasNoMarginBottom
						/>
					</FlexBlock>
				) }
			</Flex>
		</>
	);
}
