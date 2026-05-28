import React from 'react';
import { Routes, Route, useNavigate } from 'react-router-dom';
import { withWizard } from 'newspack-components';

import { addQueryArgs } from '@wordpress/url';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import BrandsList from './BrandsList';
import Brand from './Brand';

const Brands = ( { setError, wizardApiFetch } ) => {
	const [ brands, setBrands ] = useState( [] );
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
					response.map( brand => ( {
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

	const saveBrand = ( brandId, brand ) => {
		wizardApiFetch( {
			path: brandId ? `/wp/v2/brand/${ brandId }` : '/wp/v2/brand',
			method: 'POST',
			data: {
				...brand,
				meta: {
					...brand.meta,
					...( brand.meta._logo && { _logo: brand.meta._logo.id } ),
				},
			},
			quiet: true,
		} )
			.then( result =>
				setBrands( brandsList => {
					// The result from the API call doesn't contain the logo details.
					const newBrand = { id: result.id, ...brand };
					if ( brandId ) {
						const brandIndex = brandsList.findIndex( _brand => brandId === _brand.id );
						if ( brandIndex > -1 ) {
							return brandsList.map( _brand => ( brandId === _brand.id ? newBrand : _brand ) );
						}
					}

					return [ newBrand, ...brandsList ];
				} )
			)
			.then( navigate( '/' ) )
			.catch( setError );
	};

	const deleteBrand = brand => {
		// eslint-disable-next-line no-alert
		if ( confirm( __( 'Are you sure you want to delete this brand?', 'newspack' ) ) ) {
			return wizardApiFetch( {
				path: addQueryArgs( `/wp/v2/brand/${ brand.id }`, { force: true } ),
				method: 'DELETE',
				quiet: true,
			} )
				.then( result => {
					if ( result.deleted ) {
						setBrands( oldBrands => oldBrands.filter( oldBrand => brand.id !== oldBrand.id ) );
					}
				} )
				.catch( e => {
					setError( e );
				} );
		}
	};

	const fetchLogoAttachment = ( brandId, attachmentId ) => {
		if ( ! attachmentId ) {
			return;
		}
		wizardApiFetch( {
			path: `/wp/v2/media/${ attachmentId }`,
			method: 'GET',
		} )
			.then( attachment =>
				setBrands( brandsList => {
					const brandIndex = brandsList.findIndex( _brand => brandId === _brand.id );
					return brandIndex > -1
						? brandsList.map( _brand =>
								brandId === _brand.id
									? {
											..._brand,
											meta: {
												..._brand.meta,
												_logo: { ...attachment, url: attachment.source_url },
											},
									  }
									: _brand
						  )
						: brandsList;
				} )
			)
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
