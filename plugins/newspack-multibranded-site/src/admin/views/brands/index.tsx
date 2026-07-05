import React from 'react';
import { Routes, Route, useNavigate } from 'react-router-dom';
import { withWizard } from 'newspack-components';

import { addQueryArgs } from '@wordpress/url';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import BrandsList from './BrandsList';
import Brand from './Brand';
import type { Brand as BrandData, BrandFormState, MediaAttachment } from './types';

/**
 * Injected by the `withWizard` HOC (see `packages/components/src/with-wizard`).
 * This unit's own contract only needs the two members it actually calls.
 */
interface BrandsInjectedProps {
	setError: ( error?: unknown ) => void;
	wizardApiFetch: ( args: Record< string, unknown > ) => Promise< unknown >;
}

const Brands = ( { setError, wizardApiFetch }: BrandsInjectedProps ) => {
	const [ brands, setBrands ] = useState< BrandData[] >( [] );
	const navigate = useNavigate();

	const headerText = __( 'Brands', 'newspack' );
	const subHeaderText = __( 'Configure brands settings', 'newspack' );
	const wizardScreenProps = {
		headerText,
		subHeaderText,
	};

	/**
	 * Fetching brands data.
	 */
	const fetchBrands = () => {
		wizardApiFetch( {
			path: addQueryArgs( '/wp/v2/brand', { per_page: 100 } ),
		} )
			.then( response =>
				setBrands(
					// The REST response isn't typed by `@wordpress/api-fetch`; this is the
					// documented shape of the `/wp/v2/brand` endpoint.
					( response as BrandData[] ).map( brand => ( {
						...brand,
						meta: {
							...brand.meta,
							_theme_colors: 0 === brand.meta._theme_colors?.length ? null : brand.meta._theme_colors,
							_menus: 0 === brand.meta._menus?.length ? null : brand.meta._menus,
						},
					} ) )
				)
			)
			.catch( error => setError( error ) );
	};

	const saveBrand = ( brandId: number, brand: BrandFormState ) => {
		wizardApiFetch( {
			path: brandId ? `/wp/v2/brand/${ brandId }` : '/wp/v2/brand',
			method: 'POST',
			data: {
				...brand,
				meta: {
					...brand.meta,
					// By the time a save happens, a truthy `_logo` has always already been
					// resolved to an attachment object (via `ImageUpload`'s `onChange` or
					// `fetchLogoAttachment`), never the raw numeric ID.
					...( brand.meta._logo && { _logo: ( brand.meta._logo as { id: number } ).id } ),
				},
			},
			quiet: true,
		} )
			.then( result =>
				setBrands( brandsList => {
					// The result from the API call doesn't contain the logo details.
					const newBrand = { id: ( result as { id: number } ).id, ...brand } as BrandData;
					if ( brandId ) {
						const brandIndex = brandsList.findIndex( _brand => brandId === _brand.id );
						if ( brandIndex > -1 ) {
							return brandsList.map( _brand => ( brandId === _brand.id ? newBrand : _brand ) );
						}
					}

					return [ newBrand, ...brandsList ];
				} )
			)
			// NOTE: this calls `navigate( '/' )` eagerly (as soon as `saveBrand` runs), not
			// after the promise above resolves — likely a pre-existing bug in the original
			// JS (probably meant `.then( () => navigate( '/' ) )`), left as-is here.
			.then( navigate( '/' ) as undefined )
			.catch( setError );
	};

	const deleteBrand = ( brand: BrandData ) => {
		// eslint-disable-next-line no-alert
		if ( confirm( __( 'Are you sure you want to delete this brand?', 'newspack' ) ) ) {
			return wizardApiFetch( {
				path: addQueryArgs( `/wp/v2/brand/${ brand.id }`, { force: true } ),
				method: 'DELETE',
				quiet: true,
			} )
				.then( result => {
					if ( ( result as { deleted?: boolean } ).deleted ) {
						setBrands( oldBrands => oldBrands.filter( oldBrand => brand.id !== oldBrand.id ) );
					}
				} )
				.catch( e => {
					setError( e );
				} );
		}
	};

	const fetchLogoAttachment = ( brandId: number, attachmentId: number ) => {
		if ( ! attachmentId ) {
			return;
		}
		wizardApiFetch( {
			path: `/wp/v2/media/${ attachmentId }`,
			method: 'GET',
		} )
			.then( attachment => {
				const media = attachment as MediaAttachment;
				return setBrands( brandsList => {
					const brandIndex = brandsList.findIndex( _brand => brandId === _brand.id );
					return brandIndex > -1
						? brandsList.map( _brand =>
								brandId === _brand.id
									? {
											..._brand,
											meta: {
												..._brand.meta,
												_logo: { ...media, url: media.source_url },
											},
									  }
									: _brand
						  )
						: brandsList;
				} );
			} )
			.catch( setError );
	};

	useEffect( fetchBrands, [] );

	return (
		<Routes>
			<Route path="/" element={ <BrandsList { ...wizardScreenProps } brands={ brands } deleteBrand={ deleteBrand } /> } />
			<Route
				path="/brands/new"
				element={ <Brand { ...wizardScreenProps } saveBrand={ saveBrand } setError={ setError } wizardApiFetch={ wizardApiFetch } /> }
			/>
			<Route
				path="/brands/:brandId"
				element={
					<Brand
						{ ...wizardScreenProps }
						brands={ brands }
						saveBrand={ saveBrand }
						fetchLogoAttachment={ fetchLogoAttachment }
						setError={ setError }
						wizardApiFetch={ wizardApiFetch }
					/>
				}
			/>
		</Routes>
	);
};

export default withWizard( Brands );
