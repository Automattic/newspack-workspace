/**
 * Fast Checkout block — editor component.
 */

import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { debounce } from 'lodash';
import { InnerBlocks, InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { useSelect, useDispatch } from '@wordpress/data';
import { PanelBody, BaseControl, TextControl, Button, Spinner, FormTokenField, SelectControl, Placeholder } from '@wordpress/components';

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

	const fetchSuggestions = debounce( ( search: string ) => {
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
	}, 200 );

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

interface EditProps {
	attributes: FastCheckoutAttributes;
	setAttributes: ( attrs: Partial< FastCheckoutAttributes > ) => void;
	clientId: string;
}

export default function Edit( { attributes, setAttributes, clientId }: EditProps ) {
	const { product, variation, is_variable: isVariable, afterSuccessURL } = attributes;
	const blockProps = useBlockProps();
	const { updateBlockAttributes } = useDispatch( 'core/block-editor' );

	// On first insert, find the checkout-actions-block and disable "Return to Cart".
	const allDescendants = useSelect(
		select => {
			const getBlocks = ( select( 'core/block-editor' ) as { getBlocks: ( id?: string ) => Block[] } ).getBlocks;
			const walk = ( blocks: Block[] ): Block[] => blocks.flatMap( b => [ b, ...walk( b.innerBlocks ) ] );
			return walk( getBlocks( clientId ) );
		},
		[ clientId ]
	);
	useEffect( () => {
		allDescendants
			.filter( b => b.name === 'woocommerce/checkout-actions-block' && b.attributes.showReturnToCart )
			.forEach( b => updateBlockAttributes( b.clientId, { showReturnToCart: false } ) );
	}, [ allDescendants.length ] );

	// Detect variable product when product changes.
	useEffect( () => {
		if ( ! product ) {
			return;
		}
		apiFetch< StoreApiProduct >( { path: `/wc/store/v1/products/${ product }` } )
			.then( p => {
				setAttributes( { is_variable: !! p?.variations?.length } );
			} )
			.catch( () => setAttributes( { is_variable: false } ) );
	}, [ product ] );

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
								is_variable: false,
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
								is_variable: false,
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
