/**
 * Placement Control Component.
 */

/**
 * WordPress dependencies
 */
import { Notice, SelectControl, TextControl } from '@wordpress/components';
import { Fragment, useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { AdBidder, AdProvider, AdSize, PlacementValue } from './types';

/**
 * Get select options from object of ad units.
 *
 * @param providers List of providers.
 * @return Providers options for select control.
 */
const getProvidersForSelect = ( providers: AdProvider[] ) => {
	return [
		{
			label: __( 'Select a provider', 'newspack-ads' ),
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
 * @param provider Provider object.
 * @return Ad unit options for select control.
 */
const getProviderUnitsForSelect = ( provider?: AdProvider ) => {
	if ( ! provider?.units ) {
		return [];
	}
	return [
		{
			label: __( 'Select an Ad Unit', 'newspack-ads' ),
			value: '',
		},
		...provider.units.map( unit => {
			return {
				label: unit.name,
				value: unit.value,
			};
		} ),
	];
};

/**
 * Whether any `sizesToCheck` size exists in `sizes`.
 *
 * @param sizes        Array of sizes.
 * @param sizesToCheck Array of sizes to check.
 * @return Whether any size was found.
 */
const hasAnySize = ( sizes: AdSize[] | undefined, sizesToCheck: AdSize[] ) => {
	return sizesToCheck.some( sizeToCheck => {
		return ( sizes || [] ).find( size => size[ 0 ] === sizeToCheck[ 0 ] && size[ 1 ] === sizeToCheck[ 1 ] );
	} );
};

interface PlacementControlProps {
	label?: string;
	providers?: AdProvider[];
	bidders?: Record< string, AdBidder >;
	value?: PlacementValue;
	disabled?: boolean;
	onChange?: ( value: PlacementValue ) => void;
}

const PlacementControl = ( {
	label = __( 'Ad Unit', 'newspack' ),
	providers = [],
	bidders = {},
	value = {},
	disabled = false,
	onChange = () => {},
}: PlacementControlProps ) => {
	const [ biddersErrors, setBiddersErrors ] = useState< Record< string, string | null > >( {} );

	// Default provider is GAM or first index if GAM is not active.
	const placementProvider = providers.find( provider => provider?.id === ( value.provider || 'gam' ) ) || providers[ 0 ];

	useEffect( () => {
		const errors: Record< string, string | null > = {};
		Object.keys( bidders ).forEach( bidderKey => {
			const bidder = bidders[ bidderKey ];
			// NOTE: pre-existing -- `.units` isn't guarded here (unlike `getProviderUnitsForSelect`
			// above), so this throws at runtime if a provider without `units` reaches this point.
			// The non-null assertion preserves that behavior rather than adding a new guard.
			const unit = placementProvider?.units!.find( u => u.value === value.ad_unit );
			const supported = value.ad_unit && unit && hasAnySize( bidder.ad_sizes, unit.sizes );
			errors[ bidderKey ] =
				! value.ad_unit || ! unit || supported
					? null
					: // NOTE: pre-existing -- the original call passed an extra unused `''` argument
					  // here (the format string has only one `%s`); dropped since it has no effect
					  // on the rendered output and `sprintf`'s types reject the extra argument.
					  sprintf(
							// translators: %s: Ad bidder name.
							__( '%s does not support the selected ad unit sizes.', 'newspack' ),
							bidder.name
					  );
		} );
		setBiddersErrors( errors );
	}, [ providers, value.ad_unit ] );

	if ( ! providers.length ) {
		// NOTE: pre-existing -- this is `@wordpress/components`' `Notice`, whose API is
		// `children`/`status`, not the `newspack-components` `isWarning`/`noticeText` props
		// used below; those are silently ignored at runtime, so this notice renders with no
		// visible message. Flagging rather than fixing (would change rendered output).
		const noProviderNoticeProps = {
			isWarning: true,
			noticeText: __( 'There is no provider available.', 'newspack-ads' ),
			isDismissible: false,
			children: undefined,
		};
		return <Notice { ...noProviderNoticeProps } />;
	}

	return (
		<Fragment>
			{ providers.length > 1 && (
				<SelectControl
					label={ __( 'Provider', 'newspack' ) }
					value={ placementProvider?.id }
					options={ getProvidersForSelect( providers ) }
					onChange={ provider => onChange( { ...value, provider } ) }
					disabled={ disabled }
				/>
			) }
			<SelectControl
				label={ label }
				value={ value.ad_unit }
				options={ getProviderUnitsForSelect( placementProvider ) }
				onChange={ data =>
					onChange( {
						...value,
						ad_unit: data,
					} )
				}
				disabled={ disabled }
			/>
			{ placementProvider?.id === 'gam' &&
				Object.keys( bidders ).map( bidderKey => {
					const bidder = bidders[ bidderKey ];
					// translators: %s: Bidder name.
					const bidderLabel = sprintf( __( '%s Placement ID', 'newspack-ads' ), bidder.name );
					return (
						<TextControl
							key={ bidderKey }
							// NOTE: pre-existing -- passes `null` here when unset; `TextControl`'s
							// `value` type is `string | number` (no `null`), so this is cast rather
							// than coalesced to `''` to avoid changing the rendered value.
							value={ ( value.bidders_ids ? value.bidders_ids[ bidderKey ] : null ) as string }
							label={ bidderLabel }
							// NOTE: pre-existing -- intentionally forwards the error message string
							// (not just a boolean) so the `disabled` attribute also carries the message;
							// `TextControl`'s `disabled` type is `boolean`, hence the cast.
							disabled={ ( biddersErrors[ bidderKey ] || disabled ) as boolean }
							onChange={ data =>
								onChange( {
									...value,
									bidders_ids: {
										...value.bidders_ids,
										[ bidderKey ]: data,
									},
								} )
							}
						/>
					);
				} ) }
			{ placementProvider?.id === 'gam' &&
				Object.keys( biddersErrors ).map( bidderKey => {
					if ( biddersErrors[ bidderKey ] ) {
						return (
							<Notice key={ bidderKey } status="warning" isDismissible={ false }>
								{ biddersErrors[ bidderKey ] }
							</Notice>
						);
					}
					return null;
				} ) }
		</Fragment>
	);
};

export default PlacementControl;
