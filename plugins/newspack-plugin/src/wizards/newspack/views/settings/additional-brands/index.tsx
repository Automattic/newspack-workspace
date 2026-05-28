/**
 * Additional Brands page.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { useState, useEffect } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Brands from './brands';
import BrandUpsert from './brand-upsert';
import WizardsTab from '../../../../wizards-tab';
import WizardSection from '../../../../wizards-section';
import { Router, utils } from '../../../../../../packages/components/src';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';
import { TAB_PATH } from './constants';

const { Route, Switch, useHistory, useRouteMatch, useLocation } = Router;

export default function AdditionalBrands() {
	const { wizardApiFetch, isFetching, cache, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/additional-brands' );

	const brandsCache = cache( '/wp/v2/brand' );

	const [ brands, setBrands ] = useState< Brand[] >( [] );
	const history = useHistory();
	const location = useLocation();
	const { path } = useRouteMatch();

	useEffect( () => {
		resetError();
	}, [ location.pathname ] );

	/**
	 * Cache brands data.
	 */
	useEffect( () => {
		brandsCache.set( brands );
	}, [ brands ] );

	const wizardScreenProps = {
		isFetching,
		headerText: __( 'Brands', 'newspack-plugin' ),
		subHeaderText: __( 'Configure brands settings', 'newspack-plugin' ),
	};

	/**
	 * Fetching brands data.
	 */
	const fetchBrands = () => {
		wizardApiFetch< Brand[] >(
			{
				path: addQueryArgs( '/wp/v2/brand', { per_page: 100 } ),
			},
			{
				onSuccess( response ) {
					setBrands(
						response.map( ( brand: Brand ) => ( {
							...brand,
							meta: {
								...brand.meta,
								_theme_colors: brand.meta._theme_colors?.length ? brand.meta._theme_colors : [],
								_menus: brand.meta._menus?.length ? brand.meta._menus : [],
							},
						} ) )
					);
				},
			}
		);
	};

	const upsertBrand = ( brandId: number, brand: Brand ) => {
		// BrandId is NaN when inserting new brand.
		wizardApiFetch< Brand >(
			{
				path: brandId ? `/wp/v2/brand/${ brandId }` : '/wp/v2/brand',
				method: 'POST',
				data: {
					...brand,
					meta: {
						...brand.meta,
						...( brand.meta._logo && {
							_logo: brand.meta._logo instanceof Object ? brand.meta._logo.id : brand.meta._logo,
						} ),
					},
				},
			},
			{
				onSuccess( result ) {
					setBrands( ( brandsList: Brand[] ) => {
						// Is update
						if ( 0 === brandId ) {
							return [ result, ...brandsList ];
						}
						return brandsList.map( b => ( brandId === b.id ? result : b ) );
					} );
					history.push( TAB_PATH );
				},
			}
		);
	};

	const deleteBrand = ( brand: Brand ) => {
		if ( utils.confirmAction( __( 'Are you sure you want to delete this brand?', 'newspack-plugin' ) ) ) {
			wizardApiFetch< { deleted: boolean; previous: Brand } >(
				{
					path: addQueryArgs( `/wp/v2/brand/${ brand.id }`, {
						force: true,
					} ),
					method: 'DELETE',
				},
				{
					onSuccess( result ) {
						if ( result.deleted ) {
							setBrands( oldBrands => oldBrands.filter( oldBrand => brand.id !== oldBrand.id ) );
						}
					},
				}
			);
		}
	};

	const fetchLogoAttachment = ( brandId: number, attachmentId: number ) => {
		if ( ! attachmentId ) {
			return;
		}
		wizardApiFetch(
			{
				path: `/wp/v2/media/${ attachmentId }`,
			},
			{
				onSuccess( attachment ) {
					setBrands( brandsList => {
						const brandIndex = brandsList.findIndex( _brand => brandId === _brand.id );
						return brandIndex > -1
							? brandsList.map( _brand =>
									brandId === _brand.id
										? {
												..._brand,
												meta: {
													..._brand.meta,
													_logo: {
														...attachment,
														url: attachment.source_url,
													},
												},
										  }
										: _brand
							  )
							: brandsList;
					} );
				},
			}
		);
	};

	useEffect( fetchBrands, [] );

	return (
		<WizardsTab isFetching={ isFetching } title={ __( 'Additional Brands', 'newspack-plugin' ) }>
			<WizardSection>
				<Switch>
					<Route exact path={ path } render={ () => <Brands { ...wizardScreenProps } brands={ brands } deleteBrand={ deleteBrand } /> } />
					<Route
						path={ `${ path }/new` }
						render={ () => (
							<BrandUpsert
								{ ...wizardScreenProps }
								brands={ brands }
								upsertBrand={ upsertBrand }
								fetchLogoAttachment={ fetchLogoAttachment }
								wizardApiFetch={ wizardApiFetch }
							/>
						) }
					/>
					<Route
						path={ `${ path }/:brandId` }
						render={ ( { match }: { match: { params: { brandId: string } } } ) => (
							<BrandUpsert
								{ ...wizardScreenProps }
								brands={ brands }
								editBrand={ Number( match.params.brandId ) }
								upsertBrand={ upsertBrand }
								fetchLogoAttachment={ fetchLogoAttachment }
								wizardApiFetch={ wizardApiFetch }
								errorMessage={ errorMessage }
							/>
						) }
					/>
				</Switch>
			</WizardSection>
		</WizardsTab>
	);
}
