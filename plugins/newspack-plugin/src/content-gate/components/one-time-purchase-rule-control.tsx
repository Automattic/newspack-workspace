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
import { __, sprintf } from '@wordpress/i18n';
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

/**
 * FormTokenField identifies a token by its display string, so two products
 * sharing a name would be indistinguishable — selecting one token would select
 * every ID carrying that name. Build a bijection between product IDs and token
 * strings instead, appending the ID to any name that isn't unique. A stored ID
 * absent from the options list (a variation, or a deleted product) keeps a
 * `#<id>` token so editing the field doesn't silently drop it.
 *
 * Uniqueness is enforced against every token handed out, not just against names
 * that repeat: a product named `Annual Pass (#60)` or `#123` would otherwise
 * collide with a token generated for another product and steal its ID.
 */
export function getProductTokens( options: RuleOption[], productIds: Array< string | number > ) {
	const nameCounts = new Map< string, number >();
	options.forEach( option => nameCounts.set( option.label, ( nameCounts.get( option.label ) ?? 0 ) + 1 ) );

	const tokenByValue = new Map< string, string >();
	const valueByToken = new Map< string, string | number >();
	/**
	 * Claim the first free token for a product: its preferred string, then the
	 * ID-disambiguated one, then that plus a counter for the pathological case
	 * where a product is literally named like a token we already handed out.
	 */
	const addToken = ( value: string | number, preferred: string, disambiguated: string ) => {
		let token = preferred;
		let attempt = 1;
		while ( valueByToken.has( token ) ) {
			token = 1 === attempt ? disambiguated : `${ disambiguated } (${ attempt })`;
			attempt++;
		}
		tokenByValue.set( String( value ), token );
		valueByToken.set( token, value );
	};

	options.forEach( option => {
		const disambiguated = sprintf(
			// translators: 1: product name, 2: product ID.
			__( '%1$s (#%2$s)', 'newspack-plugin' ),
			option.label,
			String( option.value )
		);
		const isAmbiguous = 1 < ( nameCounts.get( option.label ) ?? 0 );
		addToken( option.value, isAmbiguous ? disambiguated : option.label, disambiguated );
	} );
	productIds.forEach( productId => {
		if ( ! tokenByValue.has( String( productId ) ) ) {
			addToken( productId, `#${ productId }`, `#${ productId }` );
		}
	} );

	return { tokenByValue, valueByToken };
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
	const { tokenByValue, valueByToken } = getProductTokens( options, currentValue.product_ids );
	const selectedTokens = currentValue.product_ids.map( productId => tokenByValue.get( String( productId ) ) as string );

	let durationHelp: string = __( 'How long a purchase grants access, counted from the order date.', 'newspack-plugin' );
	if ( 'forever' === currentValue.duration_unit ) {
		durationHelp = __( 'Purchasers keep access forever.', 'newspack-plugin' );
	} else if ( '' === currentValue.duration_unit ) {
		durationHelp = __( 'The stored duration is invalid and grants no access. Pick a duration to fix this rule.', 'newspack-plugin' );
	}

	return (
		<>
			<TokenField
				label={ productsLabel }
				value={ selectedTokens }
				suggestions={ options.map( option => tokenByValue.get( String( option.value ) ) as string ) }
				onChange={ ( tokens: ( string | { value: string } )[] ) => {
					onChange( {
						...currentValue,
						product_ids: tokens
							.map( token => valueByToken.get( typeof token === 'string' ? token : token.value ) )
							.filter( ( productId ): productId is string | number => undefined !== productId ),
					} );
				} }
				// FormTokenField accepts free-typed tokens by default, and one that
				// isn't a known product would map to nothing and silently vanish on
				// the next render. Reject it at entry instead.
				__experimentalValidateInput={ ( token: string ) => valueByToken.has( token ) }
				__experimentalExpandOnFocus
				__next40pxDefaultSize
				__nextHasNoMarginBottom
			/>
			<Flex align="flex-start" gap={ 2 } style={ { marginTop: '8px' } }>
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
