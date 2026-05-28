/**
 * Placement Control Component.
 */

/**
 * WordPress dependencies
 */
import { Fragment, useState, useEffect, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { __experimentalVStack as VStack } from '@wordpress/components'; // eslint-disable-line @wordpress/no-unsafe-wp-apis

/**
 * Internal dependencies
 */
import { Notice, SelectControl, TextControl } from '../../../../../packages/components/src';

/**
 * Get select options from object of ad units.
 *
 * @param {Array} providers List of providers.
 * @return {Array} Providers options for select control.
 */
const getProvidersForSelect = providers => {
	return [
		{
			label: __( 'Select a provider', 'newspack-plugin' ),
			value: '',
		},
		...providers.map( unit => {
			return {
				label: unit.name,
				value: unit.id,
			};
		} ),
	];
};

/**
 * Get select options from object of ad units.
 *
 * @param {Object} provider Provider object.
 * @return {Array} Ad unit options for select control.
 */
const getProviderUnitsForSelect = provider => {
	if ( ! provider?.units ) {
		return [];
	}
	return [
		{
			label: __( 'Select an Ad Unit', 'newspack-plugin' ),
			value: '',
		},
		...provider.units.map( unit => {
			return {
				label: sprintf(
					// Translators: 1 is ad unit name and 2 is ad unit id.
					__( '%1$s (%2$s)', 'newspack-plugin' ),
					unit.name,
					unit.value
				),
				value: unit.value,
			};
		} ),
	];
};

/**
 * Whether any `sizesToCheck` size exists in `sizes`.
 *
 * @param {Array} sizes        Array of sizes.
 * @param {Array} sizesToCheck Array of sizes to check.
 * @return {boolean} Whether any size was found.
 */
const hasAnySize = ( sizes, sizesToCheck ) => {
	return sizesToCheck.some( sizeToCheck => {
		return ( sizes || [] ).find( size => size[ 0 ] === sizeToCheck[ 0 ] && size[ 1 ] === sizeToCheck[ 1 ] );
	} );
};

const PlacementControl = ( {
	label = __( 'Ad Unit', 'newspack-plugin' ),
	providers = [],
	bidders = {},
	value = {},
	disabled = false,
	onChange,
	...props
} ) => {
	const [ biddersErrors, setBiddersErrors ] = useState( {} );

	// Ensure incoming value is available otherwise reset to empty values.
	const showProviderSelect = providers.length > 1;
	const placementProvider = useMemo(
		() => ( value.provider ? providers.find( provider => provider?.id === value.provider ) : null ),
		[ providers, value.provider ]
	);
	const effectiveProvider = showProviderSelect ? placementProvider : providers[ 0 ];
	const placementAdUnit = useMemo(
		() => ( value.ad_unit ? ( effectiveProvider?.units || [] ).find( u => u.value === value.ad_unit ) : null ),
		[ effectiveProvider, value.ad_unit ]
	);

	useEffect( () => {
		const errors = {};
		Object.keys( bidders ).forEach( bidderKey => {
			const bidder = bidders[ bidderKey ];
			const unit = placementAdUnit;
			const supported = placementAdUnit && unit && hasAnySize( bidder.ad_sizes, unit.sizes );
			errors[ bidderKey ] =
				! placementAdUnit || ! unit || supported
					? null
					: sprintf(
							// translators: %s: ad bidder name.
							__( '%s does not support the selected ad unit sizes.', 'newspack-plugin' ),
							bidder.name,
							''
					  );
		} );
		setBiddersErrors( errors );
	}, [ placementProvider, placementAdUnit, bidders ] );

	if ( ! providers.length ) {
		return <Notice isWarning noticeText={ __( 'There is no provider available.', 'newspack-plugin' ) } />;
	}

	return (
		<Fragment>
			<VStack spacing={ 4 }>
				{ showProviderSelect && (
					<SelectControl
						label={ __( 'Provider', 'newspack-plugin' ) }
						value={ placementProvider ? placementProvider.id : '' }
						options={ getProvidersForSelect( providers ) }
						onChange={ provider => onChange( { ...value, provider } ) }
						disabled={ disabled }
					/>
				) }
				<SelectControl
					label={ label }
					value={ placementAdUnit ? placementAdUnit.value : '' }
					options={ getProviderUnitsForSelect( effectiveProvider ) }
					onChange={ data => {
						onChange( {
							...value,
							ad_unit: data,
							...( ! showProviderSelect && { provider: effectiveProvider?.id } ),
						} );
					} }
					disabled={ disabled }
					{ ...props }
				/>
				{ effectiveProvider?.id === 'gam' &&
					Object.keys( bidders ).map( bidderKey => {
						const bidder = bidders[ bidderKey ];
						// translators: %s: bidder name.
						const bidderLabel = sprintf( __( '%s Placement ID', 'newspack-plugin' ), bidder.name );
						return (
							<TextControl
								key={ bidderKey }
								value={ value.bidders_ids ? value.bidders_ids[ bidderKey ] : null }
								label={ bidderLabel }
								disabled={ biddersErrors[ bidderKey ] || disabled }
								onChange={ data => {
									onChange( {
										...value,
										bidders_ids: {
											...value.bidders_ids,
											[ bidderKey ]: data,
										},
									} );
								} }
								{ ...props }
							/>
						);
					} ) }
				{ effectiveProvider?.id === 'gam' &&
					Object.keys( biddersErrors ).map( bidderKey => {
						if ( biddersErrors[ bidderKey ] ) {
							return (
								<Notice key={ bidderKey } isWarning>
									{ biddersErrors[ bidderKey ] }
								</Notice>
							);
						}
						return null;
					} ) }
			</VStack>
		</Fragment>
	);
};

export default PlacementControl;
