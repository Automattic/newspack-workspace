/**
 * Fast Checkout block — editor component.
 */

import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { debounce } from 'lodash';
import { InnerBlocks, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { PanelBody, BaseControl, TextControl, Button, Spinner, FormTokenField, SelectControl, Placeholder, Notice } from '@wordpress/components';

import { DEFAULT_TEMPLATE } from './template';
import { fetchProduct } from './bindings-source';
import type { FastCheckoutAttributes, StoreApiProduct, StoreApiVariation, Variation } from './types';

interface ProductPickerProps {
	productId?: string;
	onChange: ( id: string ) => void;
}

function ProductPicker( { productId, onChange }: ProductPickerProps ) {
	const [ suggestions, setSuggestions ] = useState< Record< string, string > >( {} );
	const [ inFlight, setInFlight ] = useState( false );
	const [ selectedName, setSelectedName ] = useState( '' );
	const [ isChanging, setIsChanging ] = useState( ! productId );

	useEffect( () => {
		if ( ! productId ) {
			setSelectedName( '' );
			return;
		}
		fetchProduct( productId ).then( product => {
			setSelectedName( product?.name || '' );
		} );
	}, [ productId ] );

	// Keep the debounced function stable across renders so its timer isn't
	// recreated on every render (which would defeat debouncing and leak timers).
	const fetchSuggestions = useMemo(
		() =>
			debounce( ( search: string ) => {
				if ( search.length < 3 ) {
					return;
				}
				setInFlight( true );
				apiFetch< StoreApiProduct[] >( {
					path: `/wc/store/v1/products?search=${ encodeURIComponent( search ) }&per_page=10`,
				} )
					.then( products => {
						const next: Record< string, string > = {};
						products.forEach( p => {
							next[ p.id ] = p.name;
						} );
						setSuggestions( next );
					} )
					.finally( () => setInFlight( false ) );
			}, 200 ),
		[]
	);

	// Cancel any pending debounced request on unmount.
	useEffect( () => () => fetchSuggestions.cancel(), [ fetchSuggestions ] );

	if ( productId && ! isChanging ) {
		return (
			<BaseControl label={ __( 'Product', 'newspack-blocks' ) } id="fast-checkout-product">
				<div>{ selectedName || <Spinner /> }</div>
				<Button variant="link" onClick={ () => setIsChanging( true ) }>
					{ __( 'Change', 'newspack-blocks' ) }
				</Button>
			</BaseControl>
		);
	}

	return (
		<BaseControl label={ __( 'Product', 'newspack-blocks' ) } id="fast-checkout-product">
			<FormTokenField
				label=""
				maxLength={ 1 }
				placeholder={ __( 'Type to search for a product…', 'newspack-blocks' ) }
				suggestions={ Object.values( suggestions ) }
				onInputChange={ fetchSuggestions }
				onChange={ ( tokens: string[] ) => {
					const tokenName = tokens[ 0 ];
					const id = Object.keys( suggestions ).find( key => suggestions[ key ] === tokenName );
					if ( id ) {
						onChange( String( id ) );
						setIsChanging( false );
					}
				} }
			/>
			{ inFlight && <Spinner /> }
		</BaseControl>
	);
}

interface VariationPickerProps {
	productId: string;
	variationId?: string;
	onChange: ( variationId: string ) => void;
}

function VariationPicker( { productId, variationId, onChange }: VariationPickerProps ) {
	const [ variations, setVariations ] = useState< Variation[] >( [] );

	useEffect( () => {
		if ( ! productId ) {
			setVariations( [] );
			return;
		}
		apiFetch< StoreApiVariation[] >( { path: `/wc/v2/products/${ productId }/variations?per_page=100` } )
			.then( res => {
				setVariations(
					res.map( v => ( {
						id: v.id,
						label: v.attributes.map( a => `${ a.name }: ${ a.option }` ).join( ', ' ),
					} ) )
				);
			} )
			.catch( () => setVariations( [] ) );
	}, [ productId ] );

	if ( ! variations.length ) {
		return null;
	}

	return (
		<SelectControl
			label={ __( 'Variation', 'newspack-blocks' ) }
			value={ variationId || '' }
			options={ [
				{ label: __( 'Choose a variation…', 'newspack-blocks' ), value: '' },
				...variations.map( v => ( { label: v.label, value: String( v.id ) } ) ),
			] }
			onChange={ onChange }
		/>
	);
}

interface GroupedChildPickerProps {
	productId: string;
	childId?: string;
	onChange: ( id: string ) => void;
}

function GroupedChildPicker( { productId, childId, onChange }: GroupedChildPickerProps ) {
	const [ children, setChildren ] = useState< { id: number; name: string }[] >( [] );

	useEffect( () => {
		if ( ! productId ) {
			setChildren( [] );
			return;
		}
		apiFetch< StoreApiProduct >( { path: `/wc/store/v1/products/${ productId }` } )
			.then( product => {
				const ids = product?.grouped_products || [];
				if ( ! ids.length ) {
					setChildren( [] );
					return;
				}
				return Promise.all(
					ids.map( id => apiFetch< StoreApiProduct >( { path: `/wc/store/v1/products/${ id }` } ).catch( () => null ) )
				).then( results => {
					setChildren( results.filter( ( c ): c is StoreApiProduct => Boolean( c ) ).map( c => ( { id: c.id, name: c.name } ) ) );
				} );
			} )
			.catch( () => setChildren( [] ) );
	}, [ productId ] );

	if ( ! children.length ) {
		return null;
	}

	return (
		<SelectControl
			label={ __( 'Default child product', 'newspack-blocks' ) }
			help={ __( 'Pre-select a child for readers; leave blank to use the first child.', 'newspack-blocks' ) }
			value={ childId || '' }
			options={ [
				{ label: __( 'First child (default)', 'newspack-blocks' ), value: '' },
				...children.map( c => ( { label: c.name, value: String( c.id ) } ) ),
			] }
			onChange={ onChange }
		/>
	);
}

interface NypDefaultPriceProps {
	productId: string;
	value?: string;
	onChange: ( value: string ) => void;
}

function NypDefaultPrice( { productId, value, onChange }: NypDefaultPriceProps ) {
	const [ help, setHelp ] = useState< string >( '' );

	useEffect( () => {
		if ( ! productId ) {
			return;
		}
		apiFetch< StoreApiProduct >( { path: `/wc/store/v1/products/${ productId }` } )
			.then( product => {
				const nyp = product?.extensions?.name_your_price;
				if ( ! nyp?.is_nyp ) {
					setHelp( '' );
					return;
				}
				const min = nyp.minimum_price;
				const max = nyp.maximum_price;
				const suggested = nyp.suggested_price;
				const parts: string[] = [];
				if ( min && max ) {
					parts.push(
						/* translators: 1: min price, 2: max price */
						__( 'Allowed: %1$s – %2$s.', 'newspack-blocks' ).replace( '%1$s', min ).replace( '%2$s', max )
					);
				}
				if ( suggested ) {
					parts.push(
						/* translators: %s: suggested price */
						__( 'Suggested: %s.', 'newspack-blocks' ).replace( '%s', suggested )
					);
				}
				setHelp( parts.join( ' ' ) || __( 'Reader can set any amount.', 'newspack-blocks' ) );
			} )
			.catch( () => setHelp( '' ) );
	}, [ productId ] );

	return (
		<TextControl
			label={ __( 'Default amount (override)', 'newspack-blocks' ) }
			help={ help || __( "Leave blank to use the product's suggested amount.", 'newspack-blocks' ) }
			value={ value || '' }
			onChange={ onChange }
		/>
	);
}

interface Block {
	name: string;
	clientId: string;
	attributes: Record< string, unknown >;
	innerBlocks: Block[];
}

interface EditProps {
	attributes: FastCheckoutAttributes;
	setAttributes: ( attrs: Partial< FastCheckoutAttributes > ) => void;
	clientId: string;
}

export default function Edit( { attributes, setAttributes, clientId }: EditProps ) {
	const { product, variation, is_variable: isVariable, afterSuccessURL } = attributes;
	const [ groupedWarning, setGroupedWarning ] = useState< string >( '' );
	const blockProps = useBlockProps();
	const { updateBlockAttributes, removeBlocks } = useDispatch( 'core/block-editor' );

	// On first insert, find the checkout-actions-block and disable "Return to Cart".
	const allDescendants = useSelect(
		select => {
			const getBlocks = ( select( 'core/block-editor' ) as { getBlocks: ( id?: string ) => Block[] } ).getBlocks;
			const walk = ( blocks: Block[] ): Block[] => blocks.flatMap( b => [ b, ...walk( b.innerBlocks ) ] );
			return walk( getBlocks( clientId ) );
		},
		[ clientId ]
	);

	// Direct children of this block — used for selector auto-insert/auto-clean.
	const innerBlocks = useSelect(
		select => ( select( 'core/block-editor' ) as { getBlocks: ( id: string ) => Block[] } ).getBlocks( clientId ),
		[ clientId ]
	);

	useEffect( () => {
		allDescendants
			.filter( b => b.name === 'woocommerce/checkout-actions-block' && b.attributes.showReturnToCart )
			.forEach( b => updateBlockAttributes( b.clientId, { showReturnToCart: false } ) );
	}, [ allDescendants.length ] );

	// Detect product type and set flags when product changes.
	useEffect( () => {
		if ( ! product ) {
			return;
		}
		apiFetch< StoreApiProduct >( { path: `/wc/store/v1/products/${ product }` } )
			.then( p => {
				const flags = {
					is_variable: !! p?.variations?.length,
					is_grouped: p?.type === 'grouped' || !! p?.grouped_products?.length,
					is_nyp: !! p?.extensions?.name_your_price?.is_nyp,
				};
				setAttributes( flags );

				if ( flags.is_grouped && p?.grouped_products?.length ) {
					Promise.all(
						p.grouped_products.map( id => apiFetch< StoreApiProduct >( { path: `/wc/store/v1/products/${ id }` } ).catch( () => null ) )
					).then( children => {
						const unsupported = children.filter(
							( c ): c is StoreApiProduct => !! c && ( ( c.variations?.length ?? 0 ) > 0 || !! c.extensions?.name_your_price?.is_nyp )
						);
						if ( unsupported.length ) {
							setGroupedWarning(
								__(
									'This grouped product contains variable or Name Your Price children. Fast Checkout treats children as simple products; those types are not supported.',
									'newspack-blocks'
								)
							);
						} else {
							setGroupedWarning( '' );
						}
					} );
				} else {
					setGroupedWarning( '' );
				}
			} )
			.catch( () => {
				setAttributes( { is_variable: false, is_grouped: false, is_nyp: false } );
				setGroupedWarning( '' );
			} );
	}, [ product ] );

	// Track previous type flags so we only react to transitions, not every render.
	const previousFlags = useRef< { v: boolean; g: boolean; n: boolean } >( {
		v: !! attributes.is_variable,
		g: !! attributes.is_grouped,
		n: !! attributes.is_nyp,
	} );

	// On product type transitions: clean up the donate-selector inner block
	// when the product is no longer grouped, and reset stale type-specific
	// attributes so they don't carry over into the new product context.
	useEffect( () => {
		const prev = previousFlags.current;
		const next = {
			v: !! attributes.is_variable,
			g: !! attributes.is_grouped,
			n: !! attributes.is_nyp,
		};

		if ( prev.v === next.v && prev.g === next.g && prev.n === next.n ) {
			return;
		}

		previousFlags.current = next;

		// Donate-selector is manually inserted; auto-clean on grouped → not-grouped.
		if ( prev.g && ! next.g ) {
			const donateIds = innerBlocks
				.filter( ( b: Block ) => b.name === 'newspack-blocks/fast-checkout-donate-selector' )
				.map( ( b: Block ) => b.clientId );
			if ( donateIds.length ) {
				removeBlocks( donateIds, false );
			}
		}

		// Reset child/price defaults when the product type changes.
		if ( prev.v && ! next.v && attributes.variation ) {
			setAttributes( { variation: '' } );
		}
		if ( prev.g && ! next.g && attributes.grouped_child ) {
			setAttributes( { grouped_child: '' } );
		}
		if ( prev.n && ! next.n && attributes.nyp_price ) {
			setAttributes( { nyp_price: '' } );
		}
	}, [ attributes.is_variable, attributes.is_grouped, attributes.is_nyp, innerBlocks, removeBlocks ] );

	if ( ! product ) {
		return (
			<div { ...blockProps }>
				<Placeholder
					label={ __( 'Fast Checkout', 'newspack-blocks' ) }
					instructions={ __( 'Select a product to create a checkout landing page.', 'newspack-blocks' ) }
				>
					<ProductPicker
						productId={ product }
						onChange={ newId =>
							setAttributes( {
								product: newId,
								variation: '',
								grouped_child: '',
								nyp_price: '',
								is_variable: false,
								is_grouped: false,
								is_nyp: false,
							} )
						}
					/>
				</Placeholder>
			</div>
		);
	}

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Fast Checkout', 'newspack-blocks' ) } initialOpen>
					<ProductPicker
						productId={ product }
						onChange={ newId =>
							setAttributes( {
								product: newId,
								variation: '',
								grouped_child: '',
								nyp_price: '',
								is_variable: false,
								is_grouped: false,
								is_nyp: false,
							} )
						}
					/>
					{ isVariable && (
						<VariationPicker
							productId={ product }
							variationId={ variation }
							onChange={ newVariation => setAttributes( { variation: newVariation } ) }
						/>
					) }
					{ attributes.is_grouped && (
						<GroupedChildPicker
							productId={ product || '' }
							childId={ attributes.grouped_child }
							onChange={ value => setAttributes( { grouped_child: value } ) }
						/>
					) }
					{ attributes.is_nyp && (
						<NypDefaultPrice
							productId={ product || '' }
							value={ attributes.nyp_price }
							onChange={ value => setAttributes( { nyp_price: value } ) }
						/>
					) }
					{ groupedWarning && (
						<Notice status="warning" isDismissible={ false }>
							{ groupedWarning }
						</Notice>
					) }
					<TextControl
						label={ __( 'Post-purchase redirect URL', 'newspack-blocks' ) }
						help={ __( 'Leave blank to use the default WooCommerce order confirmation page.', 'newspack-blocks' ) }
						value={ afterSuccessURL || '' }
						onChange={ value => setAttributes( { afterSuccessURL: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<InnerBlocks template={ DEFAULT_TEMPLATE } templateLock={ false } />
			</div>
		</>
	);
}
